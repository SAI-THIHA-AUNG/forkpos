<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Item;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\SyncService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Sale-register pricing rules: ERPNext POS Profile flags decide whether the
 * cashier may change a rate or apply a discount, and the ERPNext push must
 * not double-apply a discount that is already baked into the line rate.
 */
class RateDiscountPushTest extends TestCase
{
    use RefreshDatabase;

    protected PosProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->profile = PosProfile::where('name', 'Main POS')->firstOrFail();
        $this->profile->update(['require_shift' => false]);

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

    protected function authHeaders(): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@pos.test',
            'password' => 'password',
        ]);
        $response->assertOk();

        return ['Authorization' => 'Bearer '.$response->json('data.token')];
    }

    protected function sell(Item $item, float $qty, array $line = [], array $payments = []): TestResponse
    {
        return $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profile->id,
            'items' => [array_merge(['item_id' => $item->id, 'qty' => $qty], $line)],
            'payments' => $payments,
        ], $this->authHeaders());
    }

    public function test_rate_change_is_rejected_when_the_profile_disallows_it(): void
    {
        $this->profile->update(['allow_rate_change' => false]);
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();

        $this->sell($item, 1, ['rate' => 2.00])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_rate_change_is_stored_against_the_list_price_when_allowed(): void
    {
        $this->profile->update(['allow_rate_change' => true]);
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();

        $response = $this->sell($item, 2, ['rate' => 2.00]);
        $response->assertCreated();

        $this->assertEqualsWithDelta(1.20, $response->json('data.items.0.price_list_rate'), 0.0001);
        $this->assertEqualsWithDelta(2.00, $response->json('data.items.0.rate'), 0.0001);
        $this->assertEqualsWithDelta(4.00, $response->json('data.net_total'), 0.0001);
    }

    public function test_discount_is_rejected_when_the_profile_disallows_it(): void
    {
        $this->profile->update(['allow_discount_change' => false]);
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();

        $this->sell($item, 1, ['discount_percentage' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_percentage_discount_keeps_the_list_rate_and_discounts_the_rate(): void
    {
        $this->profile->update(['allow_discount_change' => true, 'max_discount_percentage' => 0]);
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();

        $response = $this->sell($item, 2, ['discount_percentage' => 10]);
        $response->assertCreated();

        $this->assertEqualsWithDelta(1.20, $response->json('data.items.0.price_list_rate'), 0.0001);
        $this->assertEqualsWithDelta(1.08, $response->json('data.items.0.rate'), 0.0001);
        $this->assertEqualsWithDelta(2.16, $response->json('data.items.0.amount'), 0.0001);
        $this->assertEqualsWithDelta(0.24, $response->json('data.items.0.discount_amount'), 0.0001);
    }

    public function test_amount_discount_is_converted_to_a_discounted_unit_rate(): void
    {
        $this->profile->update(['allow_discount_change' => true, 'max_discount_percentage' => 0]);
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();

        // 2 x 1.20 = 2.40 gross; a 0.40 line discount leaves 2.00 (1.00/unit).
        $response = $this->sell($item, 2, ['discount_amount' => 0.40]);
        $response->assertCreated();

        $this->assertEqualsWithDelta(1.20, $response->json('data.items.0.price_list_rate'), 0.0001);
        $this->assertEqualsWithDelta(1.00, $response->json('data.items.0.rate'), 0.0001);
        $this->assertEqualsWithDelta(0.40, $response->json('data.items.0.discount_amount'), 0.0001);
        $this->assertEqualsWithDelta(2.00, $response->json('data.net_total'), 0.0001);
    }

    public function test_max_discount_percentage_is_enforced_for_amount_discounts(): void
    {
        $this->profile->update(['allow_discount_change' => true, 'max_discount_percentage' => 10]);
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();

        // 0.60 on 2.40 = 25% > 10% limit.
        $this->sell($item, 2, ['discount_amount' => 0.60])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_push_uses_the_profile_company_and_sends_the_final_rate_without_double_discount(): void
    {
        $this->profile->update([
            'erpnext_id' => 'Test Van POS',
            'company' => 'ACME',
            'allow_discount_change' => true,
            'max_discount_percentage' => 0,
        ]);
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();
        $item->update(['erpnext_id' => 'ITEM-0001']);

        $response = $this->sell($item, 2, ['discount_percentage' => 10], [['mode_of_payment' => 'Cash', 'amount' => 5]]);
        $response->assertCreated();

        $invoice = Invoice::firstOrFail();
        $invoice->customer->update(['erpnext_id' => 'Walk-in Customer']);

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/Sales*' => Http::response(['data' => [
                'doctype' => 'Sales Invoice', 'name' => 'ACC-SINV-2026-00009', 'docstatus' => 0,
            ]], 200),
            'erp.example.test/api/method/frappe.client.submit' => Http::response(['message' => [
                'doctype' => 'Sales Invoice', 'name' => 'ACC-SINV-2026-00009', 'docstatus' => 1,
            ]], 200),
        ]);

        $result = app(SyncService::class)->pushInvoices([$invoice->id]);

        $this->assertSame(1, $result['pushed'], implode(' | ', $result['errors']));
        $this->assertSame('synced', $invoice->fresh()->sync_status);
        $this->assertSame('ACC-SINV-2026-00009', $invoice->fresh()->erpnext_id);

        // A REST create leaves the invoice in Draft; it must be submitted so
        // ERPNext gives it an outstanding balance to collect against.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/method/frappe.client.submit')
            && str_contains(json_encode($request->data()), 'ACC-SINV-2026-00009'));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/resource/Sales')) {
                return false;
            }
            $payload = $request->data();
            $line = $payload['items'][0] ?? [];

            return ($payload['company'] ?? null) === 'ACME'
                && ($payload['pos_profile'] ?? null) === 'Test Van POS'
                && ! array_key_exists('pos_total_paid', $payload)
                && abs($line['price_list_rate'] - 1.20) < 0.0001
                && abs($line['rate'] - 1.08) < 0.0001
                && ! array_key_exists('discount_percentage', $line)
                && ! array_key_exists('discount_amount', $line);
        });
    }

    public function test_a_stale_submit_is_retried_once_from_a_fresh_document(): void
    {
        $invoice = $this->invoiceReadyToPush();
        $submits = 0;

        Http::fake(function ($request) use (&$submits) {
            $url = urldecode($request->url());

            if (str_contains($url, 'frappe.client.submit')) {
                $submits++;

                // Frappe's TimestampMismatchError: the saved doc changed under us.
                return $submits === 1
                    ? Http::response([
                        'exc_type' => 'TimestampMismatchError',
                        '_server_messages' => json_encode([json_encode([
                            'message' => 'Sales Invoice ACC-SINV-2026-00009 has been modified after you have opened it. Please reload the document and try again.',
                        ])]),
                    ], 417)
                    : Http::response(['message' => ['docstatus' => 1]]);
            }
            if (str_contains($url, '/api/resource/Sales Invoice/ACC-SINV-2026-00009')) {
                return Http::response(['data' => ['name' => 'ACC-SINV-2026-00009', 'docstatus' => 0]]);
            }
            if (str_contains($url, '/api/resource/Sales')) {
                return Http::response(['data' => ['name' => 'ACC-SINV-2026-00009', 'docstatus' => 0]]);
            }

            return Http::response(['data' => []]);
        });

        $result = app(SyncService::class)->pushInvoices([$invoice->id]);

        $this->assertSame(1, $result['pushed'], implode(' | ', $result['errors']));
        $this->assertSame(2, $submits, 'one retry from the reloaded document');
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '/api/resource/Sales Invoice/ACC-SINV-2026-00009'));
    }

    public function test_a_stock_rejection_is_not_retried_and_keeps_the_draft(): void
    {
        $invoice = $this->invoiceReadyToPush();
        $submits = 0;
        $reloads = 0;

        Http::fake(function ($request) use (&$submits, &$reloads) {
            $url = urldecode($request->url());

            if (str_contains($url, 'frappe.client.submit')) {
                $submits++;

                return Http::response([
                    'exc_type' => 'ValidationError',
                    '_server_messages' => json_encode([json_encode([
                        'message' => '99803.0 units of Item TEST-COLA needed in Warehouse Stores - M to complete this transaction.',
                    ])]),
                ], 417);
            }
            if (preg_match('#/api/resource/Sales Invoice/#', $url)) {
                $reloads++;

                return Http::response(['data' => ['name' => 'ACC-SINV-2026-00009', 'docstatus' => 0]]);
            }
            if (str_contains($url, '/api/resource/Sales')) {
                return Http::response(['data' => ['name' => 'ACC-SINV-2026-00009', 'docstatus' => 0]]);
            }

            return Http::response(['data' => []]);
        });

        $result = app(SyncService::class)->pushInvoices([$invoice->id]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $submits, 'a stock rejection fails identically, so it is not retried');
        $this->assertSame(0, $reloads, 'no pointless reload');
        $this->assertSame('failed', $invoice->fresh()->sync_status);
        $this->assertSame('ACC-SINV-2026-00009', $invoice->fresh()->erpnext_id, 'the draft is remembered for the next push');
        $this->assertStringContainsString('units of Item TEST-COLA', implode(' | ', $result['errors']));
    }

    /** A sale with an ERPNext-mapped customer and item, ready for pushInvoices. */
    protected function invoiceReadyToPush(): Invoice
    {
        $this->profile->update(['erpnext_id' => 'Test Van POS', 'company' => 'ACME']);
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();
        $item->update(['erpnext_id' => 'ITEM-0001']);

        $this->sell($item, 1, [], [['mode_of_payment' => 'Cash', 'amount' => 2]])->assertCreated();

        $invoice = Invoice::orderByDesc('id')->firstOrFail();
        $invoice->customer->update(['erpnext_id' => 'Walk-in Customer']);

        return $invoice;
    }

    public function test_master_pull_accepts_comma_separated_entities_and_reports_counts(): void
    {
        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/UOM*' => Http::response(['data' => [
                ['name' => 'Nos'],
            ]], 200),
            'erp.example.test/api/resource/Item%20Group*' => Http::response(['data' => [
                ['name' => 'Beverages', 'item_group_name' => 'Beverages'],
            ]], 200),
        ]);

        $admin = User::where('email', 'admin@pos.test')->firstOrFail();

        $response = $this->actingAs($admin)->post('/sync/masters', [
            'entities' => ['uom,item_group'],
            'limit' => 50,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertStringContainsString('uom: 1 pulled, 1 upserted', session('success'));
        $this->assertStringContainsString('item_group: 1 pulled, 1 upserted', session('success'));
    }
}
