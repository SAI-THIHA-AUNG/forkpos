<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\PosProfile;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credit sales: a customer takes goods now and pays later (7 / 14 / 30 days or
 * a chosen date), optionally with a down payment in cash or a digital mode.
 */
class CreditSaleTest extends TestCase
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

    protected function customerId(): int
    {
        return Customer::where('is_walk_in', false)->value('id');
    }

    protected function creditSale(array $extra = [])
    {
        return $this->postJson('/api/v1/invoices', array_merge([
            'pos_profile_id' => $this->profileId,
            'customer_id' => $this->customerId(),
            'is_credit' => true,
            'items' => [['item_id' => Item::where('item_code', 'ITEM-0001')->value('id'), 'qty' => 10]], // 12.00
            'payments' => [],
        ], $extra), $this->headers);
    }

    public function test_credit_sale_with_a_fixed_term_sets_the_due_date_and_leaves_the_whole_total_unpaid(): void
    {
        foreach ([7, 14, 30] as $days) {
            $data = $this->creditSale(['credit_days' => $days])->assertCreated()->json('data');

            $this->assertTrue($data['is_credit']);
            $this->assertSame($days, $data['credit_days']);
            $this->assertSame(now()->addDays($days)->toDateString(), $data['due_date']);
            $this->assertSame('unpaid', $data['status']);
            $this->assertEqualsWithDelta(12.0, $data['outstanding_amount'], 0.005);
        }
    }

    public function test_credit_sale_accepts_a_picked_due_date(): void
    {
        $due = now()->addDays(10)->toDateString();

        $data = $this->creditSale(['due_date' => $due])->assertCreated()->json('data');

        $this->assertSame($due, $data['due_date']);
        $this->assertNull($data['credit_days']);
    }

    public function test_down_payment_in_a_digital_mode_makes_the_sale_partly_paid(): void
    {
        $data = $this->creditSale([
            'credit_days' => 14,
            'payments' => [['mode_of_payment' => 'Mobile Wallet', 'amount' => 5, 'reference_no' => 'TXN-1']],
        ])->assertCreated()->json('data');

        $this->assertSame('partly_paid', $data['status']);
        $this->assertEqualsWithDelta(7.0, $data['outstanding_amount'], 0.005);
        $this->assertSame('TXN-1', $data['payments'][0]['reference_no']);
    }

    public function test_credit_needs_terms_a_valid_term_and_a_future_date(): void
    {
        $this->creditSale()->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->creditSale(['credit_days' => 5])->assertUnprocessable()->assertJsonValidationErrors('credit_days');
        $this->creditSale(['due_date' => now()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
    }

    public function test_credit_is_refused_for_the_walk_in_customer(): void
    {
        $this->creditSale(['credit_days' => 7, 'customer_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->assertSame(0, Invoice::count());
    }

    public function test_credit_is_refused_when_the_payments_already_cover_the_total(): void
    {
        $this->creditSale([
            'credit_days' => 7,
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 20]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payments');

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_credit_balance_is_overdue_only_after_the_due_date(): void
    {
        $this->creditSale(['credit_days' => 7])->assertCreated();
        $invoice = Invoice::first();

        $this->assertFalse($invoice->isOverdue());

        $invoice->update(['due_date' => now()->subDay()->toDateString()]);
        $this->assertTrue($invoice->fresh()->isOverdue());
    }

    public function test_web_checkout_stores_the_credit_terms_and_the_receipt_shows_them(): void
    {
        $admin = User::where('email', 'admin@pos.test')->first();

        $response = $this->actingAs($admin)
            ->withSession(['pos_profile_id' => $this->profileId])
            ->postJson('/pos/invoice', [
                'customer_id' => $this->customerId(),
                'is_credit' => true,
                'credit_days' => 30,
                'items' => [['item_id' => Item::where('item_code', 'ITEM-0001')->value('id'), 'qty' => 2]],
                'payments' => [],
            ])->assertCreated();

        $invoice = Invoice::findOrFail($response->json('data.invoice_id'));
        $this->assertSame(now()->addDays(30)->toDateString(), $invoice->due_date->toDateString());

        $this->actingAs($admin)
            ->withSession(['pos_profile_id' => $this->profileId])
            ->get(route('pos.receipt', $invoice))
            ->assertOk()
            ->assertSee('CREDIT SALE')
            ->assertSee('Payment due')
            ->assertSee($invoice->due_date->format('Y-m-d'));
    }

    public function test_the_credit_filter_lists_only_credit_sales(): void
    {
        $this->creditSale(['credit_days' => 7])->assertCreated();
        $this->postJson('/api/v1/invoices', [
            'pos_profile_id' => $this->profileId,
            'items' => [['item_id' => Item::where('item_code', 'ITEM-0001')->value('id'), 'qty' => 1]],
            'payments' => [['mode_of_payment' => 'Cash', 'amount' => 5]],
        ], $this->headers)->assertCreated();

        $creditNo = Invoice::where('is_credit', true)->value('invoice_no');
        $cashNo = Invoice::where('is_credit', false)->value('invoice_no');

        $this->actingAs(User::where('email', 'admin@pos.test')->first())
            ->withSession(['pos_profile_id' => $this->profileId])
            ->get('/invoices?credit=credit')
            ->assertOk()
            ->assertSee($creditNo)
            ->assertDontSee($cashNo);
    }

    public function test_kyat_amounts_use_the_k_prefix_with_a_space(): void
    {
        $this->assertSame('K ', PosProfile::symbolFor('MMK'));
        $this->assertSame('K 5,300', PosProfile::symbolFor('MMK').Money::format(5300, 0));
    }
}
