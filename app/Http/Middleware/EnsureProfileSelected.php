<?php

namespace App\Http\Middleware;

use App\Models\PosProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require a POS profile to be selected in the session before the cashier
 * can enter the register, shift, or any sales screen.
 *
 * The selected profile id is stored as session('pos_profile_id'). We
 * re-validate against the DB on every request so a disabled profile or a
 * revoked user-attachment kicks the cashier back to the picker.
 */
class EnsureProfileSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $profileId = Session::get('pos_profile_id');
        $user = $request->user();

        if (! $profileId || ! $user) {
            return redirect()->route('home')->with('warning', 'Please select a POS profile first.');
        }

        $profile = PosProfile::visibleTo($user)->find($profileId);
        if (! $profile) {
            Session::forget('pos_profile_id');

            return redirect()->route('home')->with('warning', 'Your selected POS profile is no longer available to you.');
        }

        // Expose the resolved profile to controllers/views for the current request.
        $request->attributes->set('posProfile', $profile);

        return $next($request);
    }
}
