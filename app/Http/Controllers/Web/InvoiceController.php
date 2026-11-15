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
        $query = Invoice::with(['customer', 'cashier', 'posProfile'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
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
        ]);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['items', 'payments', 'taxes', 'customer', 'cashier', 'posProfile', 'shift']);

        return view('pos.invoices.show', ['invoice' => $invoice]);
    }

    /** GET /invoices/{invoice}/return — return-against dialog. */
    public function returnForm(Invoice $invoice)
    {
        if ($invoice->status === Invoice::STATUS_CANCELLED) {
            return back()->with('error', 'Cannot return a cancelled invoice.');
        }
        $invoice->load(['items', 'payments', 'customer']);

        return view('pos.invoices.return', ['invoice' => $invoice]);
    }

    /** POST /invoices/{invoice}/return — submit a return; calls InvoiceService. */
    public function processReturn(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'payments' => ['nullable', 'array'],
            'payments.*.mode_of_payment' => ['required', 'string', 'max:64'],
            'payments.*.amount' => ['required', 'numeric'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = [
            'pos_profile_id' => $invoice->pos_profile_id,
            'shift_id' => $invoice->shift_id,
            'customer_id' => $invoice->customer_id,
            'is_return' => true,
            'return_against_id' => $invoice->id,
            'offline_id' => (string) Str::uuid(),
            'remarks' => $data['reason'] ?? null,
            'items' => collect($data['items'])->map(fn ($i) => [
                'item_id' => $i['item_id'],
                'qty' => $i['qty'],
            ])->all(),
            'payments' => $data['payments'] ?? [],
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

    public function cancel(Request $request, Invoice $invoice)
    {
        $user = $request->user();
        if (! $user->isManager() && $invoice->user_id !== $user->id) {
            abort(403, 'Only the cashier or a manager can cancel this invoice.');
        }
        if ($invoice->status === Invoice::STATUS_RETURNED && ! $user->isManager()) {
            abort(403, 'Only managers can cancel returned invoices.');
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->invoices->cancel($invoice, $data['reason'] ?? '');

        return back()->with('success', "Invoice {$invoice->invoice_no} cancelled. Stock restored.");
    }
}
