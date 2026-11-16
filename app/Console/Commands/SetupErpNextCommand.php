<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\ErpNextClient;
use App\Services\SyncService;
use Illuminate\Console\Command;
use Throwable;

class SetupErpNextCommand extends Command
{
    protected $signature = 'erpnext:setup
                            {--url= : ERPNext URL}
                            {--api-key= : ERPNext API Key}
                            {--api-secret= : ERPNext API Secret}
                            {--company= : ERPNext Company}
                            {--price-list= : ERPNext Price List}';

    protected $description = 'Setup ERPNext integration and sync initial data';

    public function handle(SyncService $sync): int
    {
        $this->info('Setting up ERPNext integration...');

        $setting = Setting::current();

        $setting->erpnext_url = $this->option('url') ?: $this->ask('ERPNext URL', 'https://erpnext.example.com');
        $setting->erpnext_api_key = $this->option('api-key') ?: $this->secret('ERPNext API Key');
        $setting->erpnext_api_secret = $this->option('api-secret') ?: $this->secret('ERPNext API Secret');
        $setting->erpnext_company = $this->option('company') ?: $this->ask('ERPNext Company', 'My Company');
        $setting->erpnext_price_list = $this->option('price-list') ?: $this->ask('ERPNext Price List', 'Standard Selling');
        $setting->save();
        Setting::flushCache();

        $this->info('ERPNext credentials saved successfully.');

        $this->info('Testing ERPNext connection...');

        try {
            app(ErpNextClient::class)->ping();
            $this->info('Connection to ERPNext successful.');
        } catch (Throwable $e) {
            $this->error('Connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Syncing initial data from ERPNext...');

        try {
            $entities = ['uom', 'item_group', 'warehouse', 'customer_group', 'territory', 'tax', 'item', 'customer'];
            $result = $sync->pullMasters($entities, 50);

            foreach ($result['results'] as $entity => $stats) {
                $this->info("{$entity}: {$stats['pulled']} pulled, {$stats['upserted']} upserted");
            }

            foreach ($result['errors'] as $error) {
                $this->warn("ERROR: {$error}");
            }

            $this->info('Initial data synced successfully.');
            $this->info('You can now open shifts and start selling.');

            return $result['errors'] ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
