<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class PushInvoicesCommand extends Command
{
    protected $signature = 'pos:push-invoices
                            {--ids= : Comma-separated local invoice IDs (default: all pending)}';

    protected $description = 'Push pending POS invoices to a real ERPNext (creates POS Invoice documents)';

    public function handle(SyncService $sync): int
    {
        $ids = $this->option('ids')
            ? array_map('intval', array_filter(explode(',', (string) $this->option('ids'))))
            : null;

        $this->info('Pushing invoices to ERPNext...');

        try {
            $result = $sync->pushInvoices($ids);
            $collections = $ids ? null : $sync->pushCollections();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pushed: {$result['pushed']}, Failed: {$result['failed']}");
        foreach ($result['errors'] as $error) {
            $this->warn(" - {$error}");
        }

        if ($collections) {
            $this->info("Collections pushed: {$collections['pushed']}, Failed: {$collections['failed']}");
            foreach ($collections['errors'] as $error) {
                $this->warn(" - {$error}");
            }
            $result['failed'] += $collections['failed'];
        }

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
