<?php

namespace Tests\Feature;

use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\ErpNextClient;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for the 3 sync bugs the user found by local testing:
 *
 *  1. ErpNextClient::request() passed the query array to PendingRequest::query(),
 *     which in Laravel 13 takes (string $url, array $data) — so the array was
 *     treated as a URL and the request 400'd. Fixed by passing query as the
 *     2nd arg to get().
 *  2. pullWarehouse() requested `warehouse_code`, which is not a field on the
 *     ERPNext v16 Warehouse doctype → 417 DataError. Fixed by dropping it.
 *  3. pullItem() filtered by `company`, but Item is not company-scoped in
 *     ERPNext → 417 DataError. Fixed by removing the company filter.
 *
 * Plus the minor pullStock() fix: target the POS profile's warehouse (or a
 * non-group default), not the first alphabetical warehouse (which can be a
 * group warehouse like "Finished Goods - M" with 0 Bin rows).
 */
class SyncFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Point the connector at a fake ERPNext.
        $setting = Setting::current();
        $setting->forceFill([
            'erpnext_url' => 'https://erp.example.test',
            'erpnext_api_key' => 'fake-key',
            'erpnext_api_secret' => 'fake-secret',
            'erpnext_company' => 'ACME',
            'erpnext_price_list' => 'Standard Selling',
        ])->save();
        Setting::flushCache();
    }

    public function test_client_sends_query_params_as_url_params_not_body(): void
    {
        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/UOM*' => Http::response(['data' => [['name' => 'Nos']]], 200),
        ]);

        $client = app(ErpNextClient::class);
        $rows = $client->list('UOM', ['name'], [['enabled', '=', 1]], 10);

        $this->assertSame([['name' => 'Nos']], $rows);

        // The query string must have been sent on the URL, not as a JSON body.
        // PendingRequest::query() treats its first arg as a URL and would have
        // produced a malformed request — this assertion proves the fix holds.
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://erp.example.test/api/resource/UOM')
                && str_contains($request->url(), 'fields=')
                && str_contains($request->url(), 'filters=')
                && str_contains($request->url(), 'limit_page_length=10');
        });
    }

    public function test_pull_warehouse_does_not_request_warehouse_code(): void
    {
        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/Warehouse*' => Http::response(['data' => [
                ['name' => 'Stores - ACME', 'is_group' => 0, 'company' => 'ACME'],
            ]], 200),
        ]);

        $sync = app(SyncService::class);
        $result = $sync->pullWarehouse(50);

        $this->assertSame(1, $result['upserted']);
        $this->assertDatabaseHas('warehouses', [
            'erpnext_id' => 'Stores - ACME',
            'name' => 'Stores - ACME',
            'is_group' => false,
        ]);

        // Regression: `warehouse_code` must NOT be in the requested fields.
        Http::assertSent(function ($request) {
            $url = $request->url();
            $fieldsParam = preg_match('/[?&]fields=([^&]+)/', $url, $m)
                ? json_decode(urldecode($m[1]), true)
                : [];
            return is_array($fieldsParam) && ! in_array('warehouse_code', $fieldsParam, true);
        });
    }

    public function test_pull_item_does_not_filter_by_company(): void
    {
        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/Item*' => Http::response(['data' => [
                ['name' => 'Cola', 'item_code' => 'COLA', 'item_name' => 'Cola 350ml',
                 'item_group' => 'Beverages', 'stock_uom' => 'Unit', 'sales_uom' => 'Unit',
                 'is_stock_item' => 1, 'standard_rate' => 1.2, 'disabled' => 0],
            ]], 200),
        ]);

        $sync = app(SyncService::class);
        $result = $sync->pullItem(50);

        $this->assertSame(1, $result['upserted']);
        $this->assertDatabaseHas('items', ['erpnext_id' => 'Cola', 'item_code' => 'COLA']);

        // Regression: the filters must NOT contain a company clause.
        Http::assertSent(function ($request) {
            $url = $request->url();
            if (! preg_match('/[?&]filters=([^&]+)/', $url, $m)) {
                return false;
            }
            $filters = json_decode(urldecode($m[1]), true);
            foreach ($filters as $f) {
                if (($f[0] ?? null) === 'company') {
                    return false; // company filter present = regression
                }
            }
            return true;
        });
    }

    public function test_pull_stock_targets_pos_profile_warehouse_not_first_alphabetical(): void
    {
        // Seed two ERPNext-linked warehouses: a group warehouse that sorts
        // first alphabetically ("Finished Goods - M") and a non-group warehouse
        // the POS profile actually uses ("Stores - ACME").
        $group = Warehouse::create([
            'name' => 'Finished Goods - M', 'erpnext_id' => 'Finished Goods - M', 'is_group' => true,
        ]);
        $stores = Warehouse::create([
            'name' => 'Stores - ACME', 'erpnext_id' => 'Stores - ACME', 'is_group' => false, 'is_default' => true,
        ]);

        $profile = PosProfile::first();
        $profile->update(['warehouse_id' => $stores->id]);

        // Stock rows only exist for the Stores warehouse (Bins live on leaf
        // warehouses, never on group warehouses).
        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/Bin*' => function ($request) use ($stores) {
                $url = $request->url();
                // Decode the filters param and look for the Stores warehouse name
                // inside it — robust against + vs %20 encoding of spaces.
                $decoded = urldecode($url);
                if (str_contains($decoded, $stores->erpnext_id)) {
                    return Http::response(['data' => [
                        ['item_code' => 'ITEM-0001', 'warehouse' => $stores->erpnext_id, 'actual_qty' => 42.0],
                    ]], 200);
                }
                // Any other warehouse (e.g. the group) returns empty — exactly
                // what real ERPNext does for a group warehouse with no Bins.
                return Http::response(['data' => []], 200);
            },
        ]);

        $sync = app(SyncService::class);
        $result = $sync->pullStock(500);

        // If we had picked the group warehouse (first alphabetical), this would
        // be 0. The fix makes us target the POS profile's warehouse.
        $this->assertSame(1, $result['upserted'], 'stock synced from POS profile warehouse, not first alphabetical');
        $this->assertSame('Stores - ACME', $result['warehouse']);
    }
}
