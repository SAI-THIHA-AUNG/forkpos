<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\ItemGroup;
use App\Models\PosDraft;
use App\Models\PosProfile;
use App\Models\StockBalance;
use App\Models\Uom;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ERPNext POS Profile "Hide Unavailable Items": when the flag is on, stock
 * items with non-positive stock in the profile's warehouse are not displayed
 * in the register (grid, search, or a resumed draft). Non-stock items
 * (services) are always sellable; barcode scanning still returns the item so
 * the cashier sees the "out" badge, matching ERPNext's own behaviour.
 */
class HideUnavailableItemsTest extends TestCase
{
    use RefreshDatabase;

    protected PosProfile $profile;

    protected Item $outOfStock;

    protected Item $inStock;

    protected Item $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->profile = PosProfile::where('name', 'Main POS')->firstOrFail();
        $this->profile->update(['require_shift' => false]);

        // The ERPNext flag lives in the doc snapshot — no migration needed.
        $this->profile->forceFill(['erpnext_data' => ['hide_unavailable_items' => 1]])->save();

        $this->outOfStock = Item::where('item_code', 'ITEM-0002')->firstOrFail(); // Sprite
        $this->inStock = Item::where('item_code', 'ITEM-0001')->firstOrFail();    // Coca Cola
        StockBalance::where('item_id', $this->outOfStock->id)->update(['qty' => 0]);

        // A non-stock item (service) must stay visible even at zero stock.
        $uom = Uom::firstOrFail();
        $group = ItemGroup::firstOrFail();
        $this->service = Item::create([
            'item_code' => 'SERVICE-DELIVERY',
            'item_name' => 'Delivery Fee',
            'item_group_id' => $group->id,
            'stock_uom_id' => $uom->id,
            'sales_uom_id' => $uom->id,
            'is_stock_item' => false,
            'standard_rate' => 2.00,
            'allow_discount' => true,
        ]);
    }

    public function test_register_hides_zero_stock_items_but_keeps_services_visible(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->get('/pos');
        $response->assertOk();

        $codes = $response->viewData('items')->pluck('item_code')->all();

        $this->assertNotContains($this->outOfStock->item_code, $codes, 'a stock item with no stock is hidden');
        $this->assertContains($this->inStock->item_code, $codes, 'an in-stock item is still listed');
        $this->assertContains($this->service->item_code, $codes, 'a non-stock item is never hidden by stock');
    }

    public function test_items_endpoint_hides_zero_stock_items_and_reports_the_visible_total(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->getJson('/pos/items?limit_page_length=500');
        $response->assertOk();

        $codes = collect($response->json('data'))->pluck('item_code')->all();

        $this->assertNotContains($this->outOfStock->item_code, $codes);
        $this->assertContains($this->inStock->item_code, $codes);
        $this->assertContains($this->service->item_code, $codes);
        $this->assertSame(count($codes), $response->json('meta.total'), 'the total matches the visible page');
    }

    public function test_the_policy_is_off_without_the_flag(): void
    {
        $this->profile->forceFill(['erpnext_data' => []])->save();

        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->getJson('/pos/items?limit_page_length=500');
        $response->assertOk();

        $codes = collect($response->json('data'))->pluck('item_code')->all();

        $this->assertContains($this->outOfStock->item_code, $codes, 'without the flag every sellable item is listed');
    }

    public function test_barcode_scanning_still_returns_an_item_without_stock(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();
        $barcode = ItemBarcode::where('item_id', $this->outOfStock->id)->firstOrFail()->barcode;

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->getJson("/pos/barcode/{$barcode}");

        $response->assertOk();
        $this->assertSame($this->outOfStock->item_code, $response->json('data.item_code'));
        $this->assertSame(0.0, (float) $response->json('data.stock_qty'), 'the cashier sees the out badge, not a 404');
    }

    public function test_a_saved_draft_drops_lines_whose_item_ran_out_of_stock(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $draft = PosDraft::create([
            'pos_profile_id' => $this->profile->id,
            'user_id' => $cashier->id,
            'label' => 'Held cart',
            'item_count' => 2,
            'total' => 0,
            'payload' => [
                'items' => [
                    ['item_id' => $this->inStock->id, 'qty' => 1],
                    ['item_id' => $this->outOfStock->id, 'qty' => 1],
                ],
            ],
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->get("/pos?draft={$draft->id}");
        $response->assertOk();

        $cart = $response->viewData('draftCart');

        $this->assertSame(1, $cart['dropped'], 'the unavailable line was dropped');
        $this->assertCount(1, $cart['lines']);
        $this->assertSame($this->inStock->item_code, $cart['lines'][0]['item']['item_code']);
    }

    public function test_an_item_group_with_no_sellable_items_is_hidden_from_the_category_list(): void
    {
        $group = ItemGroup::create(['name' => 'Empty Group']);
        $uom = Uom::firstOrFail();
        $item = Item::create([
            'item_code' => 'EMPTY-GROUP-ITEM',
            'item_name' => 'Empty Group Item',
            'item_group_id' => $group->id,
            'stock_uom_id' => $uom->id,
            'sales_uom_id' => $uom->id,
            'is_stock_item' => true,
            'standard_rate' => 1.00,
            'allow_discount' => true,
        ]);
        StockBalance::create([
            'item_id' => $item->id,
            'warehouse_id' => $this->profile->warehouse_id,
            'qty' => 0,
        ]);

        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->get('/pos');
        $response->assertOk();

        $names = $response->viewData('groups')->pluck('name')->all();

        $this->assertNotContains('Empty Group', $names, 'a category whose only item is out of stock is hidden');
        $this->assertContains('Beverages', $names, 'a category with an in-stock item still shows');
    }

    public function test_an_item_group_whose_items_are_out_of_stock_still_shows_without_the_flag(): void
    {
        $this->profile->forceFill(['erpnext_data' => []])->save();

        $group = ItemGroup::create(['name' => 'Empty Group']);
        $uom = Uom::firstOrFail();
        $item = Item::create([
            'item_code' => 'EMPTY-GROUP-ITEM',
            'item_name' => 'Empty Group Item',
            'item_group_id' => $group->id,
            'stock_uom_id' => $uom->id,
            'sales_uom_id' => $uom->id,
            'is_stock_item' => true,
            'standard_rate' => 1.00,
            'allow_discount' => true,
        ]);
        StockBalance::create([
            'item_id' => $item->id,
            'warehouse_id' => $this->profile->warehouse_id,
            'qty' => 0,
        ]);

        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->get('/pos');
        $response->assertOk();

        $names = $response->viewData('groups')->pluck('name')->all();

        $this->assertContains('Empty Group', $names, 'without the flag every non-empty category shows');
    }
}
