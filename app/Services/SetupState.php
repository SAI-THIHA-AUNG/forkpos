<?php

namespace App\Services;

use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\User;

/**
 * Derives the first-run setup step from real data — nothing is stored as a
 * "wizard progress" flag that could drift out of sync.
 *
 *   password  → the signed-in user still has the bootstrap password
 *   connect   → no usable POS profile and ERPNext credentials not yet verified
 *   profiles  → credentials verified, but no POS profile enabled for this terminal
 *   null      → setup complete, normal login → profile → shift flow
 */
class SetupState
{
    public const PASSWORD = 'password';

    public const CONNECT = 'connect';

    public const PROFILES = 'profiles';

    public function nextStepFor(User $user): ?string
    {
        if ($user->must_change_password) {
            return self::PASSWORD;
        }

        if ($this->hasUsableProfile()) {
            return null;
        }

        return Setting::current()->erpnext_verified_at ? self::PROFILES : self::CONNECT;
    }

    public function hasUsableProfile(): bool
    {
        return PosProfile::where('disabled', false)->where('device_enabled', true)->exists();
    }

    /** Safe, unauthenticated summary for clients (Android) that need to know where to start. */
    public function publicStatus(): array
    {
        $setting = Setting::current();

        return [
            'connected' => (bool) $setting->erpnext_verified_at,
            'has_profile' => $this->hasUsableProfile(),
            'bootstrap_password_pending' => User::where('must_change_password', true)->exists(),
        ];
    }
}
