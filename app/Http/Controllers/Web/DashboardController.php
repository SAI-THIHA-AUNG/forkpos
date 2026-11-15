<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** GET /dashboard — today at a glance (manager view). */
    public function index(Request $request)
    {
        $today = now()->toDateString();

        $sales = Invoice::whereDate('posting_date', $today)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->selectRaw("
                COUNT(*) as invoice_count,
                COALESCE(SUM(CASE WHEN is_return = 0 THEN grand_total ELSE 0 END), 0) as gross_sales,
                COALESCE(SUM(CASE WHEN is_return = 1 THEN grand_total ELSE 0 END), 0) as returns_total,
                COALESCE(SUM(net_total), 0) as net_total,
                COALESCE(SUM(tax_total), 0) as tax_total,
                COALESCE(SUM(grand_total), 0) as grand_total,
                COALESCE(SUM(discount_total), 0) as discount_total
            ")->first();

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

    /** GET /dashboard/daily?date=YYYY-MM-DD */
    public function daily(Request $request)
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $date = $request->query('date', now()->toDateString());

        $invoices = Invoice::whereDate('posting_date', $date)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->with(['customer:id,customer_name', 'payments'])
            ->orderBy('id')
            ->paginate(50);

        return view('pos.dashboard.daily', [
            'date' => $date,
            'invoices' => $invoices,
        ]);
    }
}
