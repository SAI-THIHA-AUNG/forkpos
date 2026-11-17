<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\PosDraft;
use App\Models\PosProfile;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drafts hold a register cart: no stock movement, no payment, no invoice number,
 * until the draft is resumed and charged.
 */
class DraftSaleTest extends TestCase
{
    use RefreshDatabase;

    protected int $profileId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->profileId = PosProfile::where('name', 'Main POS')->value('id');

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'admin@pos.test', 'password' => 'password']);
        $this->postJson('/api/v1/shifts/open', [
            'pos_profile_id' => $this->profileId,
            'balances' => [['mode_of_payment' => 'Cash', 'amount' => 100]],
        ], ['Authorization' => 'Bearer '.$login->json('data.token')])->assertCreated();
    }

    protected function web(string $email = 'admin@pos.test')
    {
        return $this->actingAs(User::where('email', $email)->first())
            ->withSession(['pos_profile_id' => $this->profileId]);
    }

    protected function cola(): Item
    {
        return Item::where('item_code', 'ITEM-0001')->first();
    }

    protected function draftPayload(array $extra = []): array
    {
        return array_merge([
            'total' => 6,
            'items' => [['item_id' => $this->cola()->id, 'qty' => 5, 'rate' => 1.2, 'discount_type' => 'percentage', 'discount_value' => 0]],
        ], $extra);
    }

    public function test_saving_a_draft_moves_no_stock_and_creates_no_invoice(): void
    {
        $stock = fn () => app(StockService::class)->balancesFor([$this->cola()->id], PosProfile::find($this->profileId)->warehouse_id)[$this->cola()->id];
        $before = $stock();

        $this->web()->postJson(route('drafts.store'), $this->draftPayload(['label' => 'Table 4']))->assertCreated();

        $this->assertDatabaseHas('pos_drafts', ['label' => 'Table 4', 'item_count' => 1, 'pos_profile_id' => $this->profileId]);
        $this->assertSame(0, Invoice::count());
        $this->assertEqualsWithDelta($before, $stock(), 0.001);
    }

    public function test_a_draft_remembers_the_customer_discount_and_credit_terms(): void
    {
        $customer = Customer::where('is_walk_in', false)->first();

        $this->web()->postJson(route('drafts.store'), $this->draftPayload([
            'customer_id' => $customer->id,
            'additional_discount_type' => 'percentage',
            'additional_discount_value' => 5,
            'is_credit' => true,
            'credit_days' => 14,
        ]))->assertCreated();

        $draft = PosDraft::first();
        $this->assertSame($customer->customer_name, $draft->label); // default label
        $this->assertSame(14, $draft->payload['credit_days']);
        $this->assertTrue($draft->payload['is_credit']);
    }

    public function test_resaving_a_resumed_draft_updates_it_instead_of_adding_another(): void
    {
        $id = $this->web()->postJson(route('drafts.store'), $this->draftPayload())->json('data.draft_id');

        $this->web()->postJson(route('drafts.store'), $this->draftPayload(['draft_id' => $id, 'label' => 'Renamed']))->assertCreated();

        $this->assertSame(1, PosDraft::count());
        $this->assertSame('Renamed', PosDraft::find($id)->label);
    }

    public function test_resuming_shows_todays_price_not_the_saved_one(): void
    {
        $id = $this->web()->postJson(route('drafts.store'), $this->draftPayload([
            'items' => [['item_id' => $this->cola()->id, 'qty' => 5, 'rate' => 0.01]], // a stale / tampered price
        ]))->json('data.draft_id');

        // The profile does not allow price changes, so the saved rate must be ignored.
        PosProfile::whereKey($this->profileId)->update(['allow_rate_change' => false]);
        $this->web()->get(route('pos.register', ['draft' => $id]))
            ->assertOk()
            ->assertSee('Resumed draft')
            ->assertSee('"rate":1.2', false);
    }

    public function test_charging_a_resumed_draft_removes_it(): void
    {
        $id = $this->web()->postJson(route('drafts.store'), $this->draftPayload())->json('data.draft_id');

        $this->web()->postJson(route('pos.checkout'), [
            'draft_id' => $id,
            'items' => [['item_id' => $this->cola()->id, 'qty' => 5]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 6]],
        ])->assertCreated();

        $this->assertSame(0, PosDraft::count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_the_drafts_list_and_delete_are_scoped_to_the_profile(): void
    {
        $id = $this->web()->postJson(route('drafts.store'), $this->draftPayload(['label' => 'Keep me']))->json('data.draft_id');

        $this->web('cashier@pos.test')->get(route('drafts.index'))->assertOk()->assertSee('Keep me'); // shared on the profile

        $other = PosProfile::create(['name' => 'Other', 'currency' => 'USD', 'is_default' => false]);
        $foreign = PosDraft::create([
            'pos_profile_id' => $other->id, 'user_id' => User::first()->id,
            'label' => 'Foreign', 'item_count' => 1, 'total' => 1, 'payload' => ['items' => []],
        ]);
        $this->web()->get(route('drafts.index'))->assertDontSee('Foreign');
        $this->web()->delete(route('drafts.destroy', $foreign))->assertNotFound();

        $this->web()->delete(route('drafts.destroy', $id))->assertSessionHas('success');
        $this->assertDatabaseMissing('pos_drafts', ['id' => $id]);
    }

    public function test_a_draft_needs_at_least_one_item(): void
    {
        $this->web()->postJson(route('drafts.store'), ['items' => []])->assertUnprocessable();
    }
}
