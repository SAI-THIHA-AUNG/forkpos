<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Item;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\SyncService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ERPNext rejects Sales Taxes and Charges rows without an Account Head
 * ("Value missing for: Account Head", HTTP 417). The tax sync must capture
 * the account even from a mixed-charge-type template, and the invoice push
 * must resolve — or heal — the account instead of sending a row ERPNext will
 * bounce with an opaque 417.
 */
class TaxAccountHeadPushTest extends TestCase
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

    /** Sell one line taxed with the given tax so the invoice carries a taxes row. */
    protected function sellWithTax(Tax $tax): Invoice
    {
        $item = Item::where('item_code', 'ITEM-0001')->firstOrFail();
        $item->update(['erpnext_id' => 'ITEM-0001']);
        $this->profile->update(['erpnext_id' => 'Test Van POS', 'company' => 'ACME']);

        $response = $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profile->id,
            'items' => [['item_id' => $item->id, 'qty' => 1, 'tax_id' => $tax->id]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 10]],
        ], $this->authHeaders());
        $response->assertCreated();

        $invoice = Invoice::orderByDesc('id')->firstOrFail();
        $invoice->customer->update(['erpnext_id' => 'Walk-in Customer']);

        return $invoice;
    }

    public function test_push_fails_before_creating_the_invoice_when_no_account_head_can_be_resolved(): void
    {
        // Demo tax: no account_name and no ERPNext template link — the field
        // failure mode that produced the user's HTTP 417.
        $tax = Tax::where('name', 'Commercial Tax 5%')->firstOrFail();
        $invoice = $this->sellWithTax($tax);

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
        ]);

        $result = app(SyncService::class)->pushInvoices([$invoice->id]);

        $this->assertSame(1, $result['failed']);
        $this->assertSame('failed', $invoice->fresh()->sync_status);
        $this->assertNull($invoice->fresh()->erpnext_id, 'nothing was created in ERPNext');
        $this->assertStringContainsString('no Account Head', implode(' | ', $result['errors']));
        Http::assertNothingSent();
    }

    public function test_push_heals_a_missing_account_head_from_the_erpnext_template(): void
    {
        $tax = Tax::where('name', 'Commercial Tax 5%')->firstOrFail();
        $tax->update(['erpnext_id' => 'Myanmar Commercial Tax']); // template link, account still null
        $invoice = $this->sellWithTax($tax);

        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/Sales%20Taxes%20and%20Charges%20Template/Myanmar%20Commercial%20Tax' => Http::response(['data' => [
                'name' => 'Myanmar Commercial Tax',
                'taxes' => [
                    ['charge_type' => 'On Net Total', 'account_head' => 'Commercial Tax - ACME', 'rate' => 5, 'included_in_print_rate' => 1],
                ],
            ]], 200),
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
        $this->assertSame('Commercial Tax - ACME', $tax->fresh()->account_name, 'the healed account is remembered for the next push');

        Http::assertSent(function ($request) {
            if (! str_contains(urldecode($request->url()), '/api/resource/Sales Invoice')) {
                return false;
            }
            $rows = $request->data()['taxes'] ?? [];

            return ($rows[0]['account_head'] ?? null) === 'Commercial Tax - ACME'
                && ($rows[0]['charge_type'] ?? null) === 'On Net Total';
        });
    }

    public function test_pull_tax_captures_the_account_head_from_a_mixed_charge_type_template(): void
    {
        Http::fake([
            'erp.example.test/api/method/ping' => Http::response(['message' => 'pong'], 200),
            'erp.example.test/api/resource/Sales%20Taxes%20and%20Charges%20Template' => Http::response(['data' => [
                ['name' => 'Myanmar Commercial Tax', 'title' => 'Myanmar Commercial Tax', 'is_default' => 0],
            ]], 200),
            'erp.example.test/api/resource/Sales%20Taxes%20and%20Charges%20Template/Myanmar%20Commercial%20Tax' => Http::response(['data' => [
                'name' => 'Myanmar Commercial Tax',
                'title' => 'Myanmar Commercial Tax',
                'is_default' => 0,
                'taxes' => [
                    // Rate row built without an account head (the gap that left
                    // account_name null and caused the 417) ...
                    ['charge_type' => 'On Net Total', 'account_head' => '', 'rate' => 5, 'description' => 'Commercial Tax', 'included_in_print_rate' => 1],
                    // ... but a sibling row carries one, so the sync must fall back to it.
                    ['charge_type' => 'Actual', 'account_head' => 'Commercial Tax Payable - ACME', 'rate' => 0],
                ],
            ]], 200),
        ]);

        $result = app(SyncService::class)->pullMasters(['tax'], 50);

        $this->assertSame(1, $result['results']['tax']['upserted'] ?? 0);

        $tax = Tax::where('erpnext_id', 'Myanmar Commercial Tax')->firstOrFail();
        $this->assertSame('Commercial Tax Payable - ACME', $tax->account_name, 'the fallback account head is captured');
        $this->assertEqualsWithDelta(5.0, (float) $tax->rate, 0.0001, 'the rate still comes from the On Net Total row');
        $this->assertSame('Commercial Tax', $tax->description);
    }
}
