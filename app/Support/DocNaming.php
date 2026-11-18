<?php

namespace App\Support;

/**
 * Document-number rules, kept free of framework code so they are trivially testable.
 *
 * Goal: ERPNext's own naming series + a van id, e.g.
 *   ERPNext series   ACC-SINV-.YYYY.-
 *   van id           TVP
 *   local number     ACC-SINV-TVP-2026-00001      (returns: ACC-SINV-TVP-CR-2026-00001)
 *   ERPNext series   ACC-SINV-TVP-.YYYY.-         (what a push asks ERPNext to use)
 *
 * Two vans therefore never mint the same number, which is what made invoice
 * numbers collide before.
 */
final class DocNaming
{
    public const MAX_LENGTH = 32; // invoices.invoice_no column

    /** Upper-case letters/digits only, max 8 chars. '' when nothing usable is left. */
    public static function normaliseVanCode(?string $raw): string
    {
        return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $raw)) ?? '', 0, 8);
    }

    /** First letters of the profile's words ("Test Van POS" → "TVP"); digits are kept ("Van 2" → "V2"). */
    public static function suggestVanCode(string $profileName): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', trim($profileName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $code = '';
        foreach ($words as $word) {
            $code .= ctype_digit($word) ? $word : strtoupper($word[0]);
        }
        $code = self::normaliseVanCode($code);

        return $code !== '' ? $code : 'VAN';
    }

    /** "ACC-SINV-.YYYY.-" → "ACC-SINV-" (the literal text before the first "." token). */
    public static function seriesPrefix(?string $series): string
    {
        $series = (string) $series;
        $pos = strpos($series, '.');
        $prefix = $pos === false ? $series : substr($series, 0, $pos);

        return preg_replace('/[^A-Za-z0-9\-_]/', '', $prefix) ?? '';
    }

    /** "ACC-SINV-.YYYY.-" + TVP → "ACC-SINV-TVP-.YYYY.-" */
    public static function vanSeries(string $series, string $vanCode): string
    {
        $prefix = self::seriesPrefix($series);
        $rest = substr($series, strpos($series, '.') === false ? strlen($series) : strpos($series, '.'));

        return $prefix.self::withDash($vanCode).($rest !== '' ? $rest : '.YYYY.-');
    }

    /**
     * Local document number: {prefix}{VAN}-[{tag}-]{YYYY}-{seq}.
     * Falls back to a shorter prefix rather than ever exceeding the column.
     */
    public static function number(string $prefix, ?string $vanCode, string $tag, int $year, int $seq): string
    {
        $van = $vanCode ? self::withDash($vanCode) : '';
        $tail = ($tag !== '' ? $tag.'-' : '').$year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

        $room = self::MAX_LENGTH - strlen($van) - strlen($tail);

        return substr($prefix, 0, max(0, $room)).$van.$tail;
    }

    protected static function withDash(string $code): string
    {
        return rtrim($code, '-').'-';
    }
}
