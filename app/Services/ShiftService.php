<?php

namespace App\Services;

use App\Jobs\SyncProfileCatalog;
use App\Models\Collection;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceTax;
use App\Models\PosCashMovement;
use App\Models\PosClosingShift;
use App\Models\PosClosingShiftPayment;
use App\Models\PosClosingShiftTax;
use App\Models\PosOpeningShift;
use App\Models\PosOpeningShiftBalance;
use App\Models\PosProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS shift lifecycle: open → cash movements → close with reconciliation.
 * Laravel equivalent of POS Awesome's pos_opening_shift / pos_closing_shift.
 */
class ShiftService
{
    public function __construct() {}

    /**
     * Opening dialog data for a user: accessible profiles + their payment methods.
     */
    public function openingDialogData(User $user): array
    {
        $profiles = PosProfile::visibleTo($user)
            ->with('payments')
            ->orderBy('name')
            ->get();

        return [
            'pos_profiles' => $profiles->map(fn (PosProfile $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'company' => $p->company,
                'currency' => $p->currency,
                'payments' => $p->payments->map(fn ($pay) => [
                    'mode_of_payment' => $pay->mode_of_payment,
                    'is_default' => $pay->is_default,
                    'allow_edit_amount' => $pay->allow_edit_amount,
                ]),
            ]),
        ];
    }

    public function open(User $user, array $data): PosOpeningShift
    {
        $shift = DB::transaction(function () use ($user, $data) {
            /** @var PosProfile $profile */
            $profile = PosProfile::visibleTo($user)->findOrFail($data['pos_profile_id']);

            $open = PosOpeningShift::where('user_id', $user->id)
                ->where('status', PosOpeningShift::STATUS_OPEN)
                ->lockForUpdate()
                ->first();
            if ($open) {
                throw ValidationException::withMessages([
                    'pos_profile_id' => ["You already have an open shift ({$open->shift_no}) on profile {$open->posProfile->name}. Close it first."],
                ]);
            }

            $shift = PosOpeningShift::create([
                'shift_no' => 'TMP-'.uniqid(),
                'user_id' => $user->id,
                'pos_profile_id' => $profile->id,
                'status' => PosOpeningShift::STATUS_OPEN,
                'opening_note' => $data['opening_note'] ?? null,
                'started_at' => now(),
            ]);
            $shift->shift_no = 'SH-'.str_pad((string) $shift->id, 5, '0', STR_PAD_LEFT);
            $shift->save();

            $allowedModes = $profile->paymentModes();
            foreach ($data['balances'] ?? [] as $row) {
                $mode = $row['mode_of_payment'];
                if ($allowedModes && ! in_array($mode, $allowedModes, true)) {
                    throw ValidationException::withMessages([
                        'balances' => ["Payment mode '{$mode}' is not enabled for this POS profile."],
                    ]);
                }
                PosOpeningShiftBalance::create([
                    'opening_shift_id' => $shift->id,
                    'mode_of_payment' => $mode,
                    'opening_amount' => (float) ($row['amount'] ?? 0),
                ]);
            }

            return $shift->load(['balances', 'posProfile.payments']);
        });

        // Opening a shift is the trigger that pulls this profile's catalog.
        if ($shift->posProfile->isErpnextSynced()) {
            SyncProfileCatalog::dispatch($shift->pos_profile_id)->afterCommit();
        }

        return $shift;
    }

    public function currentOpenShift(User $user): ?PosOpeningShift
    {
        return PosOpeningShift::where('user_id', $user->id)
            ->where('status', PosOpeningShift::STATUS_OPEN)
            ->with(['posProfile.payments', 'balances'])
            ->first();
    }

