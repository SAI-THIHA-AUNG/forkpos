<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use App\Models\PosOpeningShift;
use App\Models\Warehouse;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    public function __construct(protected ShiftService $shifts) {}

    /**
     * GET /api/v1/shifts — history (paginated).
     */
    public function index(Request $request)
    {
        $pageLength = min((int) $request->query('limit_page_length', 20), 200);
        $offset = max(0, (int) $request->query('limit_start', 0));

        $query = PosOpeningShift::with(['posProfile', 'user', 'closingShift.payments'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
            ->when(! $request->user()->isManager(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('id');

        $total = (clone $query)->toBase()->count();
        $shifts = $query->offset($offset)->limit($pageLength)->get();

        return response()->json([
            'data' => $shifts->map(fn ($s) => [
                'id' => $s->id,
                'shift_no' => $s->shift_no,
                'status' => $s->status,
                'pos_profile' => $s->posProfile?->name,
                'user' => $s->user?->name,
                'started_at' => $s->started_at?->toDateTimeString(),
                'closed_at' => $s->closed_at?->toDateTimeString(),
                'closing' => $s->closingShift ? [
                    'closing_no' => $s->closingShift->closing_no,
                    'grand_total' => (float) $s->closingShift->grand_total,
                    'payments' => $s->closingShift->payments->map(fn ($p) => [
                        'mode_of_payment' => $p->mode_of_payment,
                        'expected_amount' => (float) $p->expected_amount,
                        'counted_amount' => (float) $p->counted_amount,
                        'difference' => (float) $p->difference,
                    ]),
                ] : null,
            ]),
            'meta' => ['total' => $total, 'limit_start' => $offset, 'limit_page_length' => $pageLength],
        ]);
    }

    /**
     * GET /api/v1/shifts/opening-data — the opening dialog (profiles + payments).
     */
    public function openingData(Request $request)
    {
        return response()->json([
            'data' => $this->shifts->openingDialogData($request->user()),
        ]);
    }

    /**
     * POST /api/v1/shifts/open
     * Body: {pos_profile_id, balances: [{mode_of_payment, amount}], opening_note?}
     */
    public function open(Request $request)
    {
        // Simple validation - just need a POS profile
        $data = $request->validate([
            'pos_profile_id' => ['required', 'exists:pos_profiles,id'],
            'balances' => ['nullable', 'array'],
            'balances.*.mode_of_payment' => ['required_with:balances', 'string', 'max:64'],
            'balances.*.amount' => ['nullable', 'numeric', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $shift = $this->shifts->open($request->user(), $data);

        return response()->json(['data' => $this->shiftPayload($shift)], 201);
    }

    /**
     * GET /api/v1/shifts/current — the caller's open shift (or null).
     */
    public function current(Request $request)
    {
        $shift = $this->shifts->currentOpenShift($request->user());

        return response()->json([
            'data' => $shift ? $this->shiftPayload($shift) : null,
        ]);
    }

    /**
     * GET /api/v1/shifts/pos-profile-data/{id} — get all data needed for a POS profile
     */
    public function posProfileData(int $id)
    {
        try {
            $posProfile = PosProfile::findOrFail($id);

            // Get all items
            $items = Item::get(['id', 'item_code', 'item_name', 'selling_price']);

            // Get all customers
            $customers = Customer::get(['id', 'customer_name', 'customer_group']);

            // Get all warehouses
            $warehouses = Warehouse::where('is_group', false)->get(['id', 'name', 'erpnext_id']);

            // Get all payment methods
            $paymentMethods = $posProfile->payments->map(fn ($p) => [
                'mode_of_payment' => $p->mode_of_payment,
                'is_default' => $p->is_default,
                'allow_edit_amount' => $p->allow_edit_amount,
            ]);

            return response()->json([
                'data' => [
                    'pos_profile' => [
                        'id' => $posProfile->id,
                        'name' => $posProfile->name,
                        'company' => $posProfile->company,
                        'currency' => $posProfile->currency,
                        'warehouse_id' => $posProfile->warehouse_id,
                    ],
                    'items' => $items,
                    'customers' => $customers,
                    'warehouses' => $warehouses,
                    'payment_methods' => $paymentMethods,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'data' => [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/v1/shifts/{id}
     */
    public function show(int $id)
    {
        $shift = PosOpeningShift::with(['posProfile.payments', 'balances', 'cashMovements', 'closingShift.payments'])
            ->findOrFail($id);

        return response()->json(['data' => $this->shiftPayload($shift)]);
    }

    /**
     * POST /api/v1/shifts/{id}/cash-movement
     * Body: {type: in|out, amount, mode_of_payment?, reason?}
     */
    public function cashMovement(Request $request, int $id)
    {
        $shift = PosOpeningShift::findOrFail($id);
        if ($shift->user_id !== $request->user()->id && ! $request->user()->isManager()) {
            abort(403, 'Only the shift owner or a manager can move cash on this shift.');
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out'])],
            'mode_of_payment' => ['nullable', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $movement = $this->shifts->addCashMovement($shift, $request->user(), $data);

        return response()->json([
            'data' => [
                'id' => $movement->id,
                'type' => $movement->type,
                'mode_of_payment' => $movement->mode_of_payment,
                'amount' => (float) $movement->amount,
                'reason' => $movement->reason,
                'created_at' => $movement->created_at?->toDateTimeString(),
            ],
        ], 201);
    }

    /**
     * GET /api/v1/shifts/{id}/summary — live totals for the closing dialog.
     */
    public function summary(int $id)
    {
        $shift = PosOpeningShift::with('posProfile')->findOrFail($id);
        $summary = $this->shifts->summary($shift);

        return response()->json([
            'data' => [
                'shift' => $this->shiftPayload($summary['shift']),
                'payments' => $summary['payments'],
                'invoices' => $summary['invoices'],
                'cash_movements' => collect($summary['cash_movements'])->map(fn ($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'mode_of_payment' => $m->mode_of_payment,
                    'amount' => (float) $m->amount,
                    'reason' => $m->reason,
                    'created_at' => $m->created_at?->toDateTimeString(),
                ]),
            ],
        ]);
    }

    /**
     * POST /api/v1/shifts/{id}/close
     * Body: {payments: [{mode_of_payment, counted_amount}], note?}
     */
    public function close(Request $request, int $id)
    {
        $shift = PosOpeningShift::findOrFail($id);

        $data = $request->validate([
            'payments' => ['nullable', 'array'],
            'payments.*.mode_of_payment' => ['required_with:payments', 'string', 'max:64'],
            'payments.*.counted_amount' => ['required_with:payments', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $closing = $this->shifts->close($shift, $request->user(), $data);

        return response()->json([
            'data' => [
                'id' => $closing->id,
                'closing_no' => $closing->closing_no,
                'period_start' => $closing->period_start?->toDateTimeString(),
                'period_end' => $closing->period_end?->toDateTimeString(),
                'grand_total' => (float) $closing->grand_total,
                'net_total' => (float) $closing->net_total,
                'total_quantity' => (float) $closing->total_quantity,
                'note' => $closing->note,
                'payments' => $closing->payments->map(fn ($p) => [
                    'mode_of_payment' => $p->mode_of_payment,
                    'opening_amount' => (float) $p->opening_amount,
                    'collected_amount' => (float) $p->collected_amount,
                    'cash_in_amount' => (float) $p->cash_in_amount,
                    'cash_out_amount' => (float) $p->cash_out_amount,
                    'expected_amount' => (float) $p->expected_amount,
                    'counted_amount' => (float) $p->counted_amount,
                    'difference' => (float) $p->difference,
                ]),
                'taxes' => $closing->taxes->map(fn ($t) => [
                    'description' => $t->description,
                    'rate' => (float) $t->rate,
                    'amount' => (float) $t->amount,
                ]),
            ],
        ], 201);
    }

    protected function shiftPayload(PosOpeningShift $shift): array
    {
        return [
            'id' => $shift->id,
            'shift_no' => $shift->shift_no,
            'status' => $shift->status,
            'pos_profile_id' => $shift->pos_profile_id,
            'pos_profile' => $shift->posProfile?->name,
            'user' => $shift->user?->name,
            'started_at' => $shift->started_at?->toDateTimeString(),
            'closed_at' => $shift->closed_at?->toDateTimeString(),
            'opening_note' => $shift->opening_note,
            'balances' => $shift->balances->map(fn ($b) => [
                'mode_of_payment' => $b->mode_of_payment,
                'opening_amount' => (float) $b->opening_amount,
            ]),
        ];
    }
}
