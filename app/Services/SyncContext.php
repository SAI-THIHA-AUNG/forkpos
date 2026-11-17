<?php

namespace App\Services;

use App\Models\PosProfile;

/**
 * What a sync run is scoped to. Built from ONE POS profile so the pull only
 * fetches that profile's warehouse, price list, item groups, customer groups
 * and tax template — never "everything in ERPNext".
 */
final class SyncContext
{
    /**
     * @param  list<string>  $itemGroups  empty = no item-group restriction
     * @param  list<string>  $customerGroups  empty = no customer-group restriction
     * @param  array<string, string>  $since  entity => ERPNext `modified` cursor (delta sync)
     */
    public function __construct(
        public readonly ?string $warehouse,
        public readonly ?string $priceList,
        public readonly ?string $currency,
        public readonly array $itemGroups = [],
        public readonly array $customerGroups = [],
        public readonly ?string $taxTemplate = null,
        protected array $since = [],
    ) {}

    public static function forProfile(PosProfile $profile): self
    {
        $profile->loadMissing('warehouse');

        return new self(
            warehouse: $profile->warehouse?->erpnext_id ?? ($profile->erpnextDoc()['warehouse'] ?? null),
            priceList: $profile->selling_price_list,
            currency: $profile->currency,
            itemGroups: $profile->itemGroups(),
            customerGroups: $profile->customerGroups(),
            taxTemplate: $profile->taxTemplate(),
        );
    }

    /** @param array<string, string> $since */
    public function withSince(array $since): self
    {
        return new self($this->warehouse, $this->priceList, $this->currency, $this->itemGroups, $this->customerGroups, $this->taxTemplate, $since);
    }

    public function since(string $entity): ?string
    {
        return $this->since[$entity] ?? null;
    }
}
