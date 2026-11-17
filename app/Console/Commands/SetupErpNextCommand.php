<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\ErpNextClient;
use App\Services\PosProfileSync;
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

    public function handle(): int
    {
        $this->info('Setting up ERPNext integration...');

        $setting = Setting::current();

        $setting->erpnext_url = $this->option('url') ?: $this->ask('ERPNext URL');
        $setting->erpnext_api_key = $this->option('api-key') ?: $this->secret('ERPNext API Key');
        $setting->erpnext_api_secret = $this->option('api-secret') ?: $this->secret('ERPNext API Secret');
        $setting->erpnext_company = $this->option('company') ?: ($this->ask('ERPNext Company (optional, legacy full sync only)') ?: null);
        $setting->erpnext_price_list = $this->option('price-list') ?: null; // price lists come from each POS profile
        $setting->save();
        Setting::flushCache();

        $this->info('ERPNext credentials saved successfully.');

        $this->info('Verifying API key/secret...');

        try {
            $user = app(ErpNextClient::class)->verify()['user'];
            $setting->forceFill(['erpnext_verified_at' => now()])->save();
            Setting::flushCache();
            $this->info("Authenticated as {$user}.");
        } catch (Throwable $e) {
            $this->error('Connection failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Pulling POS profiles...');
        $result = app(PosProfileSync::class)->syncAll();
        $this->info("Synced {$result['synced']} profile(s).");
        foreach ($result['errors'] as $error) {
            $this->warn("FAILED: {$error}");
        }
        $this->info('Catalog is pulled per profile when a shift opens.');

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
