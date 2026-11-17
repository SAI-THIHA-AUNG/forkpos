<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The first-run flow: default login → new password → connect ERPNext
 * (verified, not just pinged) → choose profiles (users imported) → normal flow.
 * Uses the real DatabaseSeeder: bootstrap admin only, zero demo data.
 */
class SetupFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function defaultCreds(): array
    {
        return [
            'email' => config('forkpos.bootstrap_admin.email'),
            'password' => config('forkpos.bootstrap_admin.password'),
        ];
    }

    protected function vanDoc(): array
    {
        return [
            'name' => 'Test Van POS',
            'company' => 'ACME',
            'currency' => 'MMK',
            'warehouse' => 'Stores - M',
            'selling_price_list' => 'Retail',
            'allow_user_to_edit_rate' => 1,
            'customer' => 'Walk-in Customer',
            'applicable_for_users' => [['user' => 'cashier@erp.test', 'default' => 1]],
            'item_groups' => [['item_group' => 'Drinks']],
            'payments' => [['mode_of_payment' => 'Cash', 'default' => 1]],
        ];
    }

    protected function fakeErp(): void
    {
        Http::fake([
            'erp.example.test/api/method/frappe.auth.get_logged_user' => Http::response(['message' => 'api@erp.test'], 200),
            'erp.example.test/api/resource/POS%20Profile/Test%20Van%20POS' => Http::response(['data' => $this->vanDoc()], 200),
            'erp.example.test/api/resource/POS%20Profile*' => Http::response(['data' => [
                ['name' => 'Test Van POS', 'company' => 'ACME', 'currency' => 'MMK', 'warehouse' => 'Stores - M'],
            ]], 200),
        ]);
    }

    public function test_fresh_install_has_only_the_bootstrap_admin_and_no_demo_data(): void
    {
        $this->assertSame(1, User::count());
        $this->assertTrue(User::first()->must_change_password);
        $this->assertSame(0, PosProfile::count());
        $this->assertSame(0, Item::count());
    }

    public function test_default_login_walks_through_password_connect_and_profile_steps(): void
    {
        $this->fakeErp();

        $this->post('/login', $this->defaultCreds())->assertRedirect('/');

        // 1. must replace the bootstrap password
        $this->get('/')->assertRedirect('/setup/password');
        $this->post('/setup/password', [
            'current_password' => $this->defaultCreds()['password'],
            'password' => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ])->assertRedirect('/');
        $this->assertFalse(User::first()->must_change_password);

        // 2. connect ERPNext — credentials are verified, then stored
        $this->get('/')->assertRedirect('/setup/connect');
        $this->post('/setup/connect', [
            'erpnext_url' => 'https://erp.example.test',
            'erpnext_api_key' => 'k',
            'erpnext_api_secret' => 's',
        ])->assertRedirect('/setup/profiles');
        Setting::flushCache();
        $this->assertNotNull(Setting::current()->erpnext_verified_at);
        $this->get('/')->assertRedirect('/setup/profiles');

        // 3. choose profiles
        $this->get('/setup/profiles')->assertOk()->assertSee('Test Van POS');
        $this->post('/setup/profiles', ['profiles' => ['Test Van POS']])->assertRedirect('/');

        $profile = PosProfile::where('erpnext_id', 'Test Van POS')->firstOrFail();
        $this->assertSame('MMK', $profile->currency);
        $this->assertTrue($profile->allow_rate_change);          // from the ERPNext doc
        $this->assertFalse($profile->ask_for_customer);          // default customer present
        $this->assertSame(['Drinks'], $profile->itemGroups());

        // Cashier imported from "Applicable for Users": exists, attached, no PIN yet.
        $cashier = User::where('email', 'cashier@erp.test')->firstOrFail();
        $this->assertNull($cashier->pos_pin);
        $this->assertTrue($profile->users()->where('users.id', $cashier->id)->exists());

        // Setup is complete: the picker shows the profile.
        $this->get('/')->assertOk()->assertSee('Test Van POS');
    }

    public function test_wrong_api_credentials_are_rejected_and_not_saved(): void
    {
        Http::fake([
            'erp.example.test/api/method/frappe.auth.get_logged_user' => Http::response([], 401),
        ]);
        $admin = User::first();
        $admin->forceFill(['must_change_password' => false])->save();

        $this->actingAs($admin)->post('/setup/connect', [
            'erpnext_url' => 'https://erp.example.test',
            'erpnext_api_key' => 'bad',
            'erpnext_api_secret' => 'bad',
        ])->assertSessionHas('error');

        Setting::flushCache();
        $this->assertNull(Setting::current()->erpnext_verified_at);
        $this->assertNull(Setting::current()->erpnext_url);
    }

    public function test_cashier_waits_when_setup_is_unfinished(): void
    {
        $cashier = User::create([
            'name' => 'C', 'email' => 'c@x.test', 'password' => 'secret123',
            'role' => User::ROLE_CASHIER, 'is_active' => true,
        ]);

        $this->actingAs($cashier)->get('/')->assertRedirect('/setup/waiting');
        $this->actingAs($cashier)->get('/setup/connect')->assertForbidden();
    }

    public function test_profile_visibility_follows_erpnext_applicable_users(): void
    {
        $cashier = User::create(['name' => 'C', 'email' => 'c@x.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);
        $other = User::create(['name' => 'O', 'email' => 'o@x.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);

        $restricted = PosProfile::create(['name' => 'Restricted']);
        $restricted->users()->sync([$other->id]);
        PosProfile::create(['name' => 'Open to all']);
        PosProfile::create(['name' => 'Off', 'device_enabled' => false]);

        $this->assertSame(['Open to all'], PosProfile::visibleTo($cashier)->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Open to all', 'Restricted'], PosProfile::visibleTo(User::first())->pluck('name')->all());
    }

    public function test_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'nobody@x.test', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'nobody@x.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_an_ambiguous_pin_never_logs_anyone_in(): void
    {
        foreach (['a@x.test', 'b@x.test'] as $email) {
            User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true, 'pos_pin' => '4321']);
        }

        $this->from('/login')->post('/login', ['pin' => '4321'])->assertSessionHasErrors('pin');
        $this->assertGuest();
    }
}
