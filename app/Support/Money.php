<?php

namespace App\Support;

/**
 * Currency formatting for the Blade screens.
 *
 * ERPNext owns the decimal precision (it arrives with the currency) and we keep
 * using it to round, but the register/receipts should not force a fixed number
 * of decimals: 1,000 is shown as "1,000", not "1,000.00", while 1.25 stays
 * "1.25".
 */
final class Money
{
    public static function format(float|int|string|null $amount, int $precision = 2): string
    {
        return self::trimmed((float) $amount, $precision);
    }

    /** Quantities: trim trailing zeros too (4.000 => "4"), default 3 decimals. */
    public static function quantity(float|int|string|null $value, int $precision = 3): string
    {
        return self::trimmed((float) $value, $precision);
    }

    /** Same trimming without thousands separators — for number inputs. */
    public static function plain(float|int|string|null $value, int $precision = 2): string
    {
        return self::trimmed((float) $value, $precision, false);
    }

    private static function trimmed(float $value, int $precision, bool $grouped = true): string
    {
        $precision = max(0, $precision);
        $formatted = number_format(round($value, $precision), $precision, '.', $grouped ? ',' : '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }
}
