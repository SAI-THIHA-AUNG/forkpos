<?php

namespace App\Console\Commands;

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
}
