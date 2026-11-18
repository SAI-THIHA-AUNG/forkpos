<?php

namespace Tests\Feature;

use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\PosProfileSync;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for the POS Profile -> ERPNext sync.
 *
 * Covers:
 *   - syncOne() pulls a single POS Profile doc and upserts the local row,
 *     mapping currency (e.g. MMK), warehouse FK, write_off_account, cost_center,
 *     and the full doc snapshot into erpnext_data.
 *   - syncOne() reconciles the payments child table: upserts rows from the
 *     ERPNext `payments` child table, and deletes local rows that no longer
 *     exist upstream.
 *   - syncAll() iterates the ERPNext POS Profile list and skips docs that fail.
 */
class PosProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $setting = Setting::current();
        $setting->forceFill([
            'erpnext_url' => 'https://erp.example.test',
            'erpnext_api_key' => 'fake-key',
            'erpnext_api_secret' => 'fake-secret',
            'erpnext_company' => 'My Company',
            'erpnext_price_list' => 'Standard Selling',
        ])->save();
        Setting::flushCache();
    }

    /**
     * The canonical "Test Van POS" doc shape from ERPNext v16.
     *
     * @return array<string, mixed>
     */
    protected function vanPosDoc(): array
    {
        return [
            'name' => 'Test Van POS',
            'pos_profile_name' => 'Test Van POS',
            'company' => 'My Company',
            'country' => 'Myanmar',
            'currency' => 'MMK',
            'warehouse' => 'Stores - M',
            'selling_price_list' => 'Standard Selling',
            'write_off_limit' => 5000,
            'write_off_account' => 'Write Off - M',
            'write_off_cost_center' => 'Main - M',
            'disabled' => 0,
            'payments' => [
                ['mode_of_payment' => 'Cash', 'default' => 1],
                ['mode_of_payment' => 'Bank', 'default' => 0],
                ['mode_of_payment' => 'Mobile Money', 'default' => 0],
            ],
        ];
    }

    public function test_sync_one_creates_profile_with_erpnext_currency_warehouse_and_payments(): void
    {
        Warehouse::create([
            'name' => 'Stores - M',
            'erpnext_id' => 'Stores - M',
            'is_group' => false,
            'is_default' => true,
        ]);

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response([
                'data' => $this->vanPosDoc(),
            ], 200),
        ]);

        $profile = app(PosProfileSync::class)->syncOne('Test Van POS');

        $this->assertSame('Test Van POS', $profile->erpnext_id);
        $this->assertSame('Test Van POS', $profile->name);
        $this->assertSame('My Company', $profile->company);
        $this->assertSame('Myanmar', $profile->country);
        $this->assertSame('MMK', $profile->currency);
        $this->assertSame('Standard Selling', $profile->selling_price_list);
        $this->assertSame('Write Off - M', $profile->write_off_account);
        $this->assertSame('Main - M', $profile->cost_center);
        $this->assertSame(5000.0, (float) $profile->write_off_limit);

        $this->assertNotNull($profile->warehouse_id);
        $this->assertSame('Stores - M', $profile->warehouse->name);

        $this->assertSame('Test Van POS', $profile->erpnext_data['name']);
        $this->assertSame('MMK', $profile->erpnext_data['currency']);

        $this->assertNotNull($profile->synced_at);
        $this->assertTrue($profile->isErpnextSynced());

        $this->assertSame(3, $profile->payments()->count());
        $this->assertSame(
            ['Bank', 'Cash', 'Mobile Money'],
            $profile->payments()->orderBy('mode_of_payment')->pluck('mode_of_payment')->all(),
        );
        $this->assertTrue($profile->payments()->where('mode_of_payment', 'Cash')->first()->is_default);
        $this->assertFalse($profile->payments()->where('mode_of_payment', 'Bank')->first()->is_default);
    }

    public function test_sync_one_reconciles_payments_when_a_method_is_removed_upstream(): void
    {
        Warehouse::create(['name' => 'Stores - M', 'erpnext_id' => 'Stores - M', 'is_group' => false]);

        $doc = $this->vanPosDoc();

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => function () use (&$doc) {
                return Http::response(['data' => $doc], 200);
            },
        ]);

        $sync = app(PosProfileSync::class);
        $sync->syncOne('Test Van POS');
        $this->assertSame(3, PosProfile::where('erpnext_id', 'Test Van POS')->first()->payments()->count());

        // "Mobile Money" was removed upstream.
        unset($doc['payments'][2]);

        $sync->syncOne('Test Van POS');

        $profile = PosProfile::where('erpnext_id', 'Test Van POS')->first();
        $this->assertSame(2, $profile->payments()->count());
        $this->assertSame(
            ['Bank', 'Cash'],
            $profile->payments()->orderBy('mode_of_payment')->pluck('mode_of_payment')->all(),
        );
    }

    public function test_sync_one_updates_existing_profile_idempotently(): void
    {
        Warehouse::create(['name' => 'Stores - M', 'erpnext_id' => 'Stores - M', 'is_group' => false]);

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response([
                'data' => $this->vanPosDoc(),
            ], 200),
        ]);

        $sync = app(PosProfileSync::class);
        $first = $sync->syncOne('Test Van POS');
        $second = $sync->syncOne('Test Van POS');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PosProfile::where('erpnext_id', 'Test Van POS')->count());
    }

    public function test_sync_all_lists_and_pulls_each_profile(): void
    {
        Warehouse::create(['name' => 'Stores - M', 'erpnext_id' => 'Stores - M', 'is_group' => false]);

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response([
                'data' => $this->vanPosDoc(),
            ], 200),
            'erp.example.test/api/resource/POS%20Profile*' => Http::response([
                'data' => [['name' => 'Test Van POS']],
            ], 200),
        ]);

        $result = app(PosProfileSync::class)->syncAll();

        $this->assertSame(1, $result['synced']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('MMK', PosProfile::where('erpnext_id', 'Test Van POS')->value('currency'));
    }

    public function test_sync_one_allows_warehouse_fk_to_be_null_when_not_yet_synced(): void
    {
        // Simulates running the POS Profile sync before the warehouse sync: the
        // profile should still be created with warehouse_id=NULL so the settings
        // page can warn the user to run a warehouse sync.
        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response([
                'data' => $this->vanPosDoc(),
            ], 200),
        ]);

        $profile = app(PosProfileSync::class)->syncOne('Test Van POS');

        $this->assertNull($profile->warehouse_id);
        $this->assertSame('MMK', $profile->currency);
        $this->assertSame(3, $profile->payments()->count());
    }

    public function test_sync_all_continues_past_one_failed_profile(): void
    {
        Warehouse::create(['name' => 'Stores - M', 'erpnext_id' => 'Stores - M', 'is_group' => false]);

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response([
                'data' => $this->vanPosDoc(),
            ], 200),
            'erp.example.test/api/resource/POS%20Profile/Ghost%20POS' => Http::response([], 404),
            'erp.example.test/api/resource/POS%20Profile*' => Http::response([
                'data' => [
                    ['name' => 'Test Van POS'],
                    ['name' => 'Ghost POS'],
                ],
            ], 200),
        ]);

        $result = app(PosProfileSync::class)->syncAll();

        $this->assertSame(1, $result['synced']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Ghost POS', $result['errors'][0]);
    }

    public function test_sync_one_carries_the_hide_unavailable_items_flag_for_the_register(): void
    {
        Warehouse::create(['name' => 'Stores - M', 'erpnext_id' => 'Stores - M', 'is_group' => false, 'is_default' => true]);

        $doc = array_merge($this->vanPosDoc(), ['hide_unavailable_items' => 1]);

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response([
                'data' => $doc,
            ], 200),
        ]);

        $profile = app(PosProfileSync::class)->syncOne('Test Van POS');

        $this->assertTrue($profile->hideUnavailableItems(), 'the ERPNext flag reaches the register via the doc snapshot');
        $this->assertSame(1, $profile->erpnext_data['hide_unavailable_items']);
    }
}
