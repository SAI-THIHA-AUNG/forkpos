<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ItemPrice;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\Territory;
use App\Models\Uom;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pulls masters from a real ERPNext into local SQLite and pushes local
 * invoices back up as ERPNext POS Invoices.
 *
 * Pull entities: uom, item_group, warehouse, item, item_price, customer,
 *                customer_group, territory, tax, stock
 * Push entities: invoice
 */
class SyncService
{
    public function __construct(
        protected ErpNextClient $client,
        protected StockService $stock,
    ) {}

    /**
     * @param  array  $entities  list of entity keys to sync
     * @return array{results: array<string, array{pulled: int, upserted: int}>, errors: array<int, string>}
     */
    public function pullMasters(array $entities, int $limit = 500, ?SyncContext $ctx = null): array
    {
        $results = [];
        $errors = [];

        $this->client->ping(); // fail fast with a clear message when unreachable

        // Order matters: lookups before the entities referencing them.
        $order = ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'item_price', 'customer', 'stock'];
        foreach ($order as $entity) {
            if (! in_array($entity, $entities, true)) {
                continue;
            }
            try {
                $results[$entity] = $this->{'pull'.Str::studly($entity)}($limit, $ctx);
            } catch (\Throwable $e) {
                $errors[] = "{$entity}: ".$e->getMessage();
            }
        }

        if ($results) {
            Setting::current()->forceFill(['last_synced_at' => now()])->save();
            Setting::flushCache();
        }

