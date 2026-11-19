<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PinController extends Controller
{
    public function edit(Request $request)
    {
        return view('pos.account.pin', [
            'hasPin' => (bool) $request->user()->pos_pin,
            'needsCurrentPin' => $request->user()->pos_pin && ! $request->session()->get('pin_reset_ok'),
        ]);
    }

    /**
     * A person sets their own PIN. Right after a password sign-in no old PIN is needed;
     * otherwise the current PIN must be typed. PINs need not be unique: a PIN is only ever
     * checked against the user who chose their name on the login screen.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $needsCurrentPin = $user->pos_pin && ! $request->session()->get('pin_reset_ok');

        $data = $request->validate([
            'pin' => ['required', 'string', 'digits_between:4,8', 'confirmed'],
            'current_pin' => [$needsCurrentPin ? 'required' : 'nullable', 'string'],
        ]);

        if ($needsCurrentPin && ! Hash::check($data['current_pin'], $user->pos_pin)) {
            throw ValidationException::withMessages(['current_pin' => ['Current PIN is wrong.']]);
        }

        $user->update(['pos_pin' => $data['pin']]);
        $request->session()->forget('pin_reset_ok');

        return redirect()->route('home')->with('status', 'PIN saved. Next time choose your name and enter this PIN.');
    }
}
