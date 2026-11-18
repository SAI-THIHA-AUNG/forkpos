<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\CreditInvoice;
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
use App\Support\DocNaming;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pulls masters from a real ERPNext into local SQLite and pushes local
 * invoices back up as ERPNext POS Invoices.
 *
 * Pull entities: uom, item_group, warehouse, item, item_price, customer,
 *                customer_group, territory, tax, stock, naming_series,
 *                credit_invoice (unpaid Sales Invoices of every van)
 * Push entities: invoice, collection (as Payment Entries)
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
        $order = ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'item_price', 'customer', 'naming_series', 'credit_invoice', 'stock'];
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

    /**
     * Sales Invoice naming series known to ERPNext. Stored so the app can build
     * local numbers (and van series) that follow ERPNext's own rule.
     */
    public function pullNamingSeries(int $limit = 500, ?SyncContext $ctx = null): array
    {
        $res = $this->client->call('frappe.desk.form.load.getdoctype', ['doctype' => 'Sales Invoice']);
        $docs = $res['docs'] ?? ($res['message']['docs'] ?? []);

        $options = [];
        $default = null;
        foreach ($docs as $doc) {
            if (($doc['name'] ?? null) !== 'Sales Invoice') {
                continue;
            }
            foreach ($doc['fields'] ?? [] as $field) {
                if (($field['fieldname'] ?? null) === 'naming_series') {
                    $options = array_values(array_filter(array_map('trim', explode("\n", (string) ($field['options'] ?? '')))));
                    $default = $field['default'] ?? null;
                }
            }
        }
        if (! $options) {
            return ['pulled' => 0, 'upserted' => 0, 'max_modified' => null];
        }

        $setting = Setting::current();
        // Prefer the series PosProfileSync already mirrored, so there is one source of truth.
        $chosen = $setting->invoice_series ?: $setting->erpnext_naming_series;
        if (! $chosen || ! in_array($chosen, $options, true)) {
            $chosen = ($default && in_array($default, $options, true)) ? $default : $options[0];
        }
        $setting->forceFill(['erpnext_naming_series' => $chosen, 'erpnext_series_options' => $options])->save();
        Setting::flushCache();

        return ['pulled' => count($options), 'upserted' => count($options), 'max_modified' => null];
    }

    /**
     * Unpaid Sales Invoices from ERPNext — made by ANY van, not just this one —
     * so a salesman can collect on an invoice another van created.
     *
     * Always a full pull of the (small) unpaid list instead of a delta: an invoice
     * that was paid elsewhere simply drops out of the list, and that is the signal
     * to close it here. Item lines are fetched only for new or changed invoices.
     */
    public function pullCreditInvoice(int $limit = 500, ?SyncContext $ctx = null): array
    {
        $fields = ['name', 'customer', 'customer_name', 'posting_date', 'due_date', 'currency', 'grand_total',
            'outstanding_amount', 'pos_profile', 'modified'];
        $filters = [['docstatus', '=', 1], ['is_return', '=', 0], ['outstanding_amount', '>', 0]];
        if ($company = $this->client->company()) {
            $filters[] = ['company', '=', $company];
        }
        $rows = $this->client->listAll('Sales Invoice', $fields, $filters, $limit, 'posting_date asc');
        $names = array_column($rows, 'name');

        $vanByProfile = PosProfile::whereNotNull('erpnext_id')->whereNotNull('van_code')->pluck('van_code', 'erpnext_id')->all();
        $vanCodes = array_values(array_filter(PosProfile::pluck('van_code')->all()));
        $existing = CreditInvoice::whereIn('erpnext_id', $names)->get()->keyBy('erpnext_id');
        $localIds = Invoice::whereIn('erpnext_id', $names)->pluck('id', 'erpnext_id');

        $count = 0;
        foreach ($rows as $row) {
            $name = $row['name'];
            $credit = $existing->get($name);
            if (! $credit && isset($localIds[$name])) {
                // Our own invoice, pushed earlier: reuse its row instead of adding a twin.
                $credit = CreditInvoice::where('invoice_id', $localIds[$name])->first();
            }
            $credit ??= new CreditInvoice(['origin' => 'erpnext']);

            // Debtors of other vans may not be in this van's customer list yet: add them (never edit existing ones).
            $customer = Customer::withTrashed()->where('erpnext_id', $row['customer'])->first()
                ?? Customer::create([
                    'erpnext_id' => $row['customer'],
                    'customer_code' => mb_substr($row['customer'], 0, 32),
                    'customer_name' => $row['customer_name'] ?: $row['customer'],
                ]);
            if ($customer->trashed()) {
                $customer->restore();
            }

            $items = $credit->items;
            if (! $credit->exists || $credit->erp_modified !== ($row['modified'] ?? null) || ! $items) {
                try {
                    $items = collect($this->client->doc('Sales Invoice', $name)['items'] ?? [])->map(fn ($l) => [
                        'item_code' => $l['item_code'] ?? null,
                        'item_name' => $l['item_name'] ?? ($l['item_code'] ?? null),
                        'qty' => (float) ($l['qty'] ?? 0),
                        'uom' => $l['uom'] ?? null,
                        'rate' => (float) ($l['rate'] ?? 0),
                        'amount' => (float) ($l['amount'] ?? 0),
                    ])->values()->all();
                } catch (\Throwable) {
                    // keep whatever lines we already have; the balance is what matters
                }
            }

            $outstanding = round((float) $row['outstanding_amount'], 6);
            $grand = round((float) $row['grand_total'], 6);
            $pending = $credit->exists ? $credit->pending_collected : 0.0;

            $credit->fill([
                'erpnext_id' => $name,
                'customer_id' => $customer->id,
                'invoice_no' => $name,
                'van_code' => $vanByProfile[$row['pos_profile'] ?? ''] ?? $this->vanFromName($name, $vanCodes) ?? $credit->van_code,
                'posting_date' => $row['posting_date'],
                'due_date' => $row['due_date'] ?? null,
                'currency' => $row['currency'] ?? null,
                'grand_total' => $grand,
                'paid_amount' => round($grand - $outstanding, 6),
                'erp_outstanding' => $outstanding,
                // Our own not-yet-pushed collections already cover it → stays settled locally.
                'status' => ($outstanding - $pending) > 0.009 ? CreditInvoice::STATUS_OPEN : CreditInvoice::STATUS_SETTLED,
                'items' => $items,
                'erp_modified' => $row['modified'] ?? null,
                'synced_at' => now(),
            ]);
            if (! $credit->invoice_id && isset($localIds[$name])) {
                $credit->invoice_id = $localIds[$name];
            }
            $credit->save();

            // ERPNext is authoritative: a payment taken at the desk or by another
            // van must show on our own invoice row too, not only in the credit list.
            if ($credit->invoice_id) {
                $left = max(0.0, round($outstanding - $pending, 6));
                Invoice::whereKey($credit->invoice_id)
                    // Cancelled and returned sales keep their own status; ERPNext's balance must not turn them back into paid/unpaid.
                    ->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_RETURNED])
                    ->update([
                        'outstanding_amount' => $left,
                        'paid_amount' => round($grand - $left, 6),
                        'status' => $left <= 0.009
                            ? Invoice::STATUS_PAID
                            : ($left >= $grand ? Invoice::STATUS_UNPAID : Invoice::STATUS_PARTLY_PAID),
                    ]);
            }
            $count++;
        }

        // Anything we still think is open that ERPNext no longer lists was paid (or
        // cancelled) elsewhere — e.g. another van collected it.
        $listed = array_flip($names);
        foreach (CreditInvoice::open()->whereNotNull('erpnext_id')->get() as $gone) {
            if (isset($listed[$gone->erpnext_id])) {
                continue;
            }
            $gone->update(['status' => CreditInvoice::STATUS_SETTLED, 'erp_outstanding' => 0, 'paid_amount' => $gone->grand_total, 'synced_at' => now()]);
            if ($gone->invoice_id) {
                Invoice::whereKey($gone->invoice_id)
                    // Same rule as the mirror: a returned or cancelled sale keeps its status.
                    ->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_RETURNED])
                    ->update(['outstanding_amount' => 0, 'paid_amount' => $gone->grand_total, 'status' => Invoice::STATUS_PAID]);
            }
        }

        return ['pulled' => count($rows), 'upserted' => $count, 'max_modified' => null];
    }

    /** ACC-SINV-TVP-2026-00001 → TVP, when one of our known van ids sits between dashes. */
    protected function vanFromName(string $name, array $vanCodes): ?string
    {
        foreach ($vanCodes as $code) {
            if (str_contains($name, '-'.$code.'-')) {
                return $code;
            }
        }

        return null;
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
                CreditInvoice::where('invoice_id', $invoice->id)->update(['erpnext_id' => $erpName]);
                $pushed++;
            } catch (\Throwable $e) {
                $invoice->update(['sync_status' => 'failed']);
                $errors[] = "{$invoice->invoice_no}: ".$e->getMessage();
                $failed++;
            }
        }

        return ['pushed' => $pushed, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Submit Sales Invoices that an older ForkPOS pushed but left as drafts
     * (before the push learned to submit). Only documents whose remarks say
     * ForkPOS created them are touched; anything else in Draft is ignored.
     *
     * @return array{drafts: array<int, array{name: string, total: float, remarks: ?string}>, submitted: int, failed: array<int, string>}
     */
    public function submitForkposDrafts(bool $apply = false): array
    {
        // Oldest first: with update-stock invoices an early sale may only be
        // submittable after a later receipt, so posting-date order is the fair one.
        $rows = $this->client->listAll('Sales Invoice', ['name', 'grand_total', 'remarks', 'posting_date'], [
            ['docstatus', '=', 0],
            ['remarks', 'like', 'Synced from forkpos Laravel POS%'],
        ], 100, 'posting_date asc, name asc');

        $drafts = array_map(fn (array $row) => [
            'name' => (string) $row['name'],
            'total' => (float) ($row['grand_total'] ?? 0),
            'posting_date' => $row['posting_date'] ?? null,
            'remarks' => $row['remarks'] ?? null,
        ], $rows);

        $submitted = 0;
        $failed = [];

        if ($apply) {
            foreach ($drafts as $draft) {
                try {
                    $this->submitDoc('Sales Invoice', $this->client->doc('Sales Invoice', $draft['name']));
                    $submitted++;
                } catch (\Throwable $e) {
                    $failed[] = $draft['name'].': '.$e->getMessage();
                }
            }
        }

        return ['drafts' => $drafts, 'submitted' => $submitted, 'failed' => $failed];
    }

    protected function pushInvoice(Invoice $invoice): string
    {
        $customer = $invoice->customer;
        if (! $customer->erpnext_id) {
            throw new RuntimeException("Customer '{$customer->customer_name}' has no ERPNext link. Sync customers first.");
        }

        // An earlier attempt created the document but failed to submit it (e.g. ERPNext
        // refused for stock). Finish THAT document instead of creating a second one.
        if ($invoice->erpnext_id) {
            try {
                $existing = $this->client->doc('Sales Invoice', $invoice->erpnext_id);
            } catch (\Throwable) {
                $existing = [];
            }
            $docstatus = $existing ? (int) ($existing['docstatus'] ?? 0) : null;
            if ($docstatus === 1) {
                return $invoice->erpnext_id;
            }
            if ($docstatus === 0) {
                $this->submitDoc('Sales Invoice', $existing);

                return $invoice->erpnext_id;
            }
            // Gone or cancelled in ERPNext: fall through and push a fresh document.
            $invoice->forceFill(['erpnext_id' => null])->save();
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

        // A credit sale with nothing paid up front is a plain Sales Invoice with a
        // due date; anything paid at the till goes as a POS invoice with payments.
        $credit = CreditInvoice::where('invoice_id', $invoice->id)->first();
        $onCredit = (float) $invoice->outstanding_amount > 0.009;
        $series = $profile ? $this->vanSeriesFor($profile) : null;

        $payload = array_filter([
            'naming_series' => $series,
            'customer' => $customer->erpnext_id,
            'company' => $company,
            'pos_profile' => $posProfile,
            'is_pos' => $payments ? 1 : 0,
            'due_date' => $onCredit ? ($invoice->due_date ?? $credit?->due_date ?? $invoice->posting_date->copy()->addDays((int) config('forkpos.credit_days', 30)))->toDateString() : null,
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
        $payload['is_pos'] = $payments ? 1 : 0; // 0 must survive the filter above

        $response = $this->client->create('Sales Invoice', $payload);

        $data = $response['data'] ?? [];
        $name = $data['name'] ?? null;
        if (! $name) {
            throw new RuntimeException('ERPNext did not return a document name.');
        }

        // Remember the draft right away: if the submit below fails, the retry must find it.
        $invoice->forceFill(['erpnext_id' => $name])->save();

        // A REST create always leaves the document in Draft (Frappe ignores
        // `docstatus` in the payload). A draft invoice has no collectible
        // balance, so submit it — otherwise credit collection and the ERPNext
        // outstanding reports see nothing to pay.
        $this->submitDoc('Sales Invoice', $data);

        return $name;
    }

    /**
     * Submit a just-created document.
     *
     * Only a stale payload ("modified after you have opened it") is worth a
     * second attempt: Frappe rejects it when the server touched the document
     * during save, and submitting a freshly loaded copy fixes that. Every other
     * failure (stock, validation) would fail again identically, so it is raised
     * as-is with a single request. The docstatus is re-checked before retrying,
     * so a request whose response was lost is not submitted twice.
     *
     * @param  array<string, mixed>  $doc  the created document (needs `name`)
     */
    protected function submitDoc(string $doctype, array $doc): void
    {
        if ((int) ($doc['docstatus'] ?? 0) === 1) {
            return;
        }

        $name = $doc['name'] ?? null;

        try {
            $this->client->call('frappe.client.submit', ['doc' => $doc], true);
        } catch (\Throwable $e) {
            if (! $name || ! str_contains($e->getMessage(), 'has been modified after you have opened it')) {
                throw $e;
            }
            $fresh = $this->client->doc($doctype, $name);
            if ((int) ($fresh['docstatus'] ?? 0) !== 1) {
                $this->client->call('frappe.client.submit', ['doc' => $fresh], true);
            }
        }
    }

    /**
     * ERPNext series for this van ("ACC-SINV-TVP-.YYYY.-"), or null to let ERPNext
     * use its default series. The numbers ERPNext hands out then carry the van id
     * too, so two vans can never end up with the same Sales Invoice name.
     */
    protected function vanSeriesFor(PosProfile $profile): ?string
    {
        $setting = Setting::current();
        $base = (string) ($setting->invoice_series ?: $setting->erpnext_naming_series);
        if ($base === '') {
            return null;
        }
        $series = DocNaming::vanSeries($base, $profile->ensureVanCode());
        $options = (array) ($setting->erpnext_series_options ?? []);
        if (in_array($series, $options, true)) {
            return $series;
        }

        return config('forkpos.register_van_series', true) && $this->registerSeries($series, $options) ? $series : null;
    }

    /** Add a series to Sales Invoice's naming_series options (Property Setter). Best effort. */
    protected function registerSeries(string $series, array $options): bool
    {
        try {
            $merged = array_values(array_unique(array_merge($options, [$series])));
            $value = implode("\n", $merged);
            $found = $this->client->list('Property Setter', ['name'], [
                ['doc_type', '=', 'Sales Invoice'], ['field_name', '=', 'naming_series'], ['property', '=', 'options'],
            ], 1);
            if ($found) {
                $this->client->update('Property Setter', $found[0]['name'], ['value' => $value]);
            } else {
                $this->client->create('Property Setter', [
                    'doctype_or_field' => 'DocField', 'doc_type' => 'Sales Invoice', 'field_name' => 'naming_series',
                    'property' => 'options', 'property_type' => 'Text', 'value' => $value,
                ]);
            }
            Setting::current()->forceFill(['erpnext_series_options' => $merged])->save();
            Setting::flushCache();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Push collections as submitted Payment Entries against the Sales Invoice.
     * ERPNext is the referee: if another van already collected, it rejects the
     * entry and the collection is marked failed (with the reason) for review.
     *
     * @param  array<int, int>|null  $ids  null = every pending/failed collection
     * @return array{pushed: int, failed: int, errors: array<int, string>}
     */
    public function pushCollections(?array $ids = null): array
    {
        $query = Collection::whereIn('sync_status', [Collection::PENDING, Collection::FAILED])->with('creditInvoice')->orderBy('id');
        if ($ids) {
            $query->whereIn('id', $ids);
        }

        $pushed = 0;
        $failed = 0;
        $errors = [];
        $credit = app(CreditService::class);
        $accounts = [];

        foreach ($query->get() as $collection) {
            $invoice = $collection->creditInvoice;
            if (! $invoice?->erpnext_id) {
                // The invoice itself has not reached ERPNext yet: wait, not an error.
                $errors[] = "{$collection->collection_no}: waiting for invoice {$invoice?->invoice_no} to sync first.";

                continue;
            }

            try {
                $entry = $this->findPaymentEntry($collection) ?? $this->createPaymentEntry($collection, $invoice, $accounts);
                $credit->markPushed($collection, $entry);
                $pushed++;
            } catch (\Throwable $e) {
                $collection->update(['sync_status' => Collection::FAILED, 'sync_error' => mb_substr($e->getMessage(), 0, 500)]);
                $errors[] = "{$collection->collection_no}: ".$e->getMessage();
                $failed++;
            }
        }

        return ['pushed' => $pushed, 'failed' => $failed, 'errors' => $errors];
    }

    /** A retry after a lost response must not pay twice: look for our reference first. */
    protected function findPaymentEntry(Collection $collection): ?string
    {
        $rows = $this->client->list('Payment Entry', ['name', 'docstatus'], [
            ['reference_no', '=', $collection->collection_no], ['docstatus', '!=', 2],
        ], 1);
        if (! $rows) {
            return null;
        }

        // Found but still a draft (the submit failed last time): finish it, don't call it pushed.
        if ((int) ($rows[0]['docstatus'] ?? 0) === 0) {
            $this->submitDoc('Payment Entry', $this->client->doc('Payment Entry', $rows[0]['name']));
        }

        return $rows[0]['name'];
    }

    protected function createPaymentEntry(Collection $collection, CreditInvoice $invoice, array &$accounts): string
    {
        $res = $this->client->call('erpnext.accounts.doctype.payment_entry.payment_entry.get_payment_entry', [
            'dt' => 'Sales Invoice',
            'dn' => $invoice->erpnext_id,
            'party_amount' => (float) $collection->amount,
        ]);
        $pe = $res['message'] ?? null;
        if (! is_array($pe) || ! $pe) {
            throw new RuntimeException('ERPNext could not build a Payment Entry for '.$invoice->erpnext_id.'.');
        }

        // When the invoice has no balance left (another van collected it first),
        // ERPNext still returns a template — but one that moves money the other
        // way (`payment_type = Pay`). Say so plainly instead of submitting a
        // nonsensical entry that fails on account types.
        if (($pe['payment_type'] ?? 'Receive') !== 'Receive') {
            throw new RuntimeException("ERPNext reports no balance left on {$invoice->erpnext_id} — it was probably collected by another van. Refresh the invoice list.");
        }

        $amount = round((float) $collection->amount, 6);
        $left = $pe['references'][0]['outstanding_amount'] ?? null;
        if ($left !== null && $amount > round((float) $left, 6) + 0.009) {
            throw new RuntimeException("ERPNext shows only {$left} left on {$invoice->erpnext_id}, less than the {$amount} collected — another van probably collected part of it. Refresh the invoice list.");
        }
        $date = $collection->posting_date->toDateString();
        $pe['posting_date'] = $date;
        $pe['mode_of_payment'] = $collection->mode_of_payment;
        $pe['reference_no'] = $collection->collection_no;
        $pe['reference_date'] = $date;
        $pe['paid_amount'] = $amount;
        $pe['received_amount'] = $amount;
        $pe['remarks'] = trim('Collected by forkpos '.$collection->collection_no.($collection->van_code ? ' (van '.$collection->van_code.')' : '').' '.$collection->remarks);
        if (! empty($pe['references'][0])) {
            $pe['references'][0]['allocated_amount'] = $amount;
        }

        // Pay into the account of the chosen mode of payment (cash vs bank, ...).
        $key = ($pe['company'] ?? '').'|'.$collection->mode_of_payment;
        if (! array_key_exists($key, $accounts)) {
            $accounts[$key] = $this->modeAccount($collection->mode_of_payment, $pe['company'] ?? null);
        }
        if ($accounts[$key]) {
            $pe['paid_to'] = $accounts[$key]['account'];
            $pe['paid_to_account_currency'] = $accounts[$key]['currency'];
        }
        $pe['docstatus'] = 1;
        unset($pe['name'], $pe['__islocal'], $pe['__unsaved']);

        $created = $this->client->create('Payment Entry', $pe);
        $data = $created['data'] ?? [];
        $name = $data['name'] ?? null;
        if (! $name) {
            throw new RuntimeException('ERPNext did not return a Payment Entry name.');
        }
        $this->submitDoc('Payment Entry', $data);

        return $name;
    }

    /** @return array{account: string, currency: ?string}|null */
    protected function modeAccount(string $mode, ?string $company): ?array
    {
        try {
            $doc = $this->client->doc('Mode of Payment', $mode);
            foreach ($doc['accounts'] ?? [] as $row) {
                if (($row['company'] ?? null) === $company && ! empty($row['default_account'])) {
                    $acct = $this->client->doc('Account', $row['default_account']);

                    return ['account' => $row['default_account'], 'currency' => $acct['account_currency'] ?? null];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
