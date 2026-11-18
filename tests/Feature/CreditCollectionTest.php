<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CreditInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\CreditService;
use App\Services\SyncService;
use App\Support\DocNaming;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Credit sales, collections (any van), ERPNext pull/push of them, van-aware numbering.
 * ERPNext is faked — nothing here talks to a real server.
 */
class CreditCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected array $headers;

    protected int $profileId;

    protected Customer $customer;

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

        $this->customer = Customer::create(['customer_code' => 'C-1', 'customer_name' => 'Aung Shop', 'erpnext_id' => 'Aung Shop']);

        Setting::current()->forceFill([
            'erpnext_url' => 'https://erp.test', 'erpnext_api_key' => 'k', 'erpnext_api_secret' => 's',
            'erpnext_naming_series' => 'ACC-SINV-.YYYY.-',
        ])->save();
        Setting::flushCache();
    }

    protected function creditSale(float $paid = 2.0, ?int $customerId = null)
    {
        return $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'customer_id' => $customerId ?? $this->customer->id,
            'items' => [['item_id' => Item::where('item_code', 'ITEM-0001')->value('id'), 'qty' => 4]], // 4.80
            'payments' => $paid > 0 ? [['mode_of_payment' => 'Cash', 'amount' => $paid]] : [],
        ], $this->headers);
    }

    protected function web()
    {
        return $this->actingAs(User::where('email', 'admin@pos.test')->first())
            ->withSession(['pos_profile_id' => $this->profileId]);
    }

    protected function credit(): CreditInvoice
    {
        // The demo seed now ships credit invoices for its own customers; this
        // helper must only ever return the invoice the test just created.
        return CreditInvoice::where('customer_id', $this->customer->id)->firstOrFail();
    }

    public function test_credit_sale_is_tracked_and_numbered_with_the_van_id(): void
    {
        $this->creditSale(2.0)->assertCreated();

        $van = PosProfile::find($this->profileId)->ensureVanCode();
        $invoice = Invoice::firstOrFail();
        $this->assertStringContainsString("-{$van}-".now()->year.'-', $invoice->invoice_no);
        $this->assertSame($van, $invoice->van_code);
        $this->assertEqualsWithDelta(2.80, $this->credit()->outstanding, 0.005);
    }

    public function test_credit_row_keeps_the_due_date_chosen_at_the_register(): void
    {
        $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'customer_id' => $this->customer->id,
            'is_credit' => true,
            'credit_days' => 14,
            'items' => [['item_id' => Item::where('item_code', 'ITEM-0001')->value('id'), 'qty' => 4]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 2]],
        ], $this->headers)->assertCreated();

        $invoice = Invoice::firstOrFail();
        $this->assertTrue($invoice->due_date->isSameDay(now()->addDays(14)), 'invoice keeps the 14-day terms');
        $this->assertTrue($this->credit()->due_date->isSameDay($invoice->due_date), 'credit row follows the invoice, not the 30-day default');
    }

    public function test_sale_with_collections_reports_a_cancel_blocker(): void
    {
        $this->creditSale(2.0)->assertCreated();
        $this->web()->postJson("/credit/{$this->credit()->id}/collect", ['amount' => 1, 'mode_of_payment' => 'Cash'])->assertCreated();

        $blocker = app(\App\Services\InvoiceService::class)
            ->cancellationBlocker(Invoice::firstOrFail(), User::where('email', 'admin@pos.test')->first());
        $this->assertStringContainsString('collected', (string) $blocker);
    }

    public function test_credit_limit_is_enforced(): void
    {
        $this->customer->update(['credit_limit' => 1.00]);
        $this->creditSale(2.0)->assertUnprocessable(); // would owe 2.80 > 1.00

        $this->customer->update(['credit_limit' => 10.00]);
        $this->creditSale(2.0)->assertCreated();
    }

    public function test_collect_partial_then_rest_and_reject_over_collection(): void
    {
        $this->creditSale(2.0)->assertCreated();
        $credit = $this->credit();

        $this->web()->postJson("/credit/{$credit->id}/collect", ['amount' => 1.00, 'mode_of_payment' => 'Cash'])->assertCreated();
        $this->assertEqualsWithDelta(1.80, $credit->fresh()->outstanding, 0.005);

        $this->web()->postJson("/credit/{$credit->id}/collect", ['amount' => 5, 'mode_of_payment' => 'Cash'])->assertUnprocessable();

        $this->web()->postJson("/credit/{$credit->id}/collect", ['amount' => 1.80, 'mode_of_payment' => 'Cash'])->assertCreated();
        $this->assertSame('settled', $credit->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, Invoice::firstOrFail()->status);
        $this->assertEqualsWithDelta(0.0, Invoice::firstOrFail()->outstanding_amount, 0.005);
        $this->assertStringContainsString('COL-', Collection::first()->collection_no);
    }

    public function test_collect_is_idempotent_on_offline_id(): void
    {
        $this->creditSale(2.0)->assertCreated();
        $credit = $this->credit();
        $body = ['amount' => 1.00, 'mode_of_payment' => 'Cash', 'offline_id' => 'dup-1'];

        $this->web()->postJson("/credit/{$credit->id}/collect", $body)->assertCreated();
        $this->web()->postJson("/credit/{$credit->id}/collect", $body)->assertCreated();

        $this->assertSame(1, Collection::count());
        $this->assertEqualsWithDelta(1.80, $credit->fresh()->outstanding, 0.005);
    }

    public function test_customer_summary_feeds_the_register_alert(): void
    {
        $this->creditSale(2.0)->assertCreated();

        $res = $this->web()->getJson("/credit/customer/{$this->customer->id}")->assertOk();
        $this->assertSame(1, $res->json('data.count'));
        $this->assertEqualsWithDelta(2.80, $res->json('data.total'), 0.005);
    }

    public function test_demo_seed_ships_credit_invoices_for_the_collect_screens(): void
    {
        // DemoSeeder runs in setUp: three open invoices (V1 overdue, V2 due soon, MP part-paid).
        $samples = CreditInvoice::open()->whereNull('invoice_id')->get();
        $this->assertGreaterThanOrEqual(3, $samples->count());

        $overdue = $samples->first(fn (CreditInvoice $c) => $c->isOverdue());
        $this->assertNotNull($overdue, 'the demo seed ships an overdue invoice so the flag can be seen');
        $this->assertSame('V1', $overdue->van_code);
        $this->assertNotNull($samples->firstWhere('van_code', 'V2'));

        $this->web()->get('/credit')->assertOk()
            ->assertSee($overdue->invoice_no)
            ->assertSee('Su Su Hlaing');

        // Register alert: the demo customer's balance is reported, not blocked.
        $customer = Customer::where('customer_name', 'Su Su Hlaing')->firstOrFail();
        $this->web()->getJson("/credit/customer/{$customer->id}")
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.overdue', 0);
    }

    protected function fakeErp(array $unpaid, array $extra = []): void
    {
        Http::fake(function ($request) use ($unpaid, $extra) {
            $url = urldecode($request->url());
            foreach ($extra as $needle => $respond) {
                if (str_contains($url, $needle)) {
                    return Http::response($respond);
                }
            }
            if (str_contains($url, '/api/method/ping')) {
                return Http::response(['message' => 'pong']);
            }
            if (str_contains($url, '/api/resource/Sales Invoice/')) {
                return Http::response(['data' => ['items' => [['item_code' => 'X', 'item_name' => 'Cola', 'qty' => 2, 'uom' => 'Nos', 'rate' => 5, 'amount' => 10]]]]);
            }
            if (str_contains($url, '/api/resource/Sales Invoice')) {
                return Http::response(['data' => $unpaid]);
            }

            return Http::response(['data' => []]);
        });
    }

    protected function otherVanInvoice(float $outstanding = 10.0): array
    {
        return [
            'name' => 'ACC-SINV-VAN1-2026-00007', 'customer' => 'Aung Shop', 'customer_name' => 'Aung Shop',
            'posting_date' => '2026-11-10', 'due_date' => '2026-12-10', 'currency' => 'USD',
            'grand_total' => 10.0, 'outstanding_amount' => $outstanding, 'pos_profile' => 'Van 1 POS', 'modified' => '2026-11-10 10:00:00',
        ];
    }

    public function test_unpaid_invoice_of_another_van_is_pulled_and_collectable_here(): void
    {
        PosProfile::create(array_merge(
            PosProfile::where('id', $this->profileId)->first()->only(['company', 'currency']),
            ['name' => 'Van 1 POS', 'erpnext_id' => 'Van 1 POS', 'van_code' => 'VAN1']
        ));
        $this->fakeErp([$this->otherVanInvoice()]);

        $result = app(SyncService::class)->pullMasters(['credit_invoice']);
        $this->assertSame([], $result['errors']);

        $credit = $this->credit();
        $this->assertSame('VAN1', $credit->van_code);
        $this->assertSame('erpnext', $credit->origin);
        $this->assertEqualsWithDelta(10.0, $credit->outstanding, 0.005);
        $this->assertSame('Cola', $credit->items[0]['item_name']);

        // Visible to this van's salesman and collectable.
        $this->web()->getJson("/credit/customer/{$this->customer->id}")->assertJsonPath('data.count', 1);
        $this->web()->postJson("/credit/{$credit->id}/collect", ['amount' => 10, 'mode_of_payment' => 'Cash'])->assertCreated();
        $this->assertSame('settled', $credit->fresh()->status);
    }

    public function test_invoice_paid_elsewhere_drops_out_on_next_pull(): void
    {
        $unpaid = [$this->otherVanInvoice()];
        Http::fake(function ($request) use (&$unpaid) {
            $url = urldecode($request->url());
            if (str_contains($url, '/api/method/ping')) {
                return Http::response(['message' => 'pong']);
            }
            if (str_contains($url, '/api/resource/Sales Invoice/')) {
                return Http::response(['data' => ['items' => [['item_code' => 'X', 'item_name' => 'Cola', 'qty' => 2, 'uom' => 'Nos', 'rate' => 5, 'amount' => 10]]]]);
            }
            if (str_contains($url, '/api/resource/Sales Invoice')) {
                return Http::response(['data' => $unpaid]);
            }

            return Http::response(['data' => []]);
        });

        app(SyncService::class)->pullMasters(['credit_invoice']);
        $this->assertSame('open', $this->credit()->status);

        $unpaid = []; // ERPNext no longer lists it
        app(SyncService::class)->pullMasters(['credit_invoice']);
        $this->assertSame('settled', $this->credit()->fresh()->status);
    }

    public function test_collection_is_pushed_as_payment_entry(): void
    {
        $this->fakeErp([$this->otherVanInvoice()], [
            'get_payment_entry' => ['message' => ['company' => 'Test Co', 'references' => [['allocated_amount' => 10]]]],
            '/api/resource/Payment Entry' => ['data' => ['name' => 'ACC-PAY-2026-00001', 'docstatus' => 1]],
        ]);
        app(SyncService::class)->pullMasters(['credit_invoice']);
        $credit = $this->credit();
        $this->web()->postJson("/credit/{$credit->id}/collect", ['amount' => 4, 'mode_of_payment' => 'Cash'])->assertCreated();

        $out = app(SyncService::class)->pushCollections();

        $this->assertSame(1, $out['pushed']);
        $c = Collection::firstOrFail();
        $this->assertSame('synced', $c->sync_status);
        $this->assertSame('ACC-PAY-2026-00001', $c->erpnext_id);
        $this->assertEqualsWithDelta(6.0, $credit->fresh()->erp_outstanding, 0.005); // 10 − 4, not subtracted twice
        $this->assertEqualsWithDelta(6.0, $credit->fresh()->outstanding, 0.005);
    }

    public function test_rejected_collection_is_marked_failed_and_can_be_discarded(): void
    {
        $reject = false;
        Http::fake(function ($request) use (&$reject) {
            $u = urldecode($request->url());
            if ($reject && str_contains($u, 'get_payment_entry')) {
                return Http::response(['exc_type' => 'ValidationError', '_server_messages' => json_encode([json_encode(['message' => 'Invoice already paid'])])], 417);
            }
            if (str_contains($u, '/api/method/ping')) {
                return Http::response(['message' => 'pong']);
            }
            if (str_contains($u, '/api/resource/Sales Invoice/')) {
                return Http::response(['data' => ['items' => [['item_code' => 'X', 'item_name' => 'Cola', 'qty' => 2, 'uom' => 'Nos', 'rate' => 5, 'amount' => 10]]]]);
            }
            if (str_contains($u, '/api/resource/Sales Invoice')) {
                return Http::response(['data' => [$this->otherVanInvoice()]]);
            }

            return Http::response(['data' => []]);
        });

        app(SyncService::class)->pullMasters(['credit_invoice']);
        $credit = $this->credit();
        $this->web()->postJson("/credit/{$credit->id}/collect", ['amount' => 10, 'mode_of_payment' => 'Cash'])->assertCreated();

        $reject = true;
        $out = app(SyncService::class)->pushCollections();

        $this->assertSame(1, $out['failed']);
        $c = Collection::firstOrFail();
        $this->assertSame('failed', $c->sync_status);
        $this->assertStringContainsString('already paid', $c->sync_error);

        app(CreditService::class)->discard($c);
        $this->assertSame('open', $credit->fresh()->status);
        $this->assertEqualsWithDelta(10.0, $credit->fresh()->outstanding, 0.005);
    }

    public function test_van_numbers_differ_between_vans_and_follow_the_erpnext_series(): void
    {
        $this->assertSame('ACC-SINV-TVP-2026-00001', DocNaming::number('ACC-SINV-', 'TVP', '', 2026, 1));
        $this->assertSame('ACC-SINV-V2-2026-00001', DocNaming::number('ACC-SINV-', 'V2', '', 2026, 1));
        $this->assertSame('ACC-SINV-TVP-.YYYY.-', DocNaming::vanSeries('ACC-SINV-.YYYY.-', 'TVP'));
        $this->assertLessThanOrEqual(32, strlen(DocNaming::number('ACC-SINV-', 'ABCDEFGH', 'CR', 2026, 99999)));
    }
}
