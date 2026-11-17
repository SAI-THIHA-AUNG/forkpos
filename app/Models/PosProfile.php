<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'ask_for_customer', 'require_shift', 'is_default', 'disabled', 'device_enabled',
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
            'device_enabled' => 'boolean',
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
     *   1. profile.currency (ERPNext-synced, or set locally on a non-synced profile)
     *   2. settings.currency (optional store-wide value; empty unless an admin set it)
     * There is no built-in currency literal.
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

        return (string) $setting->currency;
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
     *
     * The symbol is prepended to the amount as-is, so Myanmar kyat carries its
     * own trailing space ("K 2000", not "Ks2000").
     */
    public static function symbolFor(?string $currency): string
    {
        $currency = (string) $currency;

        return [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'CNY' => '¥',
            'MMK' => 'K ', 'THB' => '฿', 'INR' => '₹', 'SGD' => 'S$', 'MYR' => 'RM',
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

    /**
     * Profiles a user may work on, on THIS terminal.
     *
     * Mirrors ERPNext: a profile with no "Applicable for Users" rows is open to
     * everyone; otherwise only the listed users (managers always see all).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('disabled', false)
            ->where('device_enabled', true)
            ->when(! $user->isManager(), function (Builder $q) use ($user) {
                $q->where(function (Builder $w) use ($user) {
                    $w->whereDoesntHave('users')
                        ->orWhereHas('users', fn ($u) => $u->where('users.id', $user->id));
                });
            });
    }

    /** ERPNext "Item Groups" child table (empty = every item group). @return list<string> */
    public function itemGroups(): array
    {
        return $this->childValues('item_groups', 'item_group');
    }

    /** ERPNext "Customer Groups" child table (empty = every customer group). @return list<string> */
    public function customerGroups(): array
    {
        return $this->childValues('customer_groups', 'customer_group');
    }

    /** ERPNext "Taxes and Charges" template name for this profile, if any. */
    public function taxTemplate(): ?string
    {
        return $this->erpnextDoc()['taxes_and_charges'] ?? null ?: null;
    }

    /** ERPNext default customer of this profile (e.g. "Walk-in Customer"), if any. */
    public function defaultCustomer(): ?string
    {
        return $this->erpnextDoc()['customer'] ?? null ?: null;
    }

    /** ERPNext "Applicable for Users" rows as [user => is_default]. @return array<string, bool> */
    public function applicableUsers(): array
    {
        $out = [];
        foreach ($this->erpnextDoc()['applicable_for_users'] ?? [] as $row) {
            if (! empty($row['user'])) {
                $out[$row['user']] = (bool) ($row['default'] ?? false);
            }
        }

        return $out;
    }

    /** @return list<string> */
    protected function childValues(string $table, string $field): array
    {
        $values = [];
        foreach ($this->erpnextDoc()[$table] ?? [] as $row) {
            if (! empty($row[$field])) {
                $values[] = $row[$field];
            }
        }

        return array_values(array_unique($values));
    }
}
