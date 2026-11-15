<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PosOpeningShift;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    public function __construct(protected ShiftService $shifts) {}

    /** GET /shifts — history (paginated, scoped to role). */
    public function index(Request $request)
    {
        $user = $request->user();
        $shifts = PosOpeningShift::with(['posProfile', 'user', 'closingShift.payments'])
            ->when(! $user->isManager(), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(20);

        return view('pos.shifts.index', ['shifts' => $shifts]);
    }

    /** GET /shifts/open — opening dialog (profile + payments + opening balances). */
    public function openForm(Request $request)
    {
        $data = $this->shifts->openingDialogData($request->user());
        $current = $this->shifts->currentOpenShift($request->user());

        return view('pos.shifts.open', [
            'profiles' => $data['pos_profiles'],
            'current' => $current,
            'selectedProfileId' => session('pos_profile_id'),
        ]);
    }

    /** POST /shifts/open */
    public function open(Request $request)
    {
        $data = $request->validate([
            'pos_profile_id' => ['required', 'exists:pos_profiles,id'],
            'balances' => ['nullable', 'array'],
            'balances.*.mode_of_payment' => ['required_with:balances', 'string', 'max:64'],
            'balances.*.amount' => ['nullable', 'numeric', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $shift = $this->shifts->open($request->user(), $data);
            session()->put('pos_profile_id', $shift->pos_profile_id);

            return redirect()
                ->route('pos.register')
                ->with('success', "Shift {$shift->shift_no} opened on {$shift->posProfile->name}.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    /** GET /shifts/current — the caller's open shift, or redirect to open form. */
    public function current(Request $request)
    {
        $shift = $this->shifts->currentOpenShift($request->user());
        if (! $shift) {
            return redirect()->route('shifts.open.form')->with('info', 'You have no open shift.');
        }

        return redirect()->route('shifts.summary', $shift);
    }

    /** GET /shifts/{shift} */
    public function show(Request $request, PosOpeningShift $shift)
    {
        $this->authorizeShift($request->user(), $shift);
        $shift->load(['posProfile.payments', 'balances', 'cashMovements.user', 'closingShift.payments', 'closingShift.taxes', 'user']);

        return view('pos.shifts.show', ['shift' => $shift]);
    }

    /** GET /shifts/{shift}/summary — live totals for the closing dialog. */
    public function summary(Request $request, PosOpeningShift $shift)
    {
        $this->authorizeShift($request->user(), $shift);
        $summary = $this->shifts->summary($shift);

        return view('pos.shifts.summary', [
            'shift' => $shift->load('posProfile'),
            'payments' => $summary['payments'],
            'invoices' => $summary['invoices'],
            'cashMovements' => $summary['cash_movements'],
        ]);
    }

    /** POST /shifts/{shift}/cash-movement */
    public function cashMovement(Request $request, PosOpeningShift $shift)
    {
        $this->authorizeShift($request->user(), $shift);

        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out'])],
            'mode_of_payment' => ['nullable', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $this->shifts->addCashMovement($shift, $request->user(), $data);

            return back()->with('success', ucfirst($data['type']).' movement recorded.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    /** POST /shifts/{shift}/close */
    public function close(Request $request, PosOpeningShift $shift)
    {
        $this->authorizeShift($request->user(), $shift);

        $data = $request->validate([
            'payments' => ['nullable', 'array'],
            'payments.*.mode_of_payment' => ['required_with:payments', 'string', 'max:64'],
            'payments.*.counted_amount' => ['required_with:payments', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $closing = $this->shifts->close($shift, $request->user(), $data);

            return redirect()
                ->route('shifts.show', $shift)
                ->with('success', "Shift closed as {$closing->closing_no}.");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    private function authorizeShift($user, PosOpeningShift $shift): void
    {
        if ($shift->user_id !== $user->id && ! $user->isManager()) {
            abort(403, 'You can only view your own shifts.');
        }
    }
}
