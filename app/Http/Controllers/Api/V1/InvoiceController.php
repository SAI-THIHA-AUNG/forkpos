<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceService $invoices,
    ) {}

    /**
     * POST /api/v1/invoices — create + complete in one call (POS / offline flush).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'pos_profile_id' => ['required', 'integer', 'exists:pos_profiles,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'shift_id' => ['nullable', 'integer', 'exists:pos_opening_shifts,id'],
            'offline_id' => ['nullable', 'string', 'max:64'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
            'additional_discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'additional_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'loyalty_points' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'is_credit' => ['nullable', 'boolean'],
            'credit_days' => ['nullable', 'integer', Rule::in(Invoice::CREDIT_TERM_DAYS)],
            'due_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'posting_datetime' => ['nullable', 'date'],
            'is_return' => ['nullable', 'boolean'],
            'return_against_id' => ['required_if:is_return,true', 'nullable', 'integer', 'exists:invoices,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['required', 'numeric', 'not_in:0'],
            'items.*.uom' => ['nullable', 'string', 'max:64'],
            'items.*.rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.price_list_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_id' => ['nullable', 'integer', 'exists:taxes,id'],
            'items.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'items.*.batch_no' => ['nullable', 'string', 'max:64'],
            'items.*.serial_no' => ['nullable', 'string'],
            'items.*.note' => ['nullable', 'string', 'max:191'],
            'payments' => ['nullable', 'array'],
            'payments.*.mode_of_payment' => ['required', 'string', 'max:64'],
            'payments.*.amount' => ['required', 'numeric'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:64'],
            'payments.*.reference_date' => ['nullable', 'date'],
        ]);

        $result = $this->invoices->create($data, $request->user());

        return response()->json([
            'data' => $this->payload($result['invoice']),
            'duplicate' => $result['duplicate'],
        ], $result['duplicate'] ? 200 : 201);
    }

    /**
     * GET /api/v1/invoices — list with Frappe-style pagination.
     */
    public function index(Request $request)
    {
        $pageLength = min((int) $request->query('limit_page_length', 20), 500);
        $offset = max(0, (int) $request->query('limit_start', 0));

        $query = Invoice::with(['customer', 'cashier', 'posProfile'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('shift_id'), fn ($q, $sid) => $q->where('shift_id', $sid))
            ->when($request->query('customer_id'), fn ($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->query('date_from'), fn ($q, $d) => $q->where('posting_date', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->where('posting_date', '<=', $d))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('invoice_no', 'like', "%{$term}%")
                        ->orWhere('offline_id', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$term}%"));
                });
            })
            ->when(! $request->user()->isManager(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('id');

        $total = (clone $query)->toBase()->count();
        $invoices = $query->offset($offset)->limit($pageLength)->get();

        return response()->json([
            'data' => $invoices->map(fn ($i) => $this->payload($i, summary: true)),
            'meta' => ['total' => $total, 'limit_start' => $offset, 'limit_page_length' => $pageLength],
        ]);
    }

    /**
     * GET /api/v1/invoices/offline-check?ids=a,b,c — offline idempotency probe.
     */
    public function offlineCheck(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->filter()
            ->values()
            ->all();

        $existing = Invoice::whereIn('offline_id', $ids)->pluck('offline_id');

        return response()->json([
            'data' => [
                'requested' => $ids,
                'existing' => $existing->values(),
                'missing' => collect($ids)->diff($existing)->values(),
            ],
        ]);
    }

    /**
     * GET /api/v1/invoices/{id}
     */
    public function show(int $id)
    {
        $invoice = Invoice::with(['items', 'payments', 'taxes', 'customer', 'cashier', 'posProfile', 'shift'])
            ->findOrFail($id);

        return response()->json(['data' => $this->payload($invoice)]);
    }

    /**
     * POST /api/v1/invoices/{id}/cancel — keeps the row, restores stock, voids the payments.
     * A reason is required; the guard (owner / manager, closed shift, returns, ERPNext sync) answers 422.
     */
    public function cancel(Request $request, int $id)
    {
        $invoice = Invoice::findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $invoice = $this->invoices->cancel($invoice, $request->user(), $data['reason']);

        return response()->json(['data' => $this->payload($invoice->load('canceller'))]);
    }

    protected function payload(Invoice $invoice, bool $summary = false): array
    {
        $base = [
            'id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'status' => $invoice->status,
            'is_return' => (bool) $invoice->is_return,
            'return_against_id' => $invoice->return_against_id,
            'customer' => $invoice->customer ? [
                'id' => $invoice->customer->id,
                'customer_name' => $invoice->customer->customer_name,
                'is_walk_in' => (bool) $invoice->customer->is_walk_in,
            ] : null,
            'pos_profile' => $invoice->posProfile?->name,
            'shift_id' => $invoice->shift_id,
            'cashier' => $invoice->cashier?->name,
            'posting_datetime' => $invoice->posting_datetime?->toDateTimeString(),
            'posting_date' => $invoice->posting_date?->toDateString(),
            'currency' => $invoice->currency,
            'total_qty' => (float) $invoice->total_qty,
            'net_total' => (float) $invoice->net_total,
            'tax_total' => (float) $invoice->tax_total,
            'discount_total' => (float) $invoice->discount_total,
            'grand_total' => (float) $invoice->grand_total,
            'paid_amount' => (float) $invoice->paid_amount,
            'change_amount' => (float) $invoice->change_amount,
            'outstanding_amount' => (float) $invoice->outstanding_amount,
            'cancelled_at' => $invoice->cancelled_at?->toDateTimeString(),
            'cancelled_by' => $invoice->canceller?->name,
            'cancel_reason' => $invoice->cancel_reason,
            'is_credit' => (bool) $invoice->is_credit,
            'due_date' => $invoice->due_date?->toDateString(),
            'credit_days' => $invoice->credit_days,
            'coupon_code' => $invoice->coupon_code,
            'coupon_discount' => (float) $invoice->coupon_discount,
            'additional_discount_percentage' => (float) $invoice->additional_discount_percentage,
            'additional_discount_amount' => (float) $invoice->additional_discount_amount,
            'loyalty_points_earned' => (int) $invoice->loyalty_points_earned,
            'loyalty_points_spent' => (int) $invoice->loyalty_points_spent,
            'offline_id' => $invoice->offline_id,
            'sync_status' => $invoice->sync_status,
            'erpnext_id' => $invoice->erpnext_id,
            'remarks' => $invoice->remarks,
        ];

        if ($summary) {
            return $base;
        }

        $base['items'] = $invoice->items->map(fn ($line) => [
            'id' => $line->id,
            'item_id' => $line->item_id,
            'item_code' => $line->item_code,
            'item_name' => $line->item_name,
            'uom' => $line->uom,
            'qty' => (float) $line->qty,
            'price_list_rate' => (float) $line->price_list_rate,
            'rate' => (float) $line->rate,
            'discount_percentage' => (float) $line->discount_percentage,
            'discount_amount' => (float) $line->discount_amount,
            'amount' => (float) $line->amount,
            'tax_rate' => (float) $line->tax_rate,
            'tax_amount' => (float) $line->tax_amount,
            'tax_included' => (bool) $line->tax_included,
        ]);
        $base['payments'] = $invoice->payments->map(fn ($p) => [
            'id' => $p->id,
            'mode_of_payment' => $p->mode_of_payment,
            'amount' => (float) $p->amount,
            'reference_no' => $p->reference_no,
        ]);
        $base['taxes'] = $invoice->taxes->map(fn ($t) => [
            'id' => $t->id,
            'description' => $t->description,
            'rate' => (float) $t->rate,
            'amount' => (float) $t->amount,
            'included_in_price' => (bool) $t->included_in_price,
        ]);

        return $base;
    }
}
