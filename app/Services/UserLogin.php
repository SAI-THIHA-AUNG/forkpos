<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The one place that decides whether a person may sign in (web and API share it).
 *
 *  - Users pulled from ERPNext (`erpnext_id`): the PASSWORD is checked by ERPNext, never
 *    locally (their local password is random). A success stamps `erpnext_login_at`.
 *  - A PIN is chosen per person and is only ever checked against the chosen user.
 *    For ERPNext users it works while the last ERPNext password check is recent
 *    (forkpos.erpnext_reverify_days) — offline too.
 *  - Local users (bootstrap admin, hand-made accounts) use their local password.
 */
class UserLogin
{
    public function __construct(protected ?ErpNextClient $erpNext = null) {}

    protected function erpNext(): ErpNextClient
    {
        return $this->erpNext ??= new ErpNextClient;
    }

    /** Email + password. @throws ValidationException */
    public function withPassword(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        if ($user?->erpnext_id) {
            return $this->checkWithErpNext($user, $password);
        }

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
        }

        return $this->assertActive($user);
    }

    /** Chosen user + PIN. @throws ValidationException */
    public function withPin(User $user, string $pin): User
    {
        $key = 'pin:'.$user->id;
        $max = max(1, (int) config('forkpos.pin_max_attempts', 5));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['pin' => ["Too many wrong PINs for this user. Try again in {$minutes} min, or sign in with email & password."]]);
        }

        if (! $user->is_active || ! $user->pos_pin || ! Hash::check($pin, $user->pos_pin)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['pin' => ['Invalid PIN.']]);
        }

        $this->assertActive($user, 'pin');
        RateLimiter::clear($key);

        if ($this->needsErpNextPassword($user)) {
            throw ValidationException::withMessages(['pin' => [sprintf(
                'Your ERPNext password must be confirmed every %d days. Sign in once with email & password.',
                (int) config('forkpos.erpnext_reverify_days', 30),
            )]]);
        }

        return $user;
    }

    /** ERPNext users must re-confirm their ERPNext password when the last check is too old. */
    public function needsErpNextPassword(User $user): bool
    {
        if (! $user->erpnext_id) {
            return false;
        }
        $days = (int) config('forkpos.erpnext_reverify_days', 30);

        return $days > 0 && (! $user->erpnext_login_at || $user->erpnext_login_at->lt(now()->subDays($days)));
    }

    protected function checkWithErpNext(User $user, string $password): User
    {
        try {
            $accepted = $this->erpNext()->verifyUserPassword($user->erpnext_id, $password);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'email' => [$e->getMessage().' You can still sign in with your PIN until your 30-day ERPNext check runs out.'],
            ]);
        }

        if (! $accepted) {
            throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
        }

        $this->assertActive($user);
        $user->forceFill(['erpnext_login_at' => now()])->save();

        return $user;
    }

    protected function assertActive(User $user, string $field = 'email'): User
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages([$field => ['This account is disabled.']]);
        }

        return $user;
    }
}
