<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(protected InvoiceService $invoices) {}

    /** GET /invoices — sales history (role-scoped). */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Invoice::with(['customer', 'cashier', 'posProfile', 'creditInvoice' => fn ($q) => $q->withPending()])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type') === 'return', fn ($q) => $q->where('is_return', true))
            ->when($request->query('credit') === 'credit', fn ($q) => $q->where('is_credit', true))
            ->when($request->query('credit') === 'overdue', fn ($q) => $q->where('is_credit', true)
                ->where('outstanding_amount', '>', 0.009)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->whereDate('due_date', '<', today()))
            ->when($request->query('type') === 'sale', fn ($q) => $q->where('is_return', false))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('invoice_no', 'like', "%{$term}%")
                        ->orWhere('offline_id', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$term}%"));
                });
            })
            ->when(! $user->isManager(), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id');

        $invoices = $query->paginate(25);

        return view('pos.invoices.index', [
            'invoices' => $invoices,
            'q' => $request->query('q'),
            'status' => $request->query('status'),
            'type' => $request->query('type'),
            'credit' => $request->query('credit'),
        ]);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['items', 'payments', 'taxes', 'customer', 'cashier', 'posProfile', 'shift', 'returns', 'canceller', 'creditInvoice']);

        return view('pos.invoices.show', [
            'invoice' => $invoice,
            'cancelBlocker' => $this->invoices->cancellationBlocker($invoice, request()->user()),
            'refundDue' => $this->invoices->refundDue($invoice),
        ]);
    }

    /** GET /invoices/{invoice}/return — return-against dialog. */
    public function returnForm(Request $request, Invoice $invoice)
    {
        if ($invoice->status === Invoice::STATUS_CANCELLED || $invoice->is_return) {
            return back()->with('error', 'Only a completed sale can be returned.');
        }
        $invoice->load(['items', 'payments', 'customer']);

        $lines = collect($this->invoices->returnableLines($invoice))->values();
        if ($lines->sum('remaining') <= 0.0001) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Everything on this invoice has already been returned.');
        }

        $profile = $request->attributes->get('posProfile') ?? $invoice->posProfile;
        $modes = collect($profile?->paymentModes() ?? [])
            ->merge($invoice->payments->pluck('mode_of_payment'))
            ->unique()->values();

        return view('pos.invoices.return', [
            'invoice' => $invoice,
            'lines' => $lines,
            'refundModes' => $modes,
            'defaultMode' => $invoice->payments->first()?->mode_of_payment ?? $modes->first(),
        ]);
    }

    /** POST /invoices/{invoice}/return — submit a return; calls InvoiceService. */
    public function processReturn(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'refund_mode' => ['nullable', 'string', 'max:64'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Only lines the cashier actually put a quantity on.
        $items = collect($data['items'])
            ->filter(fn ($i) => (float) ($i['qty'] ?? 0) > 0)
            ->map(fn ($i) => ['item_id' => (int) $i['item_id'], 'qty' => (float) $i['qty']])
            ->values()->all();
        if (! $items) {
            return back()->withErrors(['items' => 'Enter a return quantity for at least one item.'])->withInput();
        }

        $payments = [];
        if (! empty($data['refund_mode']) && (float) ($data['refund_amount'] ?? 0) > 0) {
            $payments[] = ['mode_of_payment' => $data['refund_mode'], 'amount' => (float) $data['refund_amount']];
        }

        // shift_id is left out on purpose: the refund belongs to the cashier's
        // CURRENT open shift, not to the (possibly closed) shift of the sale.
        $payload = [
            'pos_profile_id' => $invoice->pos_profile_id,
            'customer_id' => $invoice->customer_id,
            'is_return' => true,
            'return_against_id' => $invoice->id,
            'offline_id' => (string) Str::uuid(),
            'remarks' => $data['reason'] ?? null,
            'items' => $items,
            'payments' => $payments,
        ];

        try {
            $result = $this->invoices->create($payload, $request->user());
            $return = $result['invoice'];

            return redirect()
                ->route('invoices.show', $return)
                ->with('success', "Return {$return->invoice_no} created against {$invoice->invoice_no}.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    /** POST /invoices/{invoice}/cancel — guarded by InvoiceService::cancellationBlocker(). */
    public function cancel(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->invoices->cancel($invoice, $request->user(), $data['reason']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "Invoice {$invoice->invoice_no} cancelled. Stock is back and the payments are voided.");
    }
}
