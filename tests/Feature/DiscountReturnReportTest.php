<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Item;
use App\Models\PosProfile;
use App\Models\User;
use App\Services\ReportService;
use App\Support\XlsxWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice-level ("total") discount, sales returns priced from the original
 * sale, and the daily report (line detail, inventory movement, Excel export).
 */
class DiscountReturnReportTest extends TestCase
{
    use RefreshDatabase;

    protected array $headers;

    protected int $profileId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'admin@pos.test', 'password' => 'password']);
        $this->headers = ['Authorization' => 'Bearer '.$login->json('data.token')];
        $this->profileId = PosProfile::where('name', 'Main POS')->value('id');

        $this->postJson('/api/v1/shifts/open', [
            'pos_profile_id' => $this->profileId,
            'balances' => [['mode_of_payment' => 'Cash', 'amount' => 100]],
        ], $this->headers)->assertCreated();
    }

    protected function cola(): Item
    {
        return Item::where('item_code', 'ITEM-0001')->first(); // 1.20 each
    }

    protected function sell(array $extra = [], float $qty = 4): array
    {
        $response = $this->postJson('/api/v1/invoices', array_merge([
            'pos_profile_id' => $this->profileId,
            'items' => [['item_id' => $this->cola()->id, 'qty' => $qty]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 50]],
        ], $extra), $this->headers);
        $response->assertCreated();

        return $response->json('data');
    }

    protected function webAsAdmin()
    {
        return $this->actingAs(User::where('email', 'admin@pos.test')->first())
            ->withSession(['pos_profile_id' => $this->profileId]);
    }

    public function test_total_discount_percentage_reduces_the_invoice(): void
    {
        $inv = $this->sell(['additional_discount_percentage' => 10], qty: 10); // 12.00 − 10%

        $this->assertEqualsWithDelta(10.80, $inv['grand_total'], 0.005);
        $this->assertEqualsWithDelta(1.20, $inv['additional_discount_amount'], 0.005);
        $this->assertEqualsWithDelta(10.0, $inv['additional_discount_percentage'], 0.001);
    }

    public function test_total_discount_amount_is_capped_at_the_invoice_value(): void
    {
        // Unlimited profile: this test is about clamping the amount to the total.
        PosProfile::whereKey($this->profileId)->update(['max_discount_percentage' => 0]);

        $inv = $this->sell(['additional_discount_amount' => 500], qty: 1);

        $this->assertEqualsWithDelta(0.0, $inv['grand_total'], 0.005);
        $this->assertEqualsWithDelta(1.20, $inv['additional_discount_amount'], 0.005);
    }

    public function test_total_discount_respects_the_profile_limit(): void
    {
        PosProfile::whereKey($this->profileId)->update(['max_discount_percentage' => 5]);

        $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'additional_discount_percentage' => 20,
            'items' => [['item_id' => $this->cola()->id, 'qty' => 1]],
        ], $this->headers)->assertUnprocessable();
    }

    public function test_return_refunds_the_price_actually_paid_and_restores_stock(): void
    {
        // Let the sale carry a 25% total discount (seeded cap is 20%).
        PosProfile::whereKey($this->profileId)->update(['max_discount_percentage' => 0]);

        $stockBefore = $this->cola()->stockQty();
        $sale = $this->sell(['additional_discount_percentage' => 25]); // 4 × 1.20 = 4.80 → 3.60
        $this->assertEqualsWithDelta(3.60, $sale['grand_total'], 0.005);
        $this->assertEquals($stockBefore - 4, $this->cola()->fresh()->stockQty());

        $return = $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'is_return' => true,
            'return_against_id' => $sale['id'],
            'items' => [['item_id' => $this->cola()->id, 'qty' => 2]],
        ], $this->headers);
        $return->assertCreated();

        // 2 × 1.20 × 0.75 = 1.80 refunded, not 2.40 at today's list price.
        $this->assertEqualsWithDelta(-1.80, $return->json('data.grand_total'), 0.005);
        $this->assertEquals($stockBefore - 2, $this->cola()->fresh()->stockQty());
        $this->assertNotEquals('returned', Invoice::find($sale['id'])->status, 'partial return keeps the sale open');
    }

    public function test_return_cannot_exceed_what_was_sold(): void
    {
        $sale = $this->sell();
        $payload = fn ($qty) => [
            'pos_profile_id' => $this->profileId,
            'is_return' => true,
            'return_against_id' => $sale['id'],
            'items' => [['item_id' => $this->cola()->id, 'qty' => $qty]],
        ];

        $this->postJson('/api/v1/invoices', $payload(3), $this->headers)->assertCreated();
        $this->postJson('/api/v1/invoices', $payload(2), $this->headers)->assertUnprocessable(); // only 1 left
        $this->postJson('/api/v1/invoices', $payload(1), $this->headers)->assertCreated();

        $this->assertEquals('returned', Invoice::find($sale['id'])->status);
    }

    public function test_web_return_form_and_submit(): void
    {
        $sale = $this->sell();

        $this->webAsAdmin()->get("/invoices/{$sale['id']}/return")
            ->assertOk()->assertSee('Sales return')->assertSee('Process return');

        $this->webAsAdmin()->post("/invoices/{$sale['id']}/return", [
            'items' => [['item_id' => $this->cola()->id, 'qty' => 1]],
            'refund_mode' => 'Cash',
            'refund_amount' => 1.20,
            'reason' => 'Defective',
        ])->assertRedirect();

        $return = Invoice::where('return_against_id', $sale['id'])->first();
        $this->assertNotNull($return);
        $this->assertEqualsWithDelta(-1.20, $return->grand_total, 0.005);
        $this->assertEqualsWithDelta(-1.20, (float) $return->payments()->sum('amount'), 0.005);
    }

    public function test_web_return_requires_a_quantity(): void
    {
        $sale = $this->sell();

        $this->webAsAdmin()->post("/invoices/{$sale['id']}/return", [
            'items' => [['item_id' => $this->cola()->id, 'qty' => 0]],
        ])->assertSessionHasErrors('items');
    }

    public function test_daily_report_shows_lines_and_inventory_movement(): void
    {
        $this->sell(qty: 4);

        $this->webAsAdmin()->get('/dashboard/daily?tab=lines')
            ->assertOk()->assertSee($this->cola()->item_name);
        $this->webAsAdmin()->get('/dashboard/daily?tab=stock&warehouse=all')
            ->assertOk()->assertSee('Opening')->assertSee('Stock in')->assertSee('Stock out')->assertSee('Closing');

        $report = app(ReportService::class)->stockMovement(now()->toDateString(), now()->toDateString());
        $row = collect($report['rows'])->firstWhere('item_code', 'ITEM-0001');
        $this->assertEquals(4.0, $row['out']);
        $this->assertEquals(4.0, $row['sold_out']);
        $this->assertEqualsWithDelta($row['opening'] - 4, $row['closing'], 0.0001);
    }

    public function test_inventory_movement_balances_for_a_past_range(): void
    {
        $this->sell(qty: 4);
        $yesterday = now()->subDay()->toDateString();

        // Nothing moved yesterday: closing = opening = balance before today's sale.
        $report = app(ReportService::class)->stockMovement($yesterday, $yesterday);
        $row = collect($report['rows'])->firstWhere('item_code', 'ITEM-0001');
        $this->assertEquals(0.0, $row['out']);
        $this->assertEqualsWithDelta($this->cola()->stockQty() + 4, $row['closing'], 0.0001);
    }

    public function test_invoice_and_receipt_show_the_total_discount(): void
    {
        // Seeded cap is 20%; 10% keeps this inside it.
        $sale = $this->sell(['additional_discount_percentage' => 10], qty: 2); // 2.40 − 10% = 2.16

        $this->webAsAdmin()->get("/invoices/{$sale['id']}")
            ->assertOk()->assertSee('Total discount')->assertSee('2.16');

        $this->webAsAdmin()->get("/pos/receipt/{$sale['id']}")
            ->assertOk()->assertSee('Total discount');
    }

    public function test_excel_export_is_manager_only_and_a_valid_workbook(): void
    {
        $this->sell(qty: 2);

        $response = $this->webAsAdmin()->get('/dashboard/daily/export?warehouse=all');
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        $this->assertSame('PK', substr($response->streamedContent(), 0, 2), 'xlsx is a zip container');

        $cashier = User::where('email', 'cashier@pos.test')->first();
        $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $cashier->posProfiles()->first()->id])
            ->get('/dashboard/daily/export')->assertForbidden();
    }

    public function test_xlsx_writer_round_trips_numbers_and_escapes_text(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        (new XlsxWriter)
            ->addSheet('Data', [['Name'], ['Qty', 'qty'], ['Amount', 'money']], [['A & B <c>', 2.5, 10.0]], 'Title')
            ->save($path);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('A &amp; B &lt;c&gt;', $sheet);
        $this->assertStringContainsString('<v>2.5</v>', $sheet);
        $zip->close();
        @unlink($path);
    }
}
