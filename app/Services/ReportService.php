<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Support\XlsxWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting shared by the web screens and the Excel export, so what
 * is on screen is exactly what lands in the workbook.
 */
class ReportService
{
    /** Invoices (sales and returns) in a date range, cancelled ones excluded. */
    protected function invoiceQuery(string $from, string $to)
    {
        return Invoice::query()
            ->whereDate('posting_date', '>=', $from)
            ->whereDate('posting_date', '<=', $to)
            ->where('status', '!=', Invoice::STATUS_CANCELLED);
    }

    /** @return array<string, float|int> */
    public function salesSummary(string $from, string $to): array
    {
        $row = $this->invoiceQuery($from, $to)->selectRaw('
            COALESCE(SUM(CASE WHEN is_return = 0 THEN 1 ELSE 0 END), 0) as sales_count,
            COALESCE(SUM(CASE WHEN is_return = 1 THEN 1 ELSE 0 END), 0) as returns_count,
            COALESCE(SUM(CASE WHEN is_return = 0 THEN grand_total ELSE 0 END), 0) as sales_total,
            COALESCE(SUM(CASE WHEN is_return = 1 THEN grand_total ELSE 0 END), 0) as returns_total,
            COALESCE(SUM(discount_total), 0) as discount_total,
            COALESCE(SUM(additional_discount_amount), 0) as additional_discount,
            COALESCE(SUM(coupon_discount), 0) as coupon_discount,
            COALESCE(SUM(net_total), 0) as net_total,
            COALESCE(SUM(tax_total), 0) as tax_total,
            COALESCE(SUM(grand_total), 0) as grand_total
        ')->first();

        return [
            'sales_count' => (int) $row->sales_count,
            'returns_count' => (int) $row->returns_count,
            'sales_total' => (float) $row->sales_total,
            'returns_total' => (float) $row->returns_total,          // negative
            'line_discounts' => (float) $row->discount_total - (float) $row->additional_discount,
            'total_discounts' => (float) $row->additional_discount,
            'coupon_discounts' => (float) $row->coupon_discount,
            'net_total' => (float) $row->net_total,
            'tax_total' => (float) $row->tax_total,
            'grand_total' => (float) $row->grand_total,               // sales − returns
        ];
    }

    /** @return Collection<string, float> mode_of_payment => net amount */
    public function paymentBreakdown(string $from, string $to): Collection
    {
        return DB::table('invoice_payments')
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->whereDate('invoices.posting_date', '>=', $from)
            ->whereDate('invoices.posting_date', '<=', $to)
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->groupBy('invoice_payments.mode_of_payment')
            ->orderBy('invoice_payments.mode_of_payment')
            ->selectRaw('invoice_payments.mode_of_payment as mode, SUM(invoice_payments.amount) as total')
            ->pluck('total', 'mode')
            ->map(fn ($v) => (float) $v);
    }

    public function invoicesQuery(string $from, string $to)
    {
        return $this->invoiceQuery($from, $to)
            ->with(['customer:id,customer_name', 'cashier:id,name', 'payments'])
            ->orderBy('posting_datetime')->orderBy('id');
    }

    /** Line-level sales detail: one row per invoice line. */
    public function lineQuery(string $from, string $to)
    {
        return InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereDate('invoices.posting_date', '>=', $from)
            ->whereDate('invoices.posting_date', '<=', $to)
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->orderBy('invoices.posting_datetime')->orderBy('invoices.id')->orderBy('invoice_items.id')
            ->select([
                'invoices.id as invoice_id', 'invoices.invoice_no', 'invoices.posting_datetime', 'invoices.is_return',
                'customers.customer_name',
                'invoice_items.item_code', 'invoice_items.item_name', 'invoice_items.uom', 'invoice_items.qty',
                'invoice_items.price_list_rate', 'invoice_items.rate', 'invoice_items.discount_amount',
                'invoice_items.tax_amount', 'invoice_items.amount',
            ]);
    }

    /**
     * Stock in / out / balance per item and warehouse for a date range.
     *
     * Opening = balance before the first day, Closing = balance at the end of the
     * last day, both rebuilt from the stock ledger (`qty_after`). Items with no
     * movement in the range still show their (unchanged) balance.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function stockMovement(string $from, string $to, ?int $warehouseId = null): array
    {
        $names = DB::table('items')->pluck('item_name', 'id');
        $codes = DB::table('items')->pluck('item_code', 'id');
        $warehouses = DB::table('warehouses')->pluck('name', 'id');

        $inRange = StockLedgerEntry::query()
            ->whereDate('posting_date', '>=', $from)
            ->whereDate('posting_date', '<=', $to)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($inRange->groupBy(fn ($e) => $e->item_id.'|'.$e->warehouse_id) as $key => $entries) {
            $first = $entries->first();
            $last = $entries->last();
            $row = $this->blankStockRow($first->item_id, $first->warehouse_id);
            $row['opening'] = round($first->qty_after - $first->qty_change, 6);
            $row['closing'] = round($last->qty_after, 6);
            foreach ($entries as $e) {
                $qty = (float) $e->qty_change;
                if ($qty >= 0) {
                    $row['in'] += $qty;
                    $e->ref_type === 'return' ? $row['returned_in'] += $qty : $row['other_in'] += $qty;
                } else {
                    $row['out'] += -$qty;
                    $e->ref_type === 'invoice' ? $row['sold_out'] += -$qty : $row['other_out'] += -$qty;
                }
            }
            $rows[$key] = $row;
        }

        // Items that did not move: their balance is whatever it was at the end
        // of the range — rebuilt from the first LATER ledger entry, or the
        // live balance when nothing happened afterwards.
        $later = DB::table('stock_ledger_entries')
            ->whereDate('posting_date', '>', $to)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('item_id', 'warehouse_id')
            ->selectRaw('item_id, warehouse_id, MIN(id) as first_id')
            ->get();
        $laterEntries = $later->isEmpty() ? collect()
            : StockLedgerEntry::whereIn('id', $later->pluck('first_id'))->get()
                ->keyBy(fn ($e) => $e->item_id.'|'.$e->warehouse_id);

        $balances = StockBalance::query()
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get();
        foreach ($balances as $balance) {
            $key = $balance->item_id.'|'.$balance->warehouse_id;
            if (isset($rows[$key])) {
                continue;
            }
            $atEnd = isset($laterEntries[$key])
                ? $laterEntries[$key]->qty_after - $laterEntries[$key]->qty_change
                : $balance->qty;
            if (abs($atEnd) < 0.0000001) {
                continue;
            }
            $row = $this->blankStockRow($balance->item_id, $balance->warehouse_id);
            $row['opening'] = $row['closing'] = round($atEnd, 6);
            $rows[$key] = $row;
        }

        $rows = collect($rows)->map(function ($row) use ($names, $codes, $warehouses) {
            $row['item_name'] = $names[$row['item_id']] ?? ('#'.$row['item_id']);
            $row['item_code'] = $codes[$row['item_id']] ?? '';
            $row['warehouse'] = $warehouses[$row['warehouse_id']] ?? '';
            foreach (['opening', 'in', 'out', 'closing', 'sold_out', 'returned_in', 'other_in', 'other_out'] as $f) {
                $row[$f] = round($row[$f], 6);
            }

            return $row;
        })->sortBy([['item_name', 'asc'], ['warehouse', 'asc']])->values()->all();

        $totals = ['opening' => 0.0, 'in' => 0.0, 'out' => 0.0, 'closing' => 0.0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $r[$k];
            }
        }

        return ['rows' => $rows, 'totals' => array_map(fn ($v) => round($v, 6), $totals)];
    }

    protected function blankStockRow(int $itemId, int $warehouseId): array
    {
        return [
            'item_id' => $itemId, 'warehouse_id' => $warehouseId,
            'opening' => 0.0, 'in' => 0.0, 'out' => 0.0, 'closing' => 0.0,
            'sold_out' => 0.0, 'returned_in' => 0.0, 'other_in' => 0.0, 'other_out' => 0.0,
        ];
    }

    /** Build the multi-sheet Excel workbook for a date range. */
    public function workbook(string $from, string $to, ?int $warehouseId, bool $withStock, string $currency = ''): XlsxWriter
    {
        $label = $from === $to ? $from : "{$from} to {$to}";
        $summary = $this->salesSummary($from, $to);
        $payments = $this->paymentBreakdown($from, $to);

        $summaryRows = [
            ['Sales invoices', $summary['sales_count']],
            ['Return invoices', $summary['returns_count']],
            ['Sales total', $summary['sales_total']],
            ['Returns total', $summary['returns_total']],
            ['Line discounts', $summary['line_discounts']],
            ['Total (invoice-level) discounts', $summary['total_discounts']],
            ['Coupon discounts', $summary['coupon_discounts']],
            ['Net total (before tax)', $summary['net_total']],
            ['Tax', $summary['tax_total']],
            ['Net sales (sales − returns)', $summary['grand_total']],
            ['', null],
            ['Payments received (net of refunds)', null],
        ];
        foreach ($payments as $mode => $total) {
            $summaryRows[] = ['   '.$mode, $total];
        }

        $book = new XlsxWriter;
        $book->addSheet('Summary', [['Metric', 'text', 36], ['Value', 'money', 18]], array_map(
            fn ($r) => [$r[0], $r[1]], $summaryRows
        ), "Sales summary — {$label}".($currency ? " ({$currency})" : ''));

        $invoiceRows = $this->invoicesQuery($from, $to)->get()->map(fn (Invoice $i) => [
            $i->invoice_no,
            $i->posting_datetime?->format('Y-m-d H:i'),
            $i->is_return ? 'Return' : 'Sale',
            $i->customer?->customer_name ?? 'Walk-in',
            $i->cashier?->name ?? '',
            $i->status,
            round($i->net_total, 2),
            round($i->discount_total, 2),
            round($i->coupon_discount, 2),
            round($i->tax_total, 2),
            round($i->grand_total, 2),
            round($i->payments->sum('amount'), 2),
            $i->payments->pluck('mode_of_payment')->unique()->implode(', '),
        ])->all();
        $book->addSheet('Invoices', [
            ['Invoice', 'text', 18], ['Date', 'date'], ['Type', 'text', 9], ['Customer', 'text', 24], ['Cashier', 'text', 18],
            ['Status', 'text', 12], ['Net total', 'money'], ['Discounts', 'money'], ['Coupon', 'money'],
            ['Tax', 'money'], ['Grand total', 'money'], ['Paid', 'money'], ['Payment modes', 'text', 22],
        ], $invoiceRows);

        $lineRows = [];
        foreach ($this->lineQuery($from, $to)->cursor() as $l) {
            $lineRows[] = [
                $l->invoice_no,
                $l->posting_datetime ? date('Y-m-d H:i', strtotime((string) $l->posting_datetime)) : '',
                $l->is_return ? 'Return' : 'Sale',
                $l->customer_name ?? 'Walk-in',
                $l->item_code, $l->item_name, $l->uom,
                (float) $l->qty, (float) $l->price_list_rate, (float) $l->rate,
                (float) $l->discount_amount, (float) $l->tax_amount, (float) $l->amount,
            ];
        }
        $book->addSheet('Line Items', [
            ['Invoice', 'text', 18], ['Date', 'date'], ['Type', 'text', 9], ['Customer', 'text', 24],
            ['Item code', 'text', 16], ['Item', 'text', 34], ['UOM', 'text', 9], ['Qty', 'qty'],
            ['List price', 'money'], ['Rate paid', 'money'], ['Line discount', 'money'], ['Tax', 'money'], ['Amount', 'money'],
        ], $lineRows);

        if ($withStock) {
            $stock = $this->stockMovement($from, $to, $warehouseId);
            $stockRows = array_map(fn ($r) => [
                $r['item_code'], $r['item_name'], $r['warehouse'],
                $r['opening'], $r['in'], $r['out'], $r['closing'],
                $r['sold_out'], $r['returned_in'], $r['other_in'], $r['other_out'],
            ], $stock['rows']);
            $stockRows[] = ['', 'TOTAL', '', $stock['totals']['opening'], $stock['totals']['in'], $stock['totals']['out'], $stock['totals']['closing'], null, null, null, null];
            $book->addSheet('Inventory Movement', [
                ['Item code', 'text', 16], ['Item', 'text', 34], ['Warehouse', 'text', 22],
                ['Opening', 'qty'], ['Stock in', 'qty'], ['Stock out', 'qty'], ['Closing', 'qty'],
                ['Sold', 'qty'], ['Returned in', 'qty'], ['Other in', 'qty'], ['Other out', 'qty'],
            ], $stockRows, "Inventory movement — {$label}");
        }

        return $book;
    }
}
