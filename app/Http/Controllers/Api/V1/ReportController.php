<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * GET /api/v1/reports/dashboard — today at a glance (manager view).
     */
    public function dashboard(Request $request)
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
            ->selectRaw('invoice_items.item_code, invoice_items.item_name,
                SUM(invoice_items.qty) as sold,
                SUM(invoice_items.amount) as revenue')
            ->get();

        // Hourly breakdown computed in PHP — keeps SQLite and MySQL happy.
        $hourlyMap = [];
        foreach (Invoice::whereDate('posting_date', $today)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where('is_return', false)
            ->get(['id', 'posting_datetime', 'grand_total']) as $inv) {
            $hour = $inv->posting_datetime?->format('H') ?? '00';
            $hourlyMap[$hour] ??= ['count' => 0, 'total' => 0.0];
            $hourlyMap[$hour]['count']++;
            $hourlyMap[$hour]['total'] += (float) $inv->grand_total;
        }
        ksort($hourlyMap);
        $hourly = collect($hourlyMap)->map(fn ($v, $h) => [
            'hour' => $h,
            'invoice_count' => $v['count'],
            'total' => round($v['total'], 2),
        ])->values();

        return response()->json([
            'data' => [
                'date' => $today,
                'sales' => [
                    'invoice_count' => (int) $sales->invoice_count,
                    'gross_sales' => round((float) $sales->gross_sales, 2),
                    'returns_total' => round((float) $sales->returns_total, 2),
                    'net_total' => round((float) $sales->net_total, 2),
                    'tax_total' => round((float) $sales->tax_total, 2),
                    'grand_total' => round((float) $sales->grand_total, 2),
                    'discount_total' => round((float) $sales->discount_total, 2),
                ],
                'payment_breakdown' => $paymentBreakdown->map(fn ($v, $k) => ['mode_of_payment' => $k, 'total' => round((float) $v, 2)])->values(),
                'top_items' => $topItems->map(fn ($i) => [
                    'item_code' => $i->item_code,
                    'item_name' => $i->item_name,
                    'qty_sold' => round((float) $i->sold, 2),
                    'revenue' => round((float) $i->revenue, 2),
                ]),
                'hourly' => $hourly,
            ],
        ]);
    }

    /**
     * GET /api/v1/reports/daily-summary?date=YYYY-MM-DD
     */
    public function dailySummary(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);
        $date = $data['date'] ?? now()->toDateString();

        $invoices = Invoice::whereDate('posting_date', $date)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->with(['customer:id,customer_name', 'payments'])
            ->orderBy('id')
            ->get();

        $payments = $invoices->flatMap->payments
            ->groupBy('mode_of_payment')
            ->map(fn ($rows, $mode) => [
                'mode_of_payment' => $mode,
                'total' => round((float) $rows->sum('amount'), 2),
            ])->values();

        $totals = [
            'invoice_count' => $invoices->count(),
            'returns_count' => $invoices->where('is_return', true)->count(),
            'net_total' => round((float) $invoices->sum('net_total'), 2),
            'tax_total' => round((float) $invoices->sum('tax_total'), 2),
            'discount_total' => round((float) $invoices->sum('discount_total'), 2),
            'grand_total' => round((float) $invoices->sum('grand_total'), 2),
            // From the payment rows, not the cached invoice field: an ERPNext desk
            // payment or a cancelled Payment Entry changes paid_amount, and this
            // figure must still reflect what this van took.
            'paid_amount' => round((float) $invoices->flatMap->payments->sum('amount'), 2),
        ];

        return response()->json([
            'data' => [
                'date' => $date,
                'totals' => $totals,
                'payments' => $payments,
                'invoices' => $invoices->map(fn ($i) => [
                    'invoice_no' => $i->invoice_no,
                    'posting_datetime' => $i->posting_datetime?->toDateTimeString(),
                    'customer' => $i->customer?->customer_name,
                    'status' => $i->status,
                    'is_return' => (bool) $i->is_return,
                    'grand_total' => (float) $i->grand_total,
                ]),
            ],
        ]);
    }
}
