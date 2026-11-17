<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'company_name', 'currency', 'currency_symbol', 'currency_precision',
        'tax_inclusive_prices', 'allow_negative_stock', 'invoice_prefix',
        'next_invoice_number', 'receipt_footer', 'receipt_header',
        'erpnext_url', 'erpnext_api_key', 'erpnext_api_secret',
        'erpnext_company', 'erpnext_price_list', 'erpnext_verified_at', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tax_inclusive_prices' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'next_invoice_number' => 'integer',
            'erpnext_api_secret' => 'encrypted',
            'last_synced_at' => 'datetime',
            'erpnext_verified_at' => 'datetime',
        ];
    }

    /**
     * Singleton accessor — the app keeps exactly one settings row (id = 1).
     *
     * The cache stores a plain attribute array (not the Eloquent model):
     * Laravel 13's database cache unserializes with allowed_classes = false
     * by default, which would turn a cached model into __PHP_Incomplete_Class.
     */
    public static function current(): self
    {
        $attributes = Cache::remember('pos.settings', 300, function () {
            return static::query()->firstOrCreate(['id' => 1])->getAttributes();
        });

        $model = new self;
        $model->setRawAttributes(is_array($attributes) ? $attributes : [], true);
        $model->exists = true;
        $model->syncOriginal();

        return $model;
    }

    public static function flushCache(): void
    {
        Cache::forget('pos.settings');
    }

    public function erpnextEnabled(): bool
    {
        return (bool) ($this->erpnext_url && $this->erpnext_api_key && $this->erpnext_api_secret);
    }
}