        return ['results' => $results, 'errors' => $errors];
    }

    /**
     * `modified > cursor` filter for delta pulls (empty on a full pull).
     *
     * @return list<array>
     */
    protected function sinceFilter(?SyncContext $ctx, string $entity): array
    {
        $since = $ctx?->since($entity);

        return $since ? [['modified', '>', $since]] : [];
    }

    /** Highest `modified` value in a page of rows — the next delta cursor. */
    protected function maxModified(array $rows): ?string
    {
        $max = null;
        foreach ($rows as $row) {
            $m = $row['modified'] ?? null;
            if ($m && ($max === null || $m > $max)) {
                $max = $m;
            }
        }

        return $max;
    }

    public function pullUom(int $limit): array
    {
        $rows = $this->client->list('UOM', ['name'], [['enabled', '=', 1]], $limit);
        $count = 0;
        foreach ($rows as $row) {
            Uom::firstOrCreate(['uom_name' => $row['name']]);
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullItemGroup(int $limit): array
    {
        $rows = $this->client->list('Item Group', ['name', 'parent_item_group', 'is_group'], [], $limit);
        $count = 0;
        foreach ($rows as $row) {
            ItemGroup::firstOrCreate(['name' => $row['name']], [
                'is_group' => (bool) ($row['is_group'] ?? false),
            ]);
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullWarehouse(int $limit, ?SyncContext $ctx = null): array
    {
        $company = $this->client->company();
        $filters = [['disabled', '=', 0]];
        if ($ctx) {
            if (! $ctx->warehouse) {
                return ['pulled' => 0, 'upserted' => 0];
            }
            $filters[] = ['name', '=', $ctx->warehouse];
        } elseif ($company) {
            $filters[] = ['company', '=', $company];
        }
        $rows = $this->client->list('Warehouse', ['name', 'is_group', 'company'], $filters, $limit);
        $count = 0;
        foreach ($rows as $row) {
            Warehouse::updateOrCreate(['erpnext_id' => $row['name']], [
                'name' => $row['name'],
                'warehouse_code' => $row['name'],
                'is_group' => (bool) ($row['is_group'] ?? false),
            ]);
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullCustomerGroup(int $limit): array
    {
        $rows = $this->client->list('Customer Group', ['name'], [], $limit);
        $count = 0;
        foreach ($rows as $row) {
            CustomerGroup::firstOrCreate(['name' => $row['name']]);
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullTerritory(int $limit): array
    {
        $rows = $this->client->list('Territory', ['name'], [], $limit);
        $count = 0;
        foreach ($rows as $row) {
            Territory::firstOrCreate(['name' => $row['name']]);
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullTax(int $limit, ?SyncContext $ctx = null): array
    {
        $company = $this->client->company();
        $filters = [['disabled', '=', 0]];
        if ($ctx) {
            if (! $ctx->taxTemplate) {
                return ['pulled' => 0, 'upserted' => 0];
            }
            $filters[] = ['name', '=', $ctx->taxTemplate];
        } elseif ($company) {
            $filters[] = ['company', '=', $company];
        }
        $rows = $this->client->list('Sales Taxes and Charges Template', ['name', 'title', 'is_default'], $filters, $ctx ? 50 : min($limit, 50));
        $count = 0;
        foreach ($rows as $row) {
            // Child rows (actual rates) need a per-doc fetch — keep the batch small.
            $doc = $this->client->doc('Sales Taxes and Charges Template', $row['name']);
            $firstRateRow = null;
            foreach ($doc['taxes'] ?? [] as $taxRow) {
                if (($taxRow['charge_type'] ?? '') === 'On Net Total') {
                    $firstRateRow = $taxRow;
                    break;
                }
            }
            Tax::updateOrCreate(['erpnext_id' => $row['name']], [
                'name' => $doc['title'] ?? $row['name'],
                'description' => $firstRateRow['description'] ?? ($firstRateRow['account_head'] ?? null),
                'rate' => (float) ($firstRateRow['rate'] ?? 0),
                'included_in_price' => (bool) ($firstRateRow['included_in_print_rate'] ?? false),
                'account_name' => $firstRateRow['account_head'] ?? null,
                'is_default' => (bool) ($doc['is_default'] ?? false),
            ]);
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullItem(int $limit, ?SyncContext $ctx = null): array
    {
        $fields = ['name', 'item_code', 'item_name', 'item_group', 'description', 'image',
            'stock_uom', 'sales_uom', 'is_stock_item', 'standard_rate', 'disabled', 'brand', 'has_variants', 'modified'];
        // Note: Item is not company-scoped in ERPNext — do not filter by company.
        $filters = array_merge([['is_sales_item', '=', 1]], $this->sinceFilter($ctx, 'item'));
        if ($ctx && $ctx->itemGroups) {
            $filters[] = ['item_group', 'in', $ctx->itemGroups];
        }
        $rows = $this->client->listAll('Item', $fields, $filters, $limit, 'modified asc');
        $count = 0;
        foreach ($rows as $row) {
            $group = $row['item_group'] ? ItemGroup::firstOrCreate(['name' => $row['item_group']]) : null;
            $stockUom = $row['stock_uom'] ? Uom::firstOrCreate(['uom_name' => $row['stock_uom']]) : null;
            $salesUom = $row['sales_uom'] ? Uom::firstOrCreate(['uom_name' => $row['sales_uom']]) : null;

            Item::withTrashed()->updateOrCreate(
                ['erpnext_id' => $row['name']],
                [
                    'item_code' => $row['item_code'] ?: $row['name'],
                    'item_name' => $row['item_name'] ?: $row['name'],
                    'item_group_id' => $group?->id,
                    'description' => $row['description'] ?? null,
                    'image_url' => $row['image'] ?? null,
                    'stock_uom_id' => $stockUom?->id,
                    'sales_uom_id' => $salesUom?->id ?? $stockUom?->id,
                    'is_stock_item' => (bool) ($row['is_stock_item'] ?? true),
                    'has_variants' => (bool) ($row['has_variants'] ?? false),
                    'standard_rate' => (float) ($row['standard_rate'] ?? 0),
                    'brand' => $row['brand'] ?? null,
                    'disabled' => (bool) ($row['disabled'] ?? false),
                ]
            )->restore();
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count, 'max_modified' => $this->maxModified($rows)];
    }

    public function pullItemPrice(int $limit, ?SyncContext $ctx = null): array
    {
        $priceList = $ctx ? (string) $ctx->priceList : $this->client->priceList();
        if ($priceList === '' && ! $ctx) {
            // Legacy full sync without an explicit list: use the profiles' price lists.
            $priceList = (string) PosProfile::whereNotNull('selling_price_list')->value('selling_price_list');
        }
        if ($priceList === '') {
            throw new RuntimeException('No price list to sync: the POS profile has no selling price list.');
        }
        $fields = ['name', 'item_code', 'price_list', 'price_list_rate', 'currency', 'valid_from', 'valid_upto', 'customer', 'uom', 'modified'];
        $filters = array_merge([['price_list', '=', $priceList]], $this->sinceFilter($ctx, 'item_price'));
        $rows = $this->client->listAll('Item Price', $fields, $filters, $limit, 'modified asc');

        $itemsByCode = Item::pluck('id', 'item_code');
        $customersByErp = Customer::whereNotNull('erpnext_id')->pluck('id', 'erpnext_id');

        $count = 0;
        foreach ($rows as $row) {
            $itemId = $itemsByCode[$row['item_code']] ?? null;
            if (! $itemId) {
                continue;
            }
            // ERPNext omits empty optional fields from get_list responses.
            $customerCode = $row['customer'] ?? null;
            $customerId = $customerCode ? ($customersByErp[$customerCode] ?? null) : null;
            ItemPrice::updateOrCreate(
                [
                    'item_id' => $itemId,
                    'price_list' => $priceList,
                    'customer_id' => $customerId,
                ],
                [
                    'currency' => $row['currency'] ?? $ctx?->currency,
                    'price_list_rate' => (float) ($row['price_list_rate'] ?? 0),
                    'valid_from' => ($row['valid_from'] ?? null) ?: null,
                    'valid_to' => ($row['valid_upto'] ?? null) ?: null,
                ]
            );
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count, 'max_modified' => $this->maxModified($rows)];
    }

    public function pullCustomer(int $limit, ?SyncContext $ctx = null): array
    {
        $fields = ['name', 'customer_name', 'customer_group', 'territory', 'email_id', 'mobile_no', 'disabled', 'modified'];
        $filters = array_merge([['disabled', '=', 0]], $this->sinceFilter($ctx, 'customer'));
        if ($ctx && $ctx->customerGroups) {
            $filters[] = ['customer_group', 'in', $ctx->customerGroups];
        }
        $rows = $this->client->listAll('Customer', $fields, $filters, $limit, 'modified asc');
        $count = 0;
        foreach ($rows as $row) {
            $group = $row['customer_group'] ? CustomerGroup::firstOrCreate(['name' => $row['customer_group']]) : null;
            $territory = $row['territory'] ? Territory::firstOrCreate(['name' => $row['territory']]) : null;

            $isWalkIn = str_contains(strtolower((string) ($row['customer_name'] ?? '')), 'walk-in');

            // Key the walk-in by its flag (not erpnext_id) so the seeded
            // walk-in gets linked to ERPNext instead of being duplicated.
            Customer::withTrashed()->updateOrCreate(
                $isWalkIn ? ['is_walk_in' => true] : ['erpnext_id' => $row['name']],
                [
                    'erpnext_id' => $row['name'],
                    'customer_code' => mb_substr($row['name'], 0, 32),
                    'customer_name' => $row['customer_name'] ?: $row['name'],
                    'customer_group_id' => $group?->id,
                    'territory_id' => $territory?->id,
                    'email' => $row['email_id'] ?? null,
                    'phone' => $row['mobile_no'] ?? null,
                    'is_walk_in' => $isWalkIn,
                ]
            )->restore();
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count, 'max_modified' => $this->maxModified($rows)];
    }

    public function pullStock(int $limit, ?SyncContext $ctx = null): array
    {
        $warehouse = null;
        if ($ctx) {
            // Profile-scoped run: sell-from warehouse of THIS profile, nothing else.
            if (! $ctx->warehouse) {
                throw new RuntimeException('The POS profile has no warehouse, so stock cannot be synced.');
            }
            $warehouse = Warehouse::where('erpnext_id', $ctx->warehouse)->where('is_group', false)->first();
            if (! $warehouse) {
                throw new RuntimeException("Warehouse '{$ctx->warehouse}' is not synced locally (or is a group warehouse).");
            }
        }

        // Legacy full sync: prefer the POS profile's warehouse (the one the register
        // actually sells from). Fall back to any non-group warehouse that's been
        // synced — but never to a group warehouse (e.g. "Finished Goods - M"),
        // which holds no real Bin rows and would sync 0 stock for everything.
        $warehouse ??= PosProfile::whereNotNull('warehouse_id')
            ->with('warehouse')
            ->get()
            ->map(fn ($p) => $p->warehouse)
            ->first(fn ($w) => $w && $w->erpnext_id && ! $w->is_group);

        if (! $warehouse) {
            $warehouse = Warehouse::whereNotNull('erpnext_id')
                ->where('is_group', false)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->first();
        }

        if (! $warehouse) {
            throw new RuntimeException('No non-group warehouse synced from ERPNext yet. Sync "warehouse" first.');
        }

        $fields = ['item_code', 'warehouse', 'actual_qty'];
        $rows = $this->client->listAll('Bin', $fields, [['warehouse', '=', $warehouse->erpnext_id]], $limit);
        $itemsByCode = Item::pluck('id', 'item_code');

        $count = 0;
        foreach ($rows as $row) {
            $item = $itemsByCode[$row['item_code']] ?? null;
            if (! $item) {
                continue;
            }
            $this->stock->setBalance(Item::find($item), $warehouse->id, (float) ($row['actual_qty'] ?? 0), ledger: false);
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count, 'warehouse' => $warehouse->erpnext_id];
    }

    /**
     * Push local invoices (sync_status = pending) to ERPNext as POS Invoices.
     *
     * @param  array<int, int>|null  $invoiceIds  null = all pending
     * @return array{pushed: int, failed: int, errors: array<int, string>}
     */
    public function pushInvoices(?array $invoiceIds = null): array
    {
        $query = Invoice::whereIn('sync_status', ['pending', 'failed'])
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->with(['items', 'payments', 'taxes', 'customer', 'posProfile']);
        if ($invoiceIds) {
            $query->whereIn('id', $invoiceIds);
        }

        $pushed = 0;
        $failed = 0;
        $errors = [];

        foreach ($query->get() as $invoice) {
            try {
                $erpName = $this->pushInvoice($invoice);
                $invoice->update(['erpnext_id' => $erpName, 'sync_status' => 'synced']);
                $pushed++;
            } catch (\Throwable $e) {
                $invoice->update(['sync_status' => 'failed']);
                $errors[] = "{$invoice->invoice_no}: ".$e->getMessage();
                $failed++;
            }
        }

        return ['pushed' => $pushed, 'failed' => $failed, 'errors' => $errors];
    }

    protected function pushInvoice(Invoice $invoice): string
    {
        $customer = $invoice->customer;
        if (! $customer->erpnext_id) {
            throw new RuntimeException("Customer '{$customer->customer_name}' has no ERPNext link. Sync customers first.");
        }

        // The invoice's own POS profile is authoritative for company, price
        // list and warehouse — NOT the (possibly stale) global settings row.
        $profile = $invoice->posProfile;
        $profileDoc = $profile?->erpnextDoc() ?? [];
        $company = $profile?->company ?: ($profileDoc['company'] ?? null) ?: $this->client->company();
        $posProfile = $profile?->erpnext_id;
        $priceList = $profile?->selling_price_list ?: $this->client->priceList();

        $warehouse = $profile?->warehouse_id ? Warehouse::find($profile->warehouse_id) : null;
        $warehouseName = $warehouse?->erpnext_id ?? ($profileDoc['warehouse'] ?? null);

        $items = [];
        foreach ($invoice->items as $line) {
            $item = Item::withTrashed()->find($line->item_id);
            if (! $item?->erpnext_id) {
                throw new RuntimeException("Item '{$line->item_code}' has no ERPNext link. Sync items first.");
            }

            // `rate` is already the final (discounted) unit rate and the line
            // keeps the original list price, so push both and let ERPNext
            // derive discount_amount. Do NOT send discount_percentage /
            // discount_amount as well — that would apply the discount twice.
            // `rate` is also kept as-is: when a tax is marked
            // included_in_print_rate ERPNext expects the tax-inclusive rate.
            $items[] = array_filter([
                'item_code' => $item->erpnext_id,
                'qty' => (float) $line->qty,
                'rate' => round((float) $line->rate, 6),
                'price_list_rate' => round((float) ($line->price_list_rate ?: $line->rate), 6),
                'uom' => $line->uom,
                'conversion_factor' => $line->conversion_factor,
                'warehouse' => $warehouseName,
            ], fn ($v) => $v !== null && $v !== '');
        }

        $payments = $invoice->payments->map(fn ($p) => [
            'mode_of_payment' => $p->mode_of_payment,
            'amount' => (float) $p->amount,
        ])->values()->all();

        $taxes = $invoice->taxes->map(fn ($t) => array_filter([
            'charge_type' => 'On Net Total',
            'account_head' => Tax::find($t->tax_id)?->account_name,
            'rate' => $t->rate,
            'included_in_print_rate' => $t->included_in_price,
            'description' => $t->description,
        ], fn ($v) => $v !== null))->values()->all();

        $payload = array_filter([
            'customer' => $customer->erpnext_id,
            'company' => $company,
            'pos_profile' => $posProfile,
            'is_pos' => 1,
            'update_stock' => 1,
            'set_posting_time' => 1,
            'posting_date' => $invoice->posting_date->toDateString(),
            'currency' => $invoice->currency ?: ($profile?->currency ?? null),
            'selling_price_list' => $priceList,
            'items' => $items,
            'payments' => $payments,
            'taxes' => $taxes,
            // Invoice-level discount: ERPNext applies it on the grand total.
            'apply_discount_on' => (float) $invoice->additional_discount_amount > 0 ? 'Grand Total' : null,
            'additional_discount_amount' => (float) $invoice->additional_discount_amount > 0
                ? round((float) $invoice->additional_discount_amount, 6) : null,
            'remarks' => 'Synced from forkpos Laravel POS ('.$invoice->invoice_no.')',
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $response = $this->client->create('Sales Invoice', $payload);

        $name = $response['data']['name'] ?? null;
        if (! $name) {
            throw new RuntimeException('ERPNext did not return a document name.');
        }

        return $name;
    }
}
