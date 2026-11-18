<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Item;
use App\Models\PosCashMovement;
use App\Models\PosOpeningShift;
use App\Models\PosProfile;
use App\Models\User;
use App\Services\ShiftService;
use App\Services\StockService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelling a sale keeps the row (with who / when / why), puts stock back, voids
 * the payments, and is fenced by one guard: owner or manager, closed shift,
 * returns, and ERPNext sync.
 */
class CancelSaleTest extends TestCase
{
    use RefreshDatabase;

    protected array $admin;

    protected array $cashier;

    protected int $profileId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->admin = $this->login('admin@pos.test');
        $this->cashier = $this->login('cashier@pos.test');
        $this->profileId = PosProfile::where('name', 'Main POS')->value('id');

        $this->openShift($this->admin);
        $this->openShift($this->cashier);
    }

    protected function login(string $email): array
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password']);

        return ['Authorization' => 'Bearer '.$login->json('data.token')];
    }

    protected function openShift(array $headers): int
    {
        auth()->forgetGuards();

        return $this->postJson('/api/v1/shifts/open', [
            'pos_profile_id' => $this->profileId,
            'balances' => [['mode_of_payment' => 'Cash', 'amount' => 100]],
        ], $headers)->assertCreated()->json('data.id');
    }

    protected function cola(): Item
    {
        return Item::where('item_code', 'ITEM-0001')->first(); // 1.20 each
    }

    protected function stockOf(Item $item): float
    {
        return (float) app(StockService::class)->balancesFor([$item->id], PosProfile::find($this->profileId)->warehouse_id)[$item->id];
    }

    protected function sell(array $headers, float $qty = 10): array
    {
        auth()->forgetGuards();

        $response = $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'items' => [['item_id' => $this->cola()->id, 'qty' => $qty]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 20]], // 12.00 due, 8.00 change
        ], $headers);
        $response->assertCreated();

        return $response->json('data');
    }

    protected function cancel(int $id, array $headers, ?string $reason = 'Customer changed their mind')
    {
        auth()->forgetGuards();

        return $this->postJson("/api/v1/invoices/{$id}/cancel", array_filter(['reason' => $reason]), $headers);
    }

    public function test_cancel_keeps_the_row_restores_stock_and_records_who_when_and_why(): void
    {
        $before = $this->stockOf($this->cola());
        $sale = $this->sell($this->admin);
        $this->assertEqualsWithDelta($before - 10, $this->stockOf($this->cola()), 0.001);

        $data = $this->cancel($sale['id'], $this->admin)->assertOk()->json('data');

        $this->assertSame('cancelled', $data['status']);
        $this->assertSame('Customer changed their mind', $data['cancel_reason']);
        $this->assertNotNull($data['cancelled_at']);
        $this->assertSame(User::where('email', 'admin@pos.test')->value('name'), $data['cancelled_by']);
        $this->assertEqualsWithDelta($before, $this->stockOf($this->cola()), 0.001);
        $this->assertDatabaseHas('invoices', ['id' => $sale['id'], 'status' => 'cancelled']);
        $this->assertDatabaseCount('invoice_payments', 1); // kept on record, never deleted
    }

    public function test_a_cancelled_sale_drops_out_of_the_shift_totals(): void
    {
        $sale = $this->sell($this->admin);
        $shiftId = Invoice::find($sale['id'])->shift_id;
        $cashOf = fn () => collect(app(ShiftService::class)->summary(PosOpeningShift::find($shiftId))['payments'])
            ->firstWhere('mode_of_payment', 'Cash')['collected_amount'];

        $this->assertEqualsWithDelta(20.0, $cashOf(), 0.005);

        $this->cancel($sale['id'], $this->admin)->assertOk();

        $this->assertEqualsWithDelta(0.0, $cashOf(), 0.005);
    }

    public function test_a_reason_is_required(): void
    {
        $sale = $this->sell($this->admin);

        $this->cancel($sale['id'], $this->admin, reason: null)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('paid', Invoice::find($sale['id'])->status);
    }

    public function test_a_cashier_cannot_cancel_someone_elses_sale_but_a_manager_can(): void
    {
        $sale = $this->sell($this->admin);

        $this->cancel($sale['id'], $this->cashier)->assertUnprocessable()->assertJsonValidationErrors('cancel');

        $own = $this->sell($this->cashier);
        $this->cancel($own['id'], $this->cashier)->assertOk();
        $this->cancel($sale['id'], $this->admin)->assertOk();
    }

    public function test_a_sale_cannot_be_cancelled_twice(): void
    {
        $sale = $this->sell($this->admin);
        $this->cancel($sale['id'], $this->admin)->assertOk();

        $this->cancel($sale['id'], $this->admin)->assertUnprocessable();
        $this->assertEqualsWithDelta(0.0, Invoice::find($sale['id'])->outstanding_amount, 0.001);
    }

    public function test_a_sale_with_returns_cannot_be_cancelled(): void
    {
        $sale = $this->sell($this->admin);
        auth()->forgetGuards();
        $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'is_return' => true,
            'return_against_id' => $sale['id'],
            'items' => [['item_id' => $this->cola()->id, 'qty' => 1]],
        ], $this->admin)->assertCreated();

        $this->cancel($sale['id'], $this->admin)->assertUnprocessable()->assertJsonValidationErrors('cancel');
    }

    public function test_a_sale_already_synced_to_erpnext_cannot_be_cancelled(): void
    {
        $sale = $this->sell($this->admin);
        Invoice::whereKey($sale['id'])->update(['sync_status' => 'synced', 'erpnext_id' => 'ACC-SINV-2026-00001']);

        $this->cancel($sale['id'], $this->admin)->assertUnprocessable()->assertJsonValidationErrors('cancel');
        $this->assertSame('paid', Invoice::find($sale['id'])->status);
    }

    public function test_a_sale_left_as_a_draft_by_a_failed_submit_can_still_be_cancelled_locally(): void
    {
        $sale = $this->sell($this->admin);
        // The push created the invoice but ERPNext refused the submit: the draft
        // name is remembered and the sync failed, so nothing was posted.
        Invoice::whereKey($sale['id'])->update(['sync_status' => 'failed', 'erpnext_id' => 'ACC-SINV-TVP-2026-00009']);

        $this->cancel($sale['id'], $this->admin)->assertOk();
        $this->assertSame('cancelled', Invoice::find($sale['id'])->status);
    }

    public function test_cancelling_into_a_closed_shift_is_manager_only_and_books_the_refund_as_cash_out(): void
    {
        $sale = $this->sell($this->cashier);
        $oldShiftId = Invoice::find($sale['id'])->shift_id;
        auth()->forgetGuards();
        $this->postJson("/api/v1/shifts/{$oldShiftId}/close", [], $this->cashier)->assertCreated();

        // The cashier's shift is closed: they can no longer cancel it.
        $this->cancel($sale['id'], $this->cashier)->assertUnprocessable()->assertJsonValidationErrors('cancel');

        // A manager can, and the refund (paid 20 − change 8 = 12) comes out of the manager's open shift.
        $this->cancel($sale['id'], $this->admin)->assertOk();

        $movement = PosCashMovement::where('type', 'out')->first();
        $this->assertNotNull($movement);
        $this->assertEqualsWithDelta(12.0, $movement->amount, 0.005);
        $this->assertSame('Cash', $movement->mode_of_payment);
        $this->assertStringContainsString($sale['invoice_no'], $movement->reason);
    }

    public function test_cancelling_a_closed_shift_sale_needs_the_manager_to_have_an_open_shift(): void
    {
        $sale = $this->sell($this->cashier);
        auth()->forgetGuards();
        $this->postJson('/api/v1/shifts/'.Invoice::find($sale['id'])->shift_id.'/close', [], $this->cashier)->assertCreated();
        $adminShift = PosOpeningShift::where('user_id', User::where('email', 'admin@pos.test')->value('id'))->value('id');
        auth()->forgetGuards();
        $this->postJson("/api/v1/shifts/{$adminShift}/close", [], $this->admin)->assertCreated();

        $this->cancel($sale['id'], $this->admin)->assertUnprocessable()->assertJsonValidationErrors('cancel');
        $this->assertSame('paid', Invoice::find($sale['id'])->status);
    }

    public function test_the_invoice_page_shows_the_cancel_form_or_why_it_is_blocked(): void
    {
        $sale = $this->sell($this->admin);
        $admin = User::where('email', 'admin@pos.test')->first();
        $web = fn () => $this->actingAs($admin)->withSession(['pos_profile_id' => $this->profileId]);

        $web()->get(route('invoices.show', $sale['id']))->assertOk()->assertSee('Cancel sale');

        Invoice::whereKey($sale['id'])->update(['erpnext_id' => 'ACC-SINV-1', 'sync_status' => 'synced']);
        $web()->get(route('invoices.show', $sale['id']))->assertOk()
            ->assertSee('Cancel not available')->assertDontSee('Cancel sale');
    }

    public function test_web_cancel_requires_a_reason_and_stores_it(): void
    {
        $sale = $this->sell($this->admin);
        $admin = User::where('email', 'admin@pos.test')->first();

        $this->actingAs($admin)->withSession(['pos_profile_id' => $this->profileId])
            ->post(route('invoices.cancel', $sale['id']), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)->withSession(['pos_profile_id' => $this->profileId])
            ->post(route('invoices.cancel', $sale['id']), ['reason' => 'Wrong customer'])
            ->assertSessionHas('success');

        $this->assertSame('Wrong customer', Invoice::find($sale['id'])->cancel_reason);
    }
}
