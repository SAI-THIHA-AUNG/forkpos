<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\UserLogin;
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
            'pinUsers' => config('forkpos.login_user_picker')
                ? User::where('is_active', true)->whereNotNull('pos_pin')->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    public function login(Request $request, UserLogin $userLogin)
    {
        $credentials = $request->validate([
            'email' => ['nullable', 'email'],
            'password' => ['required_with:email', 'nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'digits_between:4,8'],
        ]);

        // Email/password wins when both were posted: a stale PIN in the form must
        // never hijack a normal sign-in (the login page submits one pane at a time).
        $passwordConfirmed = false;
        if (filled($credentials['email'] ?? null)) {
            $user = $userLogin->withPassword($credentials['email'], $credentials['password']);
            $passwordConfirmed = true;
        } elseif (filled($credentials['pin'] ?? null)) {
            // PIN sign-in is always for ONE chosen person (picker or email), never "whoever matches".
            $user = $this->chosenUser($credentials);
            $user = $userLogin->withPin($user, $credentials['pin']);
        } else {
            throw ValidationException::withMessages(['email' => ['Enter your email and password, or choose your name and PIN.']]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // A fresh password check lets this person set or change the PIN without typing the old one.
        $request->session()->put('pin_reset_ok', $passwordConfirmed);

        if ($passwordConfirmed && ! $user->pos_pin) {

            return redirect()->route('account.pin')->with('status', 'Choose a PIN for quick sign-in next time.');
        }

        return redirect()->intended(route('home'));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function chosenUser(array $credentials): User
    {
        $user = filled($credentials['user_id'] ?? null) ? User::find($credentials['user_id']) : null;

        if (! $user) {
            throw ValidationException::withMessages(['pin' => ['Choose your name, then enter your PIN.']]);
        }

        return $user;
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
