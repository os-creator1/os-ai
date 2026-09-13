<?php

namespace App\Library\Crm;

/**
 * Deal values are stored in minor units (cents) beside the currency captured
 * when the deal was created. v1 takes and shows two decimal places.
 */
final class CrmMoney
{
    /** 99,999,999.99 — what a form accepts. */
    public const MAX_MINOR = 9_999_999_999;

    public static function toMinor(mixed $amount): ?int
    {
        if ($amount === null || trim((string) $amount) === '') {
            return null;
        }

        return (int) round(((float) str_replace(',', '', (string) $amount)) * 100);
    }

    public static function toInput(?int $minor): string
    {
        return $minor === null ? '' : number_format($minor / 100, 2, '.', '');
    }

    public static function format(?int $minor, ?string $currency): ?string
    {
        if ($minor === null) {
            return null;
        }

        $amount = number_format($minor / 100, $minor % 100 === 0 ? 0 : 2);

        return $currency ? $currency . ' ' . $amount : $amount;
    }
}