    public function addCashMovement(PosOpeningShift $shift, User $user, array $data): PosCashMovement
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages(['shift_id' => ['Shift is already closed.']]);
        }

        return PosCashMovement::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'type' => $data['type'],
            'mode_of_payment' => $data['mode_of_payment']
                ?? $shift->posProfile->payments()->orderByDesc('is_default')->value('mode_of_payment'),
            'amount' => (float) $data['amount'],
            'reason' => $data['reason'] ?? null,
        ]);
    }

    /**
     * Live shift summary — what the closing dialog shows before counting cash.
     */
    public function summary(PosOpeningShift $shift): array
    {
        $modes = $shift->posProfile->paymentModes();
        $openingMap = $shift->balances->pluck('opening_amount', 'mode_of_payment')->all();

        $collected = InvoicePayment::selectRaw('mode_of_payment, SUM(amount) as total')
            ->whereIn('invoice_id', Invoice::where('shift_id', $shift->id)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->pluck('id'))
            ->groupBy('mode_of_payment')
            ->pluck('total', 'mode_of_payment')
            ->all();

        // Credit collections taken during this shift are cash in the drawer too.
        foreach (Collection::where('shift_id', $shift->id)
            ->selectRaw('mode_of_payment, SUM(amount) as total')
            ->groupBy('mode_of_payment')->pluck('total', 'mode_of_payment') as $mode => $total) {
            $collected[$mode] = (float) ($collected[$mode] ?? 0) + (float) $total;
        }

        $movements = PosCashMovement::where('shift_id', $shift->id)
            ->selectRaw("mode_of_payment,
                SUM(CASE WHEN type = 'in' THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END) as total_out")
            ->groupBy('mode_of_payment')
            ->get()
            ->keyBy('mode_of_payment');

        $paymentSummary = [];
        foreach ($modes as $mode) {
            $opening = (float) ($openingMap[$mode] ?? 0);
            $paid = (float) ($collected[$mode] ?? 0);
            $in = (float) ($movements[$mode]->total_in ?? 0);
            $out = (float) ($movements[$mode]->total_out ?? 0);
            $paymentSummary[] = [
                'mode_of_payment' => $mode,
                'opening_amount' => round($opening, 2),
                'collected_amount' => round($paid, 2),
                'cash_in_amount' => round($in, 2),
                'cash_out_amount' => round($out, 2),
                'expected_amount' => round($opening + $paid + $in - $out, 2),
            ];
        }

        $invoiceStats = Invoice::where('shift_id', $shift->id)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->selectRaw('COUNT(*) as invoice_count,
                COALESCE(SUM(net_total), 0) as net_total,
                COALESCE(SUM(tax_total), 0) as tax_total,
                COALESCE(SUM(grand_total), 0) as grand_total,
                COALESCE(SUM(total_qty), 0) as total_qty,
                COALESCE(SUM(discount_total), 0) as discount_total')
            ->first();

        return [
            'shift' => $shift->load('posProfile'),
            'payments' => $paymentSummary,
            'invoices' => [
                'count' => (int) $invoiceStats->invoice_count,
                'net_total' => round((float) $invoiceStats->net_total, 2),
                'tax_total' => round((float) $invoiceStats->tax_total, 2),
                'grand_total' => round((float) $invoiceStats->grand_total, 2),
                'total_qty' => round((float) $invoiceStats->total_qty, 2),
                'discount_total' => round((float) $invoiceStats->discount_total, 2),
            ],
            'cash_movements' => $shift->cashMovements()->latest()->get(),
        ];
    }

    /**
     * Close the shift and store the reconciliation.
     *
     * @param  array  $data  {payments: [{mode_of_payment, counted_amount}], note?}
     */
    public function close(PosOpeningShift $shift, User $user, array $data): PosClosingShift
    {
        return DB::transaction(function () use ($shift, $user, $data) {
            if (! $shift->isOpen()) {
                throw ValidationException::withMessages(['shift_id' => ['Shift is already closed.']]);
            }
            if ($shift->user_id !== $user->id && ! $user->isManager()) {
                throw ValidationException::withMessages(['shift_id' => ['Only the shift owner or a manager can close this shift.']]);
            }

            $summary = $this->summary($shift);

            $closing = PosClosingShift::create([
                'closing_no' => 'CS-'.str_pad((string) $shift->id, 5, '0', STR_PAD_LEFT),
                'opening_shift_id' => $shift->id,
                'user_id' => $shift->user_id,
                'pos_profile_id' => $shift->pos_profile_id,
                'period_start' => $shift->started_at,
                'period_end' => now(),
                'grand_total' => $summary['invoices']['grand_total'],
                'net_total' => $summary['invoices']['net_total'],
                'total_quantity' => $summary['invoices']['total_qty'],
                'note' => $data['note'] ?? null,
            ]);

            $countedMap = collect($data['payments'] ?? [])
                ->pluck('counted_amount', 'mode_of_payment')
                ->all();

            foreach ($summary['payments'] as $row) {
                $counted = (float) ($countedMap[$row['mode_of_payment']] ?? $row['expected_amount']);
                PosClosingShiftPayment::create([
                    'closing_shift_id' => $closing->id,
                    'mode_of_payment' => $row['mode_of_payment'],
                    'opening_amount' => $row['opening_amount'],
                    'collected_amount' => $row['collected_amount'],
                    'cash_in_amount' => $row['cash_in_amount'],
                    'cash_out_amount' => $row['cash_out_amount'],
                    'expected_amount' => $row['expected_amount'],
                    'counted_amount' => round($counted, 2),
                    'difference' => round($counted - $row['expected_amount'], 2),
                ]);
            }

            // Tax breakdown for the shift.
            $taxRows = InvoiceTax::selectRaw('tax_id, description, rate, SUM(amount) as amount')
                ->whereIn('invoice_id', Invoice::where('shift_id', $shift->id)
                    ->where('status', '!=', Invoice::STATUS_CANCELLED)
                    ->pluck('id'))
                ->where('amount', '!=', 0)
                ->groupBy('tax_id', 'description', 'rate')
                ->get();
            foreach ($taxRows as $row) {
                PosClosingShiftTax::create([
                    'closing_shift_id' => $closing->id,
                    'tax_id' => $row->tax_id,
                    'description' => $row->description,
                    'rate' => $row->rate,
                    'amount' => $row->amount,
                ]);
            }

            $shift->update([
                'status' => PosOpeningShift::STATUS_CLOSED,
                'closed_at' => now(),
                'closing_note' => $data['note'] ?? null,
            ]);

            return $closing->load(['payments', 'taxes', 'openingShift']);
        });
    }
}
