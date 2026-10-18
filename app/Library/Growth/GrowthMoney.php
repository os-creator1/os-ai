<?php

declare(strict_types=1);

namespace App\Library\Growth;

/**
 * Owner-facing money and plural helpers. Growth only ever formats a figure
 * that came from a canonical row (a deal value, a schedule item's amount);
 * it never produces an estimate, so there is no "approximately" anywhere.
 */
final class GrowthMoney
{
    private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$', 'AUD' => 'A$'];

    public static function format(?int $minor, ?string $currency): ?string
    {
        if ($minor === null || $currency === null || $currency === '') {
            return null;
        }

        $major = $minor / 100;
        $prefix = self::SYMBOLS[strtoupper($currency)] ?? (strtoupper($currency) . ' ');
        $decimals = ($minor % 100 === 0) ? 0 : 2;

        return $prefix . number_format($major, $decimals);
    }

    public static function plural(int $count, string $singular, ?string $plural = null): string
    {
        return $count . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }
}
