<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PosProfile;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class ProfileController extends Controller
{
    public function __construct(protected ShiftService $shifts) {}

    /**
     * Profile picker — the landing page after login.
     * Shows profiles the user may use, plus their current open shift (if any).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $profiles = PosProfile::visibleTo($user)
            ->with(['payments', 'warehouse'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $currentShift = $this->shifts->currentOpenShift($user);

        return view('pos.profiles.index', [
            'profiles' => $profiles,
            'currentShift' => $currentShift,
            'selectedProfileId' => Session::get('pos_profile_id'),
        ]);
    }

    /**
     * Store the selected profile in the session, then route the cashier to
     * either their open shift's register or the shift-open dialog.
     */
    public function select(Request $request)
    {
        $data = $request->validate([
            'pos_profile_id' => ['required', 'integer', 'exists:pos_profiles,id'],
        ]);

        $user = $request->user();
        $profile = PosProfile::visibleTo($user)->find($data['pos_profile_id']);

        if (! $profile) {
            return back()->with('error', 'You are not assigned to that POS profile.');
        }

        Session::put('pos_profile_id', $profile->id);

        // If the cashier already has an open shift on this profile, go to the register.
        $shift = $this->shifts->currentOpenShift($user);
        if ($shift && $shift->pos_profile_id === $profile->id) {
            return redirect()->route('pos.register')->with('success', "Resumed shift {$shift->shift_no}.");
        }

        // Otherwise route to shift-open if the profile requires one.
        return $profile->require_shift
            ? redirect()->route('shifts.open.form')->with('info', "Open a shift on {$profile->name} to start selling.")
            : redirect()->route('pos.register');
    }
}
