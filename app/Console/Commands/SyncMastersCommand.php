<?php

namespace App\Console\Commands;

use App\Models\PosProfile;
use App\Models\PosProfilePayment;
use App\Models\Warehouse;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncMastersCommand extends Command
{
    protected $signature = 'pos:sync-masters
                            {--entities= : Comma-separated list (uom,item_group,warehouse,customer_group,territory,tax,item,item_price,customer,stock)}
                            {--limit=500 : Max records per entity}';

    protected $description = 'Pull master data (items, prices, customers, taxes, stock...) from a real ERPNext into the local database';

    public function handle(SyncService $sync): int
    {
        $valid = ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'item_price', 'customer', 'stock'];
        $entities = $this->option('entities')
            ? array_values(array_intersect(explode(',', (string) $this->option('entities')), $valid))
            : $valid;

        if (! $entities) {
            $this->error('No valid entities given. Valid: '.implode(', ', $valid));

            return self::FAILURE;
        }

        $this->info('Pulling from ERPNext: '.implode(', ', $entities));

        try {
            $result = $sync->pullMasters($entities, (int) $this->option('limit'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['results'] as $entity => $stats) {
            $this->info(sprintf(
                '%-15s pulled=%-5d upserted=%-5d %s',
                $entity,
                $stats['pulled'] ?? 0,
                $stats['upserted'] ?? 0,
                isset($stats['warehouse']) ? "(warehouse: {$stats['warehouse']})" : ''
            ));
        }
        foreach ($result['errors'] as $error) {
            $this->warn("FAILED: {$error}");
        }

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Sync initial data from ERPNext when settings are updated
     */
    public static function syncInitialData(): void
    {
        $sync = app(SyncService::class);

        // Sync essential entities
        $entities = ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'customer'];

        try {
            $result = $sync->pullMasters($entities, 50);

            // Log success
            \Log::info('Initial data synced from ERPNext', $result);
        } catch (\Exception $e) {
            \Log::error('Failed to sync initial data from ERPNext: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Update POS profiles with ERPNext warehouses
     */
    public static function updatePosProfiles(): void
    {
        $warehouse = Warehouse::whereNotNull('erpnext_id')
            ->where('is_group', false)
            ->orderBy('name')
            ->first();

        if (! $warehouse) {
            \Log::warning('No ERPNext warehouse found for POS profile update');

            return;
        }

        // Update or create the main POS profile
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

        \Log::info('POS profile updated with ERPNext warehouse: '.$warehouse->name);
    }
}
