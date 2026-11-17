<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Show the login form. Supports email/password OR PIN (POS keypad).
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('home');
        }

        // First-run hint: if no users exist, instruct the operator to seed.
        $needsBootstrap = User::count() === 0;

        return view('pos.auth.login', [
            'needsBootstrap' => $needsBootstrap,
            'bootstrapPending' => User::where('must_change_password', true)->exists(),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required_without:pin', 'email'],
            'password' => ['required_with:email', 'string'],
            'pin' => ['required_without:email', 'string', 'digits_between:4,8'],
        ]);

        $user = null;

        // Email/password wins when both were posted: a stale PIN in the form must
        // never hijack a normal sign-in (the login page submits one pane at a time).
        $usingPin = ! filled($credentials['email'] ?? null) && isset($credentials['pin']);

        if ($usingPin) {
            // PIN login — POS terminals / quick keypad sign-in.
            // Same logic as the API AuthController: scan active users, Hash::check.
            $matches = User::where('is_active', true)->whereNotNull('pos_pin')->get()
                ->filter(fn (User $u) => Hash::check($credentials['pin'], $u->pos_pin));
            // Exactly one user must match: an ambiguous PIN must never pick "the first" account.
            if ($matches->count() !== 1) {
                throw ValidationException::withMessages(['pin' => ['Invalid PIN.']]);
            }
            $user = $matches->first();
        } else {
            $user = User::where('email', $credentials['email'])->first();
            if (! $user || ! Hash::check($credentials['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
            }
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['This account is disabled.']]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
