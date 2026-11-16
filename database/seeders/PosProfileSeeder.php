<?php

namespace Database\Seeders;

use App\Models\PosProfile;
use App\Models\PosProfilePayment;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class PosProfileSeeder extends Seeder
{
    public function run()
    {
        // Find a warehouse with ERPNext ID
        $warehouse = Warehouse::whereNotNull('erpnext_id')
            ->where('is_group', false)
            ->first();

        if (! $warehouse) {
            $this->command->warn('No ERPNext warehouse found. Please run sync first.');

            return;
        }

        // Create or update the main POS profile
        $profile = PosProfile::firstOrCreate(['id' => 1], [
            'name' => 'Main POS',
            'company' => 'My Company',
            'currency' => 'USD',
            'warehouse_id' => $warehouse->id,
            'selling_price_list' => 'Standard Selling',
            'allow_rate_change' => false,
            'allow_discount_change' => true,
            'allow_negative_stock' => false,
            'tax_inclusive' => true,
            'ask_for_customer' => true,
            'require_shift' => true,
            'is_default' => true,
            'disabled' => false,
        ]);

        // Create payment methods for the profile
        $payments = [
            ['mode_of_payment' => 'Cash', 'is_default' => true, 'allow_edit_amount' => true],
            ['mode_of_payment' => 'Card', 'is_default' => false, 'allow_edit_amount' => true],
            ['mode_of_payment' => 'Mobile Wallet', 'is_default' => false, 'allow_edit_amount' => true],
        ];

        foreach ($payments as $payment) {
            PosProfilePayment::firstOrCreate(
                [
                    'pos_profile_id' => $profile->id,
                    'mode_of_payment' => $payment['mode_of_payment'],
                ],
                $payment
            );
        }

        $this->command->info("Created POS profile: {$profile->name} using warehouse: {$warehouse->name}");
    }
}
