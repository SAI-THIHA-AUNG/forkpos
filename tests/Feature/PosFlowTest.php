<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PosProfile;
use App\Models\Tax;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end feature tests for the POS flow. Mirrors the curl-based smoke
 * test in the repo docs (see README "Testing"): auth → shift → invoice →
 * close. Run with `php artisan test`.
 */
class PosFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    protected function loginAsAdmin(): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@pos.test',
            'password' => 'password',
        ]);
        $response->assertOk();

        return ['Authorization' => 'Bearer '.$response->json('data.token')];
    }

    protected function openShift(array $headers): int
    {
        $profileId = PosProfile::where('name', 'Main POS')->value('id');

        $response = $this->postJson('/api/v1/shifts/open', [
            'pos_profile_id' => $profileId,
            'balances' => [['mode_of_payment' => 'Cash', 'amount' => 100]],
        ], $headers);
        $response->assertCreated();

        return $response->json('data.id');
    }

    public function test_full_pos_flow_from_shift_open_to_close(): void
    {
        $headers = $this->loginAsAdmin();
        $profileId = PosProfile::where('name', 'Main POS')->value('id');
        $shiftId = $this->openShift($headers);

        $cola = Item::where('item_code', 'ITEM-0001')->first();
        $rice = Item::where('item_code', 'ITEM-0017')->first();
        $taxId = Tax::where('is_default', true)->value('id');

        // 5 x 1.20 + 2 x 6.50 = 19.00 gross, tax 5% inclusive,
        // coupon WELCOME10 = 10% of net (18.0952) = 1.8095,
        // grand = 19.00 - 1.8095 = 17.1905
        $response = $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $profileId,
            'offline_id' => 'test-uuid-1',
            'coupon_code' => 'WELCOME10',
            'items' => [
                ['item_id' => $cola->id, 'qty' => 5, 'tax_id' => $taxId],
                ['item_id' => $rice->id, 'qty' => 2, 'tax_id' => $taxId],
            ],
            'payments' => [
                ['mode_of_payment' => 'Cash', 'amount' => 20.00],
            ],
        ], $headers);

        $response->assertCreated();
        $inv = $response->json('data');
        $this->assertEquals('paid', $inv['status']);
        $this->assertEqualsWithDelta(18.10, $inv['net_total'], 0.005);
        $this->assertEqualsWithDelta(0.90, $inv['tax_total'], 0.005);
        $this->assertEqualsWithDelta(1.81, $inv['coupon_discount'], 0.005);
        $this->assertEqualsWithDelta(17.19, $inv['grand_total'], 0.005);
        $this->assertEqualsWithDelta(2.81, $inv['change_amount'], 0.01);
        // Demo settings carry ERPNext's Sales Invoice series, so the number is
        // {series}{van}-{year}-##### (the same shape it has after a real sync).
        $this->assertSame('ACC-SINV-MP-'.now()->year.'-00001', $inv['invoice_no']);

        // stock deducted
        $this->assertEquals(95.0, $cola->fresh()->stockQty());

        // close the shift with the expected amount counted
        $expectedCash = 100 + 20.00; // opening + collected (no cash movements)
        $close = $this->postJson("/api/v1/shifts/{$shiftId}/close", [
            'payments' => [['mode_of_payment' => 'Cash', 'counted_amount' => $expectedCash]],
        ], $headers);
        $close->assertCreated();
        $this->assertEquals(0.0, $close->json('data.payments.0.difference'));
    }

    public function test_offline_id_is_idempotent(): void
    {
        $headers = $this->loginAsAdmin();
        $profileId = PosProfile::where('name', 'Main POS')->value('id');
        $this->openShift($headers);
        $cola = Item::where('item_code', 'ITEM-0001')->first();

        $body = [
            'pos_profile_id' => $profileId,
            'offline_id' => 'offline-uuid-42',
            'items' => [['item_id' => $cola->id, 'qty' => 1]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 1.20]],
        ];
        $first = $this->postJson('/api/v1/invoices', $body, $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/v1/invoices', $body, $headers);
        $second->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_insufficient_stock_is_rejected(): void
    {
        $headers = $this->loginAsAdmin();
        $profileId = PosProfile::where('name', 'Main POS')->value('id');
        $this->openShift($headers);
        $cola = Item::where('item_code', 'ITEM-0001')->first();

        $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $profileId,
            'items' => [['item_id' => $cola->id, 'qty' => 1000]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 1200]],
        ], $headers)->assertUnprocessable();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_pin_login_and_cashier_permissions(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['pin' => '5678']);
        $response->assertOk();
        $token = $response->json('data.token');
        $this->assertEquals('cashier', $response->json('data.user.role'));

        $cashierHeaders = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/v1/items', $cashierHeaders)->assertOk();
        $this->putJson('/api/v1/settings', ['company_name' => 'hax'], $cashierHeaders)->assertForbidden();
        $this->postJson('/api/v1/sync/masters', [], $cashierHeaders)->assertForbidden();
    }

    public function test_invoice_requires_open_shift(): void
    {
        $headers = $this->loginAsAdmin();
        $profileId = PosProfile::where('name', 'Main POS')->value('id');
        $cola = Item::where('item_code', 'ITEM-0001')->first();

        $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $profileId,
            'items' => [['item_id' => $cola->id, 'qty' => 1]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 1.20]],
        ], $headers)->assertUnprocessable();
    }
}
