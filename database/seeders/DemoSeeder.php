<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\ItemGroup;
use App\Models\ItemPrice;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\Tax;
use App\Models\Territory;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Demo dataset for the first run: settings, users, a POS profile with
     * payment methods, 32 items with barcodes/prices/stock, customers,
     * taxes and coupons. Opt-in only: `php artisan db:seed --class=DemoSeeder`. Never part of a normal install.
     */
    public function run(): void
    {
        // ---- Settings -----------------------------------------------------
        Setting::flushCache();
        Setting::query()->delete();
        Setting::create([
            'company_name' => 'ForkPOS Demo Store',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'currency_precision' => 2,
            'tax_inclusive_prices' => true,
            'allow_negative_stock' => false,
            'invoice_prefix' => 'POS-',
            'next_invoice_number' => 1,
            'receipt_footer' => "Thank you for shopping with us!\nItems can be returned within 7 days with receipt.",
        ]);

        // ---- Users ---------------------------------------------------------
        $admin = User::create([
            'name' => 'POS Administrator',
            'email' => 'admin@pos.test',
            'password' => 'password',
            'role' => User::ROLE_ADMIN,
            'pos_pin' => '1234',
            'is_active' => true,
        ]);
        $cashier = User::create([
            'name' => 'Aung Cashier',
            'email' => 'cashier@pos.test',
            'password' => 'password',
            'role' => User::ROLE_CASHIER,
            'pos_pin' => '5678',
            'is_active' => true,
        ]);

        // ---- UOMs / groups / warehouse / taxes -----------------------------
        $unit = Uom::create(['uom_name' => 'Unit']);
        $box = Uom::create(['uom_name' => 'Box']);
        $kg = Uom::create(['uom_name' => 'Kg']);
        $litre = Uom::create(['uom_name' => 'Litre']);

        $beverages = ItemGroup::create(['name' => 'Beverages']);
        $snacks = ItemGroup::create(['name' => 'Snacks']);
        $groceries = ItemGroup::create(['name' => 'Groceries']);
        $household = ItemGroup::create(['name' => 'Household']);
        $personal = ItemGroup::create(['name' => 'Personal Care']);

        $warehouse = Warehouse::create([
            'name' => 'Main Store',
            'warehouse_code' => 'MAIN',
            'is_default' => true,
        ]);

        Tax::create([
            'name' => 'Commercial Tax 5%',
            'description' => 'Commercial Tax (included in price)',
            'rate' => 5,
            'included_in_price' => true,
            'is_default' => true,
        ]);
        Tax::create([
            'name' => 'VAT 10%',
            'description' => 'Value Added Tax (added on top)',
            'rate' => 10,
            'included_in_price' => false,
            'is_default' => false,
        ]);

        // ---- Customer groups / territories / customers ----------------------
        $retail = CustomerGroup::create(['name' => 'Retail', 'is_default' => true]);
        CustomerGroup::create(['name' => 'Wholesale']);
        $territory = Territory::create(['name' => 'All Territories', 'is_default' => true]);

        Customer::create([
            'customer_code' => 'CUST-00001',
            'customer_name' => 'Walk-in Customer',
            'customer_group_id' => $retail->id,
            'territory_id' => $territory->id,
            'is_walk_in' => true,
        ]);
        $customerNames = [
            'Thiri Aung', 'Kyaw Min', 'Su Su Hlaing', 'Myo Thu', 'Nilar Win',
            'Zaw Zaw', 'Hnin Yu', 'Aye Chan', 'Ko Ko Gyi', 'Moe Sandar',
        ];
        foreach ($customerNames as $index => $name) {
            Customer::create([
                'customer_code' => 'CUST-'.str_pad((string) ($index + 2), 5, '0', STR_PAD_LEFT),
                'customer_name' => $name,
                'customer_group_id' => $retail->id,
                'territory_id' => $territory->id,
                'phone' => '09'.str_pad((string) (770000000 + $index * 1111), 9, '0'),
                'city' => 'Yangon',
                'country' => 'Myanmar',
            ]);
        }

        // ---- Items with prices, barcodes and opening stock ------------------
        $catalog = [
            // [group, name, uom, rate]
            [$beverages, 'Coca Cola 350ml', $unit, 1.20],
            [$beverages, 'Sprite 350ml', $unit, 1.20],
            [$beverages, 'Pepsi 600ml', $unit, 1.50],
            [$beverages, 'Red Bull 250ml', $unit, 2.00],
            [$beverages, 'Coffee Latte Can', $unit, 1.80],
            [$beverages, 'Green Tea Bottle 500ml', $unit, 1.10],
            [$beverages, 'Orange Juice 1L', $litre, 2.50],
            [$beverages, 'Mineral Water 1L', $litre, 0.60],
            [$snacks, 'Potato Chips Classic', $unit, 1.30],
            [$snacks, 'Potato Chips BBQ', $unit, 1.35],
            [$snacks, 'Chocolate Bar Milk', $unit, 0.90],
            [$snacks, 'Chocolate Bar Dark', $unit, 1.00],
            [$snacks, 'Instant Noodle Shrimp', $unit, 0.50],
            [$snacks, 'Instant Noodle Chicken', $unit, 0.50],
            [$snacks, 'Biscuit Family Pack', $box, 2.20],
            [$snacks, 'Salted Peanuts 200g', $unit, 1.40],
            [$groceries, 'Rice Premium 5Kg', $kg, 6.50],
            [$groceries, 'Cooking Oil 1L', $litre, 2.80],
            [$groceries, 'Sugar 1Kg', $kg, 1.20],
            [$groceries, 'Salt 500g', $unit, 0.40],
            [$groceries, 'Eggs (10 pcs)', $unit, 1.90],
            [$groceries, 'Tomato Paste 400g', $unit, 1.10],
            [$groceries, 'Coconut Milk 400ml', $unit, 1.05],
            [$household, 'Dish Soap 500ml', $unit, 1.60],
            [$household, 'Laundry Powder 1Kg', $kg, 2.90],
            [$household, 'Toilet Paper 6 rolls', $box, 2.40],
            [$household, 'Trash Bags 30pcs', $box, 1.70],
            [$household, 'Sponges (3 pcs)', $unit, 0.80],
            [$personal, 'Toothpaste 120g', $unit, 1.50],
            [$personal, 'Shampoo 400ml', $unit, 3.20],
            [$personal, 'Soap Bar 100g', $unit, 0.70],
            [$personal, 'Hand Sanitizer 250ml', $unit, 1.90],
        ];

        foreach ($catalog as $index => [$group, $name, $uom, $rate]) {
            $seq = $index + 1;
            $item = Item::create([
                'item_code' => 'ITEM-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                'item_name' => $name,
                'item_group_id' => $group->id,
                'description' => $name.' — demo catalog item',
                'stock_uom_id' => $uom->id,
                'sales_uom_id' => $uom->id,
                'is_stock_item' => true,
                'standard_rate' => $rate,
            ]);
            ItemPrice::create([
                'item_id' => $item->id,
                'price_list' => 'Standard Selling',
                'currency' => 'USD',
                'price_list_rate' => $rate,
            ]);
            ItemBarcode::create([
                'item_id' => $item->id,
                'barcode' => '88'.str_pad((string) (100000 + $seq), 10, '0', STR_PAD_LEFT),
                'uom_id' => $uom->id,
            ]);
            StockBalance::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'qty' => 100,
            ]);
            StockLedgerEntry::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'qty_change' => 100,
                'qty_after' => 100,
                'ref_type' => 'opening',
                'posting_date' => now()->toDateString(),
            ]);
        }

        // ---- POS Profile + payment methods ----------------------------------
        $profile = PosProfile::create([
            'name' => 'Main POS',
            'company' => 'ForkPOS Demo Store',
            'currency' => 'USD',
            'warehouse_id' => $warehouse->id,
            'selling_price_list' => 'Standard Selling',
            'allow_rate_change' => true,
            'allow_discount_change' => true,
            'tax_inclusive' => true,
            'write_off_limit' => 0,
            'max_discount_percentage' => 20,
            'ask_for_customer' => false,
            'require_shift' => true,
            'is_default' => true,
        ]);
        foreach ([
            ['Cash', true],
            ['Card', false],
            ['Mobile Wallet', false],
        ] as [$mode, $default]) {
            $profile->payments()->create([
                'mode_of_payment' => $mode,
                'is_default' => $default,
                'allow_edit_amount' => true,
            ]);
        }
        $profile->users()->syncWithPivotValues([$admin->id, $cashier->id], ['is_default' => true]);

        // ---- Coupons -----------------------------------------------------------
        Coupon::create([
            'coupon_code' => 'WELCOME10',
            'type' => Coupon::TYPE_PERCENT,
            'discount_value' => 10,
            'min_order_amount' => 10,
            'max_use' => 100,
        ]);
        Coupon::create([
            'coupon_code' => 'FLAT5',
            'type' => Coupon::TYPE_AMOUNT,
            'discount_value' => 5,
            'min_order_amount' => 20,
            'max_use' => 0, // unlimited
        ]);

        $this->command?->info('Seeded: 2 users (admin@pos.test / cashier@pos.test, PINs 1234 / 5678), 1 POS profile, '.count($catalog).' items, 11 customers, 2 taxes, 2 coupons.');
    }
}
