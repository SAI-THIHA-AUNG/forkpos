<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Web POS flow — proves the Blade UI works end-to-end against the same
 * services the API uses. Mirrors the user's "web first" directive from
 * AI_TALK.md.
 */
class WebPosFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_anonymous_visitor_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/pos')->assertRedirect('/login');
    }

    public function test_cashier_can_login_with_email_and_password(): void
    {
        $this->post('/login', [
            'email' => 'cashier@pos.test',
            'password' => 'password',
        ])->assertRedirect('/');
    }

    public function test_cashier_can_login_with_pin(): void
    {
        $this->post('/login', [
            'pin' => '5678',
        ])->assertRedirect('/');
    }

    public function test_invalid_pin_is_rejected(): void
    {
        $this->from('/login')->post('/login', ['pin' => '0000'])
            ->assertSessionHasErrors(['pin']);
    }

    public function test_a_stale_pin_does_not_hijack_email_password_login(): void
    {
        $this->post('/login', [
            'email' => 'cashier@pos.test',
            'password' => 'password',
            'pin' => '0000',
        ])->assertRedirect('/');
    }

    public function test_profile_selection_requires_a_valid_profile(): void
    {
        $this->actingAs(User::where('email', 'cashier@pos.test')->first())
            ->post('/profile/select', ['pos_profile_id' => 99999])
            ->assertSessionHasErrors(['pos_profile_id']);
    }

    public function test_register_redirects_to_shift_open_when_required(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->first();
        $profileId = $cashier->posProfiles()->first()->id;

        $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $profileId])
            ->get('/pos')
            ->assertRedirect('/shifts/open');
    }

    public function test_full_web_flow_login_select_open_shift_register(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->first();

        // 1. Login
        $this->post('/login', ['email' => 'cashier@pos.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($cashier);

        // 2. Select profile
        $profile = $cashier->posProfiles()->first();
        $this->post('/profile/select', ['pos_profile_id' => $profile->id])->assertRedirect('/shifts/open');

        // 3. Open shift with opening balances
        $this->post('/shifts/open', [
            'pos_profile_id' => $profile->id,
            'balances' => [
                ['mode_of_payment' => 'Cash', 'amount' => 100.00],
                ['mode_of_payment' => 'Card', 'amount' => 0],
            ],
        ])->assertRedirect('/pos');

        // 4. Register renders (shift is now open)
        $this->get('/pos')->assertOk()->assertSee('Current Sale');

        // 5. Logout
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_role_middleware_blocks_cashier_from_sync_and_settings(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->first();
        $profile = $cashier->posProfiles()->first();

        $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $profile->id])
            ->get('/sync')->assertForbidden();
        $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $profile->id])
            ->get('/settings')->assertForbidden();
    }

    public function test_customer_update_rejects_loyalty_points_mass_assignment(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->first();
        $profile = $cashier->posProfiles()->first();
        $customer = Customer::where('is_walk_in', false)->first();
        $originalPoints = $customer->loyalty_points;

        $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $profile->id])
            ->put("/customers/{$customer->id}", [
                'customer_name' => 'Renamed Customer',
                'loyalty_points' => 999999, // should be ignored
            ])
            ->assertRedirect();

        $customer->refresh();
        $this->assertSame('Renamed Customer', $customer->customer_name);
        $this->assertSame($originalPoints, $customer->loyalty_points, 'loyalty_points must not be writable from the web form');
    }
}
