<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Warehouse;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** GET /dashboard — today at a glance (manager view). */
    public function index(Request $request)
    {
        $today = now()->toDateString();

        $sales = Invoice::whereDate('posting_date', $today)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->selectRaw('
                COUNT(*) as invoice_count,
                COALESCE(SUM(CASE WHEN is_return = 0 THEN grand_total ELSE 0 END), 0) as gross_sales,
                COALESCE(SUM(CASE WHEN is_return = 1 THEN grand_total ELSE 0 END), 0) as returns_total,
                COALESCE(SUM(net_total), 0) as net_total,
                COALESCE(SUM(tax_total), 0) as tax_total,
                COALESCE(SUM(grand_total), 0) as grand_total,
                COALESCE(SUM(discount_total), 0) as discount_total
            ')->first();

        $paymentBreakdown = DB::table('invoice_payments')
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->whereDate('invoices.posting_date', $today)
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->groupBy('invoice_payments.mode_of_payment')
            ->selectRaw('invoice_payments.mode_of_payment, SUM(invoice_payments.amount) as total')
            ->pluck('total', 'invoice_payments.mode_of_payment');

        $topItems = InvoiceItem::join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereDate('invoices.posting_date', $today)
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->groupBy('invoice_items.item_code', 'invoice_items.item_name')
            ->orderByDesc('sold')
            ->limit(10)
            ->selectRaw('invoice_items.item_code, invoice_items.item_name, SUM(invoice_items.qty) as sold, SUM(invoice_items.amount) as revenue')
            ->get();

        return view('pos.dashboard.index', [
            'date' => $today,
            'sales' => $sales,
            'paymentBreakdown' => $paymentBreakdown,
            'topItems' => $topItems,
        ]);
    }

    /** GET /dashboard/daily?from=YYYY-MM-DD&to=YYYY-MM-DD&tab=invoices|lines|stock */
    public function daily(Request $request, ReportService $reports)
    {
        [$from, $to] = $this->range($request);
        $isManager = $request->user()->isManager();
        $profile = $request->attributes->get('posProfile');

        $tab = $request->query('tab', 'invoices');
        if (! in_array($tab, ['invoices', 'lines', 'stock'], true) || ($tab === 'stock' && ! $isManager)) {
            $tab = 'invoices';
        }

        $data = [
            'from' => $from,
            'to' => $to,
            'tab' => $tab,
            'isManager' => $isManager,
            'summary' => $reports->salesSummary($from, $to),
            'payments' => $reports->paymentBreakdown($from, $to),
            'warehouses' => $isManager ? Warehouse::where('is_group', false)->orderBy('name')->get(['id', 'name']) : collect(),
            'warehouseId' => $this->warehouseId($request, $profile),
        ];

        if ($tab === 'invoices') {
            $data['invoices'] = $reports->invoicesQuery($from, $to)->paginate(50)->withQueryString();
        } elseif ($tab === 'lines') {
            $data['lines'] = $reports->lineQuery($from, $to)->paginate(100)->withQueryString();
        } else {
            $data['stock'] = $reports->stockMovement($from, $to, $data['warehouseId']);
        }

        return view('pos.dashboard.daily', $data);
    }

    /** GET /dashboard/daily/export — Excel workbook (manager only, route-level). */
    public function export(Request $request, ReportService $reports)
    {
        [$from, $to] = $this->range($request);
        $profile = $request->attributes->get('posProfile');

        $book = $reports->workbook(
            $from,
            $to,
            $this->warehouseId($request, $profile),
            withStock: true,
            currency: $profile?->effectiveCurrency() ?? '',
        );

        $name = 'sales-report-'.($from === $to ? $from : "{$from}_to_{$to}").'.xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $book->save($tmp);

        return response()->download($tmp, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** @return array{0:string,1:string} */
    protected function range(Request $request): array
    {
        $request->validate([
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = $request->query('from', $request->query('date', now()->toDateString()));
        $to = $request->query('to', $from);
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /** Warehouse filter: explicit ?warehouse=ID, "all", or the POS profile's own warehouse. */
    protected function warehouseId(Request $request, $profile): ?int
    {
        $w = $request->query('warehouse');
        if ($w === 'all') {
            return null;
        }
        if ($w !== null && $w !== '') {
            return (int) $w;
        }

        return $profile?->warehouse_id ? (int) $profile->warehouse_id : null;
    }
}
