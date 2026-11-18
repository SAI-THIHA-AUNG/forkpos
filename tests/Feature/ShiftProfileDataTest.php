<?php

namespace Tests\Feature;

use App\Models\PosProfile;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftProfileDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    /** @return array{Authorization: string} */
    protected function loginAsAdmin(): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@pos.test',
            'password' => 'password',
        ]);
        $response->assertOk();

        return ['Authorization' => 'Bearer '.$response->json('data.token')];
    }

    public function test_pos_profile_data_returns_catalog_for_a_profile(): void
    {
        $profileId = PosProfile::where('name', 'Main POS')->value('id');

        $response = $this->getJson("/api/v1/shifts/pos-profile-data/{$profileId}", $this->loginAsAdmin());

        $response->assertOk()
            ->assertJsonPath('data.pos_profile.id', $profileId)
            ->assertJsonStructure(['data' => [
                'pos_profile' => ['id', 'name', 'company', 'currency', 'warehouse_id'],
                'items' => [['id', 'item_code', 'item_name', 'standard_rate']],
                'customers' => [['id', 'customer_name', 'customer_group_id']],
                'warehouses',
                'payment_methods',
            ]]);
    }

    public function test_pos_profile_data_for_unknown_profile_hides_the_stack_trace(): void
    {
        $response = $this->getJson('/api/v1/shifts/pos-profile-data/999999', $this->loginAsAdmin());

        $response->assertStatus(500)->assertJsonMissingPath('data.trace');
    }
}
