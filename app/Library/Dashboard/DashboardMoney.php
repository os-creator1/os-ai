<?php

namespace App\Library\Dashboard;

/**
 * Customer Experience Slice 4 — one integer micro-unit amount as the customer
 * reads it, formatted exactly as the Usage & Billing page formats it: exact
 * string arithmetic (bcmath), two decimals, thousands grouped, the wallet's
 * own currency code in front. Never a float, never a recomputation.
 */
final class DashboardMoney
{
    public static function format(?string $micro, ?string $currencyCode): string
    {
        if ($micro === null || $micro === '') {
            return '—';
        }

        $negative = str_starts_with($micro, '-');
        $major = bcdiv(ltrim($micro, '-'), '1000000', 2);
        [$whole, $cents] = array_pad(explode('.', $major, 2), 2, '00');
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));
        $currency = (string) $currencyCode;

        return ($negative ? '-' : '') . ($currency !== '' ? $currency . ' ' : '') . $grouped . '.' . $cents;
    }
}
