<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

/**
 * One-off repair for Sales Invoices that ForkPOS pushed before it learned to
 * submit them. Those documents sit in Draft in ERPNext, so they have no
 * outstanding balance and credit collection cannot see them.
 *
 * Dry run by default: submitting changes live accounting data (stock, GL).
 */
class SubmitDraftsCommand extends Command
{
    protected $signature = 'pos:submit-drafts
                            {--apply : Actually submit the drafts (without it, only list them)}';

    protected $description = 'Submit Sales Invoices an older ForkPOS left as drafts (dry run by default)';

    public function handle(SyncService $sync): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? 'Submitting ForkPOS draft invoices...'
            : 'ForkPOS draft invoices (dry run - nothing is submitted):');

        try {
            $result = $sync->submitForkposDrafts($apply);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $result['drafts']) {
            $this->info('No draft invoices found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Invoice', 'Posting date', 'Total', 'Remarks'],
            array_map(fn (array $d) => [
                $d['name'],
                (string) $d['posting_date'],
                number_format($d['total'], 2),
                mb_strimwidth((string) $d['remarks'], 0, 60, '...'),
            ], $result['drafts']),
        );

        if (! $apply) {
            $this->info(sprintf('%d draft(s). Run again with --apply to submit them.', count($result['drafts'])));

            return self::SUCCESS;
        }

        $this->info(sprintf('Submitted %d, failed %d.', $result['submitted'], count($result['failed'])));
        foreach ($result['failed'] as $error) {
            $this->warn($error);
        }

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
