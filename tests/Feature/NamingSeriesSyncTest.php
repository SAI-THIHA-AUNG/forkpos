<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\PosProfileSync;
use App\Support\Money;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Invoice numbering is owned by ERPNext: the prefix comes from the naming
 * series of the Sales Invoice (fallback: POS Invoice) DocType, and the local
 * invoice number keeps that prefix's shape.
 */
class NamingSeriesSyncTest extends TestCase
{
    use RefreshDatabase;

    protected array $headers;

    protected int $profileId;

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

        $this->profileId = PosProfile::where('name', 'Main POS')->value('id');
    }

    protected function posDoc(): array
    {
        return [
            'name' => 'Test Van POS',
            'company' => 'My Company',
            'currency' => 'MMK',
            'selling_price_list' => 'Standard Selling',
            'warehouse' => null,
            'payments' => [['mode_of_payment' => 'Cash', 'default' => 1]],
        ];
    }

    protected function docTypeSeries(string $doctype, string $series)
    {
        return Http::response([
            'data' => [
                'name' => $doctype,
                'fields' => [
                    ['fieldname' => 'naming_series', 'options' => $series],
                ],
            ],
        ], 200);
    }

    protected function apiLoginAndOpenShift(): array
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'admin@pos.test', 'password' => 'password']);
        $headers = ['Authorization' => 'Bearer '.$login->json('data.token')];

        $this->postJson('/api/v1/shifts/open', [
            'pos_profile_id' => $this->profileId,
            'balances' => [['mode_of_payment' => 'Cash', 'amount' => 100]],
        ], $headers)->assertCreated();

        return $headers;
    }

    public function test_invoice_series_and_prefix_are_mirrored_from_sales_invoice_doctype(): void
    {
        Http::fake([
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response(['data' => $this->posDoc()], 200),
            'erp.example.test/api/resource/DocType/Sales%20Invoice' => $this->docTypeSeries('Sales Invoice', "ACC-SINV-.YYYY.-\nSINV-.YYYY.-"),
            'erp.example.test/api/resource/Currency/MMK' => Http::response(['data' => ['symbol' => 'K', 'number_format' => '#,###.##']], 200),
        ]);

        app(PosProfileSync::class)->syncOne('Test Van POS');

        $setting = Setting::current();
        $this->assertSame('ACC-SINV-.YYYY.-', $setting->invoice_series);
        $this->assertSame('ACC-SINV-', $setting->invoice_prefix);
    }

    public function test_falls_back_to_pos_invoice_when_sales_invoice_doctype_is_unreadable(): void
    {
        Http::fake([
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response(['data' => $this->posDoc()], 200),
            'erp.example.test/api/resource/DocType/Sales%20Invoice' => Http::response([], 404),
            'erp.example.test/api/resource/DocType/POS%20Invoice' => $this->docTypeSeries('POS Invoice', 'ACC-PSINV-.YYYY.-'),
        ]);

        app(PosProfileSync::class)->syncOne('Test Van POS');

        $setting = Setting::current();
        $this->assertSame('ACC-PSINV-.YYYY.-', $setting->invoice_series);
        $this->assertSame('ACC-PSINV-', $setting->invoice_prefix);
    }

    public function test_falls_back_to_latest_document_naming_series_when_doctype_metadata_is_unreadable(): void
    {
        Http::fake([
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response(['data' => $this->posDoc()], 200),
            'erp.example.test/api/resource/DocType/Sales%20Invoice' => Http::response([], 404),
            'erp.example.test/api/resource/Sales%20Invoice*' => Http::response([
                'data' => [['name' => 'ACC-SINV-2026-00002', 'naming_series' => 'ACC-SINV-.YYYY.-']],
            ], 200),
        ]);

        app(PosProfileSync::class)->syncOne('Test Van POS');

        $setting = Setting::current();
        $this->assertSame('ACC-SINV-.YYYY.-', $setting->invoice_series);
        $this->assertSame('ACC-SINV-', $setting->invoice_prefix);
    }

    public function test_next_invoice_number_uses_the_synced_prefix(): void
    {
        $setting = Setting::current();
        $setting->forceFill([
            'invoice_series' => 'ACC-SINV-.YYYY.-',
            'invoice_prefix' => 'ACC-SINV-',
            'next_invoice_number' => 1,
        ])->save();
        Setting::flushCache();

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@pos.test', 'password' => 'password']);
        $headers = $this->apiLoginAndOpenShift();

        $item = Item::where('item_code', 'ITEM-0001')->first();

        $response = $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'items' => [['item_id' => $item->id, 'qty' => 1]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 50]],
        ], $headers);

        $response->assertCreated();
        $this->assertSame('ACC-SINV-2026-00001', $response->json('data.invoice_no'));
    }

    public function test_settings_page_shows_series_and_does_not_let_prefix_be_edited(): void
    {
        $setting = Setting::current();
        $setting->forceFill([
            'invoice_series' => 'ACC-SINV-.YYYY.-',
            'invoice_prefix' => 'ACC-SINV-',
        ])->save();
        Setting::flushCache();

        $admin = User::where('email', 'admin@pos.test')->first();

        $this->actingAs($admin)
            ->withSession(['pos_profile_id' => $this->profileId])
            ->get('/settings')
            ->assertOk()
            ->assertSee('ACC-SINV-.YYYY.-')
            ->assertSee('ACC-SINV-')
            ->assertDontSee('name="invoice_prefix"');
    }

    public function test_receipt_trims_forced_decimals(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@pos.test', 'password' => 'password']);
        $headers = $this->apiLoginAndOpenShift();

        $item = Item::where('item_code', 'ITEM-0001')->first();

        // 100 x 1.20 = 120.00 — displayed as "120", not "120.00".
        $response = $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'items' => [['item_id' => $item->id, 'qty' => 100]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 500]],
        ], $headers);

        $invoiceId = $response->json('data.id');
        $grandTotal = $response->json('data.grand_total');
        $this->assertEqualsWithDelta(120.0, (float) $grandTotal, 0.005);
        $this->assertSame('120', Money::format($grandTotal, 2));

        $admin = User::where('email', 'admin@pos.test')->first();
        $this->actingAs($admin)
            ->withSession(['pos_profile_id' => $this->profileId])
            ->get("/pos/receipt/{$invoiceId}")
            ->assertOk()
            ->assertSee('120')
            ->assertDontSee('120.00');
    }
}
