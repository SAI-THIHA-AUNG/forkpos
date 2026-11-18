<?php

namespace Tests\Feature;

use App\Models\PosProfile;
use App\Models\Tax;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The register's default tax comes from the POS profile's ERPNext "Taxes and
 * Charges" template — never from a hard-coded "is_default" row. A profile with
 * no template sells without tax (ERPNext's own behaviour), which stops the
 * demo-seeded tax from leaking onto real ERPNext-synced profiles.
 */
class ProfileTaxTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected PosProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->profile = PosProfile::where('name', 'Main POS')->firstOrFail();
        $this->profile->update(['require_shift' => false]);
    }

    public function test_profile_without_a_tax_template_gets_no_default_tax(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->get('/pos');
        $response->assertOk();

        $this->assertTrue($response->viewData('defaultTaxes')->isEmpty(), 'a profile with no template applies no default tax');
    }

    public function test_profile_with_a_tax_template_gets_only_that_tax(): void
    {
        $template = Tax::create([
            'name' => 'Myanmar Commercial Tax',
            'erpnext_id' => 'Myanmar Commercial Tax - M',
            'account_name' => 'Commercial Tax Payable - M',
            'rate' => 5,
            'included_in_price' => false,
        ]);

        $this->profile->forceFill(['erpnext_data' => ['taxes_and_charges' => 'Myanmar Commercial Tax - M']])->save();

        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->withSession(['pos_profile_id' => $this->profile->id])
            ->get('/pos');
        $response->assertOk();

        $ids = $response->viewData('defaultTaxes')->pluck('id')->all();

        $this->assertSame([$template->id], $ids, 'only the profile template tax is applied');
    }

    public function test_api_default_taxes_follow_the_profile_template(): void
    {
        $template = Tax::create([
            'name' => 'Myanmar Commercial Tax',
            'erpnext_id' => 'Myanmar Commercial Tax - M',
            'account_name' => 'Commercial Tax Payable - M',
            'rate' => 5,
            'included_in_price' => false,
        ]);

        $this->profile->forceFill(['erpnext_data' => ['taxes_and_charges' => 'Myanmar Commercial Tax - M']])->save();

        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->getJson("/api/v1/pos-profiles/{$this->profile->id}");

        $response->assertOk();
        $taxes = $response->json('data.default_taxes');

        $this->assertCount(1, $taxes);
        $this->assertSame($template->id, $taxes[0]['id']);
    }

    public function test_api_default_taxes_are_empty_without_a_template(): void
    {
        $cashier = User::where('email', 'cashier@pos.test')->firstOrFail();

        $response = $this->actingAs($cashier)
            ->getJson("/api/v1/pos-profiles/{$this->profile->id}");

        $response->assertOk();
        $this->assertSame([], $response->json('data.default_taxes'));
    }
}
