<?php

namespace App\Support;

/**
 * THE single place that turns an ERPNext POS Profile document into local
 * configuration. Nothing else in the app invents profile defaults.
 *
 * Synced fields are overwritten on every sync (ERPNext owns them).
 * Local-only fields are applied once, when the profile is first created,
 * and can then be tuned locally.
 */
class PosProfileConfig
{
    /**
     * Fields ERPNext owns. Behaviour flags come straight from the doc.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function synced(array $doc): array
    {
        $synced = [
            'name' => $doc['pos_profile_name'] ?? $doc['name'],
            'company' => $doc['company'] ?? null,
            'country' => $doc['country'] ?? null,
            'currency' => $doc['currency'] ?? null,
            'selling_price_list' => $doc['selling_price_list'] ?? null,
            'write_off_limit' => (float) ($doc['write_off_limit'] ?? 0),
            'write_off_account' => $doc['write_off_account'] ?? null,
            'cost_center' => $doc['write_off_cost_center'] ?? $doc['cost_center'] ?? null,
            // ERPNext v13+ ships `allow_rate_change` / `allow_discount_change`;
            // older builds and POS Awesome use the `allow_user_to_edit_*` names.
            'allow_rate_change' => (bool) ($doc['allow_rate_change'] ?? $doc['allow_user_to_edit_rate'] ?? false),
            'allow_discount_change' => (bool) ($doc['allow_discount_change'] ?? $doc['allow_user_to_edit_discount'] ?? false),
            // A profile with a default customer never prompts for one.
            'ask_for_customer' => empty($doc['customer']),
            'disabled' => (bool) ($doc['disabled'] ?? false),
            'erpnext_data' => $doc,
            'synced_at' => now(),
        ];

        // Only overwrite the local limit when ERPNext actually provides one.
        if (array_key_exists('max_discount_percentage', $doc)) {
            $synced['max_discount_percentage'] = (float) $doc['max_discount_percentage'];
        }

        // POS Awesome exposes a negative-stock switch; core ERPNext does not.
        if (array_key_exists('allow_negative_stock', $doc)) {
            $synced['allow_negative_stock'] = (bool) $doc['allow_negative_stock'];
        } elseif (array_key_exists('custom_allow_negative_stock', $doc)) {
            $synced['allow_negative_stock'] = (bool) $doc['custom_allow_negative_stock'];
        }

        return $synced;
    }

    /**
     * Fields ERPNext has no concept of — set once on create.
     *
     * @return array<string, mixed>
     */
    public static function localDefaults(): array
    {
        return [
            'require_shift' => true,        // POS Opening/Closing Entry model
            'allow_negative_stock' => false,
            'max_discount_percentage' => 0, // 0 = unlimited
            'tax_inclusive' => false,       // refined from the tax template after the tax sync
            'is_default' => false,
            'device_enabled' => true,
        ];
    }
}
