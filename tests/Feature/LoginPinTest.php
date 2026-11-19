<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Sign-in: ERPNext users prove their ERPNext password once (never stored), choose a PIN,
 * then sign in with "who am I" + PIN; ERPNext re-checks the password every 30 days.
 */
class LoginPinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::current()->update([
            'erpnext_url' => 'https://erp.example.test',
            'erpnext_api_key' => 'key',
            'erpnext_api_secret' => 'secret',
        ]);
        Setting::flushCache();
    }

    protected function erpUser(array $attributes = []): User
    {
        return User::create($attributes + [
            'name' => 'Aye', 'email' => 'aye@x.test', 'password' => 'random-not-known-0123456789',
            'role' => 'cashier', 'is_active' => true, 'erpnext_id' => 'aye@x.test',
        ]);
    }

    /** One closure for every URL (first Http::fake match wins). */
    protected function fakeErpLogin(int $status = 200): void
    {
        Http::fake(function ($request) use ($status) {
            if (str_contains($request->url(), '/api/method/login')) {
                return Http::response(['message' => 'Logged In'], $status);
            }

            return Http::response([], 404);
        });
    }

    public function test_erpnext_user_signs_in_with_erpnext_password_and_is_asked_for_a_pin(): void
    {
        $this->fakeErpLogin(200);
        $user = $this->erpUser();

        $this->post('/login', ['email' => 'aye@x.test', 'password' => 'erp-password'])
            ->assertRedirect(route('account.pin'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->erpnext_login_at);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'https://erp.example.test/api/method/login')
            && $r['usr'] === 'aye@x.test' && $r['pwd'] === 'erp-password'
            && ! $r->hasHeader('Authorization'));
        $this->assertFalse(Hash::check('erp-password', $user->fresh()->password), 'the ERPNext password must not be stored');
    }

    public function test_wrong_erpnext_password_is_rejected(): void
    {
        $this->fakeErpLogin(401);
        $this->erpUser();

        $this->from('/login')->post('/login', ['email' => 'aye@x.test', 'password' => 'nope'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_erpnext_user_cannot_sign_in_with_the_random_local_password(): void
    {
        $this->fakeErpLogin(401);
        $this->erpUser();

        $this->from('/login')->post('/login', ['email' => 'aye@x.test', 'password' => 'random-not-known-0123456789'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_erpnext_being_offline_blocks_password_login_with_a_clear_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->erpUser();

        $this->from('/login')->post('/login', ['email' => 'aye@x.test', 'password' => 'x'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_sets_a_pin_then_signs_in_with_name_and_pin_offline(): void
    {
        $this->fakeErpLogin(200);
        $user = $this->erpUser();

        $this->post('/login', ['email' => 'aye@x.test', 'password' => 'erp-password']);
        $this->put(route('account.pin.update'), ['pin' => '2468', 'pin_confirmation' => '2468'])
            ->assertRedirect(route('home'));
        $this->post('/logout');

        Http::fake(fn () => throw new ConnectionException('offline'));
        $this->post('/login', ['user_id' => $user->id, 'pin' => '2468'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_pin_is_refused_after_thirty_days_without_an_erpnext_check(): void
    {
        $user = $this->erpUser(['pos_pin' => '2468', 'erpnext_login_at' => now()->subDays(31)]);

        $this->from('/login')->post('/login', ['user_id' => $user->id, 'pin' => '2468'])
            ->assertSessionHasErrors('pin');
        $this->assertGuest();

        $user->update(['erpnext_login_at' => now()->subDays(29)]);
        $this->post('/login', ['user_id' => $user->id, 'pin' => '2468'])->assertRedirect('/');
    }

    public function test_erpnext_user_who_never_confirmed_the_password_cannot_use_a_manager_set_pin(): void
    {
        $user = $this->erpUser(['pos_pin' => '2468']);

        $this->from('/login')->post('/login', ['user_id' => $user->id, 'pin' => '2468'])
            ->assertSessionHasErrors('pin');
        $this->assertGuest();
    }

    public function test_pin_only_works_for_the_chosen_user_and_locks_that_user_after_wrong_attempts(): void
    {
        $other = User::create(['name' => 'Bo', 'email' => 'bo@x.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true, 'pos_pin' => '1111']);
        $mine = User::create(['name' => 'Cy', 'email' => 'cy@x.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true, 'pos_pin' => '2222']);

        // Bo's PIN typed for Cy does not work.
        $this->from('/login')->post('/login', ['user_id' => $mine->id, 'pin' => '1111'])->assertSessionHasErrors('pin');
        $this->assertGuest();

        config(['forkpos.pin_max_attempts' => 3]);
        RateLimiter::clear('pin:'.$mine->id);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['user_id' => $mine->id, 'pin' => '9999']);
        }
        // Even the right PIN is now refused for Cy, but Bo is unaffected.
        $this->from('/login')->post('/login', ['user_id' => $mine->id, 'pin' => '2222'])->assertSessionHasErrors('pin');
        $this->assertGuest();
        $this->post('/login', ['user_id' => $other->id, 'pin' => '1111'])->assertRedirect('/');
    }

    public function test_disabled_user_cannot_use_a_pin(): void
    {
        $user = User::create(['name' => 'Di', 'email' => 'di@x.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => false, 'pos_pin' => '3333']);

        $this->from('/login')->post('/login', ['user_id' => $user->id, 'pin' => '3333'])->assertSessionHasErrors('pin');
        $this->assertGuest();
    }

    public function test_login_page_lists_names_with_a_pin_but_no_emails(): void
    {
        User::create(['name' => 'Visible Vera', 'email' => 'vera@secret.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true, 'pos_pin' => '4444']);
        User::create(['name' => 'No Pin Nick', 'email' => 'nick@secret.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);

        $this->get('/login')->assertSee('Visible Vera')->assertDontSee('No Pin Nick')->assertDontSee('vera@secret.test');
    }

    public function test_api_pin_login_needs_the_user_and_api_lists_names_only(): void
    {
        $user = User::create(['name' => 'Api Ann', 'email' => 'ann@x.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true, 'pos_pin' => '5555']);

        $this->postJson('/api/v1/auth/login', ['pin' => '5555'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['user_id' => $user->id, 'pin' => '5555'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->getJson('/api/v1/auth/users')->assertOk()->assertJsonPath('data.0.name', 'Api Ann')
            ->assertJsonMissingPath('data.0.email');
    }

    public function test_pin_change_requires_the_current_pin_unless_password_was_just_confirmed(): void
    {
        $user = User::create(['name' => 'Eve', 'email' => 'eve@x.test', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true, 'pos_pin' => '6666']);
        $this->post('/login', ['user_id' => $user->id, 'pin' => '6666']);

        $this->put(route('account.pin.update'), ['pin' => '7777', 'pin_confirmation' => '7777'])->assertSessionHasErrors('current_pin');
        $this->put(route('account.pin.update'), ['current_pin' => '6666', 'pin' => '7777', 'pin_confirmation' => '7777'])->assertRedirect(route('home'));
        $this->assertTrue(Hash::check('7777', $user->fresh()->pos_pin));
    }
}
