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
use Illuminate\Support\Facades\DB;
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
    public function pullMasters(array $entities, int $limit = 500): array
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
                $results[$entity] = $this->{'pull'.\Illuminate\Support\Str::studly($entity)}($limit);
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

    public function pullWarehouse(int $limit): array
    {
        $company = $this->client->company();
        $filters = [['disabled', '=', 0]];
        if ($company) {
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

    public function pullTax(int $limit): array
    {
        $company = $this->client->company();
        $filters = [['disabled', '=', 0]];
        if ($company) {
            $filters[] = ['company', '=', $company];
        }
        $rows = $this->client->list('Sales Taxes and Charges Template', ['name', 'title', 'is_default'], $filters, min($limit, 50));
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

    public function pullItem(int $limit): array
    {
        $company = $this->client->company();
        $fields = ['name', 'item_code', 'item_name', 'item_group', 'description', 'image',
            'stock_uom', 'sales_uom', 'is_stock_item', 'standard_rate', 'disabled', 'brand', 'has_variants'];
        // Note: Item is not company-scoped in ERPNext — do not filter by company.
        $filters = [['is_sales_item', '=', 1]];
        $rows = $this->client->list('Item', $fields, $filters, $limit);
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

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullItemPrice(int $limit): array
    {
        $priceList = $this->client->priceList();
        $fields = ['name', 'item_code', 'price_list', 'price_list_rate', 'currency', 'valid_from', 'valid_upto', 'customer', 'uom'];
        $rows = $this->client->list('Item Price', $fields, [['price_list', '=', $priceList]], $limit);

        $itemsByCode = Item::pluck('id', 'item_code');
        $customersByErp = Customer::whereNotNull('erpnext_id')->pluck('id', 'erpnext_id');

        $count = 0;
        foreach ($rows as $row) {
            $itemId = $itemsByCode[$row['item_code']] ?? null;
            if (! $itemId) {
                continue;
            }
            $customerId = $row['customer'] ? ($customersByErp[$row['customer']] ?? null) : null;
            ItemPrice::updateOrCreate(
                [
                    'item_id' => $itemId,
                    'price_list' => $priceList,
                    'customer_id' => $customerId,
                ],
                [
                    'currency' => $row['currency'] ?? 'USD',
                    'price_list_rate' => (float) ($row['price_list_rate'] ?? 0),
                    'valid_from' => $row['valid_from'] ?: null,
                    'valid_to' => $row['valid_upto'] ?: null,
                ]
            );
            $count++;
        }

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullCustomer(int $limit): array
    {
        $fields = ['name', 'customer_name', 'customer_group', 'territory', 'email_id', 'mobile_no', 'disabled'];
        $rows = $this->client->list('Customer', $fields, [['disabled', '=', 0]], $limit);
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

        return ['pulled' => count($rows), 'upserted' => $count];
    }

    public function pullStock(int $limit): array
    {
        // Prefer the POS profile's warehouse (the one the register actually sells
        // from). Fall back to any non-group warehouse that's been synced — but
        // never to a group warehouse (e.g. "Finished Goods - M"), which holds
        // no real Bin rows and would sync 0 stock for everything.
        $warehouse = PosProfile::whereNotNull('warehouse_id')
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
        $rows = $this->client->list('Bin', $fields, [['warehouse', '=', $warehouse->erpnext_id]], $limit);
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
            ->with(['items', 'payments', 'taxes', 'customer']);
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

        $warehouse = Warehouse::find($invoice->posProfile->warehouse_id);
        $items = [];
        foreach ($invoice->items as $line) {
            $item = Item::withTrashed()->find($line->item_id);
            if (! $item?->erpnext_id) {
                throw new RuntimeException("Item '{$line->item_code}' has no ERPNext link. Sync items first.");
            }
            $items[] = array_filter([
                'item_code' => $item->erpnext_id,
                'qty' => $line->qty,
                'rate' => $line->tax_included ? round($line->rate / (1 + $line->tax_rate / 100), 6) : $line->rate,
                'uom' => $line->uom,
                'conversion_factor' => $line->conversion_factor,
                'discount_percentage' => $line->discount_percentage,
                'warehouse' => $warehouse?->erpnext_id,
            ], fn ($v) => $v !== null);
        }

        $payments = $invoice->payments->map(fn ($p) => [
            'mode_of_payment' => $p->mode_of_payment,
            'amount' => $p->amount,
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
            'company' => $this->client->company(),
            'is_pos' => 1,
            'update_stock' => 1,
            'set_posting_time' => 1,
            'posting_date' => $invoice->posting_date->toDateString(),
            'currency' => $invoice->currency,
            'items' => $items,
            'payments' => $payments,
            'taxes' => $taxes,
            'pos_total_paid' => $invoice->paid_amount,
            'remarks' => 'Synced from forkpos Laravel POS ('.$invoice->invoice_no.')',
        ]);

        $response = $this->client->create('POS Invoice', $payload);

        $name = $response['data']['name'] ?? null;
        if (! $name) {
            throw new RuntimeException('ERPNext did not return a document name.');
        }

        return $name;
    }
}
