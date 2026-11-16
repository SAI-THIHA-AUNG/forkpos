<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosProfile extends Model
{
    // Existing fillable fields (kept) + the 5 ERPNext-sync fields this round adds.
    // IMPORTANT: when erpnext_id is set, currency / warehouse_id / selling_price_list
    // are owned by ERPNext — local edits MUST be rejected by the controller layer
    // (see Api\V1\PosProfileController::update + Web\SettingController::syncPosProfile).
    protected $fillable = [
        'name', 'company', 'country', 'currency', 'warehouse_id', 'selling_price_list',
        'allow_rate_change', 'allow_discount_change', 'allow_negative_stock',
        'tax_inclusive', 'write_off_limit', 'write_off_account', 'cost_center',
        'max_discount_percentage',
        'ask_for_customer', 'require_shift', 'is_default', 'disabled',
        'erpnext_id', 'erpnext_data', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'allow_rate_change' => 'boolean',
            'allow_discount_change' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'tax_inclusive' => 'boolean',
            'write_off_limit' => 'float',
            'max_discount_percentage' => 'float',
            'ask_for_customer' => 'boolean',
            'require_shift' => 'boolean',
            'is_default' => 'boolean',
            'disabled' => 'boolean',
            'erpnext_data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosProfilePayment::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pos_profile_users', 'pos_profile_id', 'user_id')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function paymentModes(): array
    {
        return $this->payments()->orderByDesc('is_default')->orderBy('mode_of_payment')->pluck('mode_of_payment')->all();
    }

    /**
     * True when this profile was pulled from ERPNext. Used by the controller
     * layer to reject local edits of currency/warehouse/payment_methods, and
     * by the register to decide whether to read currency from the profile
     * (ERPNext-sourced, e.g. MMK) or fall back to settings.currency (USD).
     */
    public function isErpnextSynced(): bool
    {
        return ! empty($this->erpnext_id) && $this->synced_at !== null;
    }

    /**
     * Effective currency ISO code for this profile.
     *
     * Priority:
     *   1. profile.currency (when ERPNext-synced — this is how "Test Van POS" reports MMK)
     *   2. profile.currency (when locally set on a non-synced profile)
     *   3. settings.currency (the store-wide default — usually USD on fresh installs)
     *
     * The optional $setting arg lets callers reuse an already-loaded Setting
     * instance and skip a fresh DB/cache lookup.
     */
    public function effectiveCurrency(?Setting $setting = null): string
    {
        if (! empty($this->currency)) {
            return $this->currency;
        }

        $setting ??= Setting::current();

        return $setting->currency ?: 'USD';
    }

    /**
     * Currency symbol for the register's money rendering. Falls back to the
     * ISO code itself when we have no symbol mapping (still unambiguous).
     */
    public function effectiveCurrencySymbol(?Setting $setting = null): string
    {
        $code = $this->effectiveCurrency($setting);

        return self::symbolFor($code);
    }

    /**
     * Currency precision used at checkout. Pulled from settings (it is a
     * store-wide display preference, not a per-profile attribute in ERPNext).
     */
    public function effectivePrecision(?Setting $setting = null): int
    {
        $setting ??= Setting::current();

        return (int) ($setting->currency_precision ?? 2);
    }

    /**
     * Map an ISO 4217 currency code to a display symbol. Locale-independent
     * (no PHP intl dependency). Falls back to the raw ISO code.
     */
    public static function symbolFor(string $currency): string
    {
        return [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'CNY' => '¥',
            'MMK' => 'Ks', 'THB' => '฿', 'INR' => '₹', 'SGD' => 'S$', 'MYR' => 'RM',
            'PHP' => '₱', 'VND' => '₫', 'IDR' => 'Rp', 'AUD' => 'A$', 'CAD' => 'C$',
            'CHF' => 'CHF', 'AED' => 'AED', 'SAR' => 'SAR', 'KRW' => '₩', 'ZAR' => 'R',
        ][strtoupper($currency)] ?? strtoupper($currency);
    }

    /**
     * Convenience accessor for the raw ERPNext doc, used by the settings page
     * "show me what ERPNext returned" inspector. Returns [] when not synced.
     *
     * @return array<string, mixed>
     */
    public function erpnextDoc(): array
    {
        return is_array($this->erpnext_data) ? $this->erpnext_data : [];
    }
}
