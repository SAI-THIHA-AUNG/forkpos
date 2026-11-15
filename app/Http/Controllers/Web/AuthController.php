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

        return view('pos.auth.login', ['needsBootstrap' => $needsBootstrap]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required_without:pin', 'email'],
            'password' => ['required_with:email', 'string'],
            'pin' => ['required_without:email', 'string', 'digits_between:4,8'],
        ]);

        $user = null;

        if (isset($credentials['pin'])) {
            // PIN login — POS terminals / quick keypad sign-in.
            // Same logic as the API AuthController: scan active users, Hash::check.
            $candidates = User::where('is_active', true)->get();
            $user = $candidates->first(fn (User $u) => $u->pos_pin && Hash::check($credentials['pin'], $u->pos_pin));
            if (! $user) {
                throw ValidationException::withMessages(['pin' => ['Invalid PIN.']]);
            }
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
