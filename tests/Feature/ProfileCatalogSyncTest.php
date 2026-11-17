<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\SyncState;
use App\Services\ProfileCatalogSync;
use App\Support\PosProfileConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfileCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::current()->forceFill([
            'erpnext_url' => 'https://erp.example.test',
            'erpnext_api_key' => 'k',
            'erpnext_api_secret' => 's',
        ])->save();
        Setting::flushCache();
    }

    protected function profile(): PosProfile
    {
        return PosProfile::create([
            'name' => 'Test Van POS',
            'erpnext_id' => 'Test Van POS',
            'currency' => 'MMK',
            'selling_price_list' => 'Retail',
            'synced_at' => now(),
            'erpnext_data' => [
                'name' => 'Test Van POS',
                'warehouse' => 'Stores - M',
                'item_groups' => [['item_group' => 'Drinks']],
                'customer_groups' => [['customer_group' => 'Retail']],
            ],
        ]);
    }

    protected function fakeCatalog(int $binStatus = 200): void
    {
        $modified = '2026-11-16 10:00:00.000000';
        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/Warehouse*' => Http::response(['data' => [['name' => 'Stores - M', 'is_group' => 0, 'company' => 'ACME']]], 200),
            'erp.example.test/api/resource/Item%20Price*' => Http::response(['data' => [[
                'name' => 'IP1', 'item_code' => 'ITEM-1', 'price_list' => 'Retail', 'price_list_rate' => 5, 'currency' => 'MMK', 'modified' => $modified,
            ]]], 200),
            'erp.example.test/api/resource/Item*' => Http::response(['data' => [[
                'name' => 'ITEM-1', 'item_code' => 'ITEM-1', 'item_name' => 'Tea', 'item_group' => 'Drinks',
                'stock_uom' => 'Nos', 'sales_uom' => 'Nos', 'is_stock_item' => 1, 'standard_rate' => 4, 'disabled' => 0, 'modified' => $modified,
            ]]], 200),
            'erp.example.test/api/resource/Bin*' => $binStatus === 200
                ? Http::response(['data' => [['item_code' => 'ITEM-1', 'warehouse' => 'Stores - M', 'actual_qty' => 12]]], 200)
                : Http::response([], $binStatus),
            'erp.example.test/api/resource/Customer*' => Http::response(['data' => [[
                'name' => 'CUST-1', 'customer_name' => 'Ko Ko', 'customer_group' => 'Retail', 'territory' => 'All Territories', 'disabled' => 0, 'modified' => $modified,
            ]]], 200),
        ]);
    }

    public function test_pulls_only_what_the_profile_scopes_and_marks_it_ready(): void
    {
        $this->fakeCatalog();
        $profile = $this->profile();
        $sync = app(ProfileCatalogSync::class);

        $this->assertFalse($sync->isReady($profile));

        $outcome = $sync->run($profile);

        $this->assertSame('done', $outcome['item']);
        $this->assertSame('done', $outcome['stock']);
        $this->assertTrue($sync->isReady($profile->fresh()));

        $item = Item::where('erpnext_id', 'ITEM-1')->firstOrFail();
        $this->assertSame('MMK', ItemPrice::where('item_id', $item->id)->value('currency'));
        $this->assertSame('Retail', ItemPrice::where('item_id', $item->id)->value('price_list'));
        $this->assertEquals(12, StockBalance::where('item_id', $item->id)->value('qty'));
        $this->assertNotNull($profile->fresh()->warehouse_id, 'warehouse step links the profile');

        // The requests were scoped to the profile, not "everything".
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'resource/Item?') && str_contains(urldecode($r->url()), '["item_group","in",["Drinks"]]'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'resource/Item%20Price') && str_contains(urldecode($r->url()), '["price_list","=","Retail"]'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'resource/Customer') && str_contains(urldecode($r->url()), '["customer_group","in",["Retail"]]'));
    }

    public function test_second_run_fetches_only_changes_since_the_stored_cursor(): void
    {
        $this->fakeCatalog();
        $profile = $this->profile();
        $sync = app(ProfileCatalogSync::class);

        $sync->run($profile);
        $this->assertSame('2026-11-16 10:00:00.000000', SyncState::where('pos_profile_id', $profile->id)->where('entity', 'item')->value('cursor'));

        $sync->run($profile->fresh());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'resource/Item?') && str_contains(urldecode($r->url()), '["modified",">","2026-11-16 10:00:00.000000"]'));
    }

    public function test_a_failing_step_is_recorded_and_blocks_readiness(): void
    {
        $this->fakeCatalog(binStatus: 500);
        $profile = $this->profile();
        $sync = app(ProfileCatalogSync::class);

        $outcome = $sync->run($profile);

        $this->assertSame('failed', $outcome['stock']);
        $this->assertSame('done', $outcome['item']);
        $this->assertFalse($sync->isReady($profile->fresh()));
        $this->assertTrue($sync->progress($profile->fresh())['failed']);
    }

    public function test_local_only_profiles_are_always_ready(): void
    {
        $local = PosProfile::create(['name' => 'Local']);

        $this->assertTrue(app(ProfileCatalogSync::class)->isReady($local));
    }

    public function test_profile_config_maps_erpnext_flags_and_keeps_local_defaults_separate(): void
    {
        $synced = PosProfileConfig::synced([
            'name' => 'P', 'currency' => 'MMK', 'allow_user_to_edit_rate' => 1, 'customer' => 'Walk-in',
        ]);

        $this->assertTrue($synced['allow_rate_change']);
        $this->assertFalse($synced['allow_discount_change']);
        $this->assertFalse($synced['ask_for_customer']);
        $this->assertArrayNotHasKey('require_shift', $synced, 'local-only fields are not overwritten by ERPNext');
        $this->assertTrue(PosProfileConfig::localDefaults()['require_shift']);
    }
}
