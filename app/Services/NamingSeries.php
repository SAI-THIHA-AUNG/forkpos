<?php

namespace App\Services;

use Throwable;

/**
 * Resolves the ERPNext document naming series (for example
 * "ACC-SINV-.YYYY.-") and turns it into the literal prefix ForkPOS stores in
 * `settings.invoice_prefix`.
 *
 * ERPNext keeps the series on the DocType's `naming_series` field (a select,
 * set only once). If the API user is not allowed to read DocType metadata we
 * fall back to the `naming_series` of the newest document of that type.
 *
 * ForkPOS creates Sales Invoice documents (with `is_pos = 1` during push), so
 * that series is preferred; the POS Invoice series is the fallback for sites
 * that only number POS invoices.
 */
class NamingSeries
{
    /** DocTypes ForkPOS creates, in order of preference. */
    public const INVOICE_DOCTYPES = ['Sales Invoice', 'POS Invoice'];

    public function __construct(protected ErpNextClient $client) {}

    /**
     * Full series currently used for invoices, e.g. "ACC-SINV-.YYYY.-".
     */
    public function invoiceSeries(): ?string
    {
        foreach (self::INVOICE_DOCTYPES as $doctype) {
            if ($series = $this->forDoctype($doctype)) {
                return $series;
            }
        }

        return null;
    }

    /**
     * Full naming series configured for a DocType, or null when unknown.
     */
    public function forDoctype(string $doctype): ?string
    {
        try {
            $meta = $this->client->doc('DocType', $doctype);
            $options = collect($meta['fields'] ?? [])
                ->firstWhere('fieldname', 'naming_series')['options'] ?? '';

            if ($series = $this->firstLine((string) $options)) {
                return $series;
            }
        } catch (Throwable) {
            // DocType metadata is not readable for every API user; try a document.
        }

        try {
            $rows = $this->client->list($doctype, ['name', 'naming_series'], [], 1, 0, 'creation desc');

            return $this->firstLine((string) ($rows[0]['naming_series'] ?? ''));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The literal part before the dynamic numbering:
     * "ACC-SINV-.YYYY.-" => "ACC-SINV-", "INV-.#####" => "INV-".
     *
     * A series without placeholders is returned as-is (trimmed).
     */
    public static function prefix(?string $series): string
    {
        $series = trim((string) $series);
        if ($series === '') {
            return '';
        }

        $parts = preg_split('/(?:\.[A-Z]{1,4}\.|#+)/', $series, 2);
        $prefix = rtrim((string) ($parts[0] ?? ''), '.');

        return trim($prefix) !== '' ? $prefix : trim($series);
    }

    protected function firstLine(string $value): ?string
    {
        foreach (preg_split('/[\r\n]+/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }
}
