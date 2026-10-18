<?php

namespace App\Library\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Library\GoogleAds\Reporting\GoogleAdsSearchTermClass;

/**
 * Google Ads Module V1 — the small, pure presentation helpers the data pages
 * share (so no page re-implements the "absent is a dash, never 0" rules).
 * Money goes through GoogleAdsMoney; nothing here touches a database or a
 * provider, and every returned string is plain text for an escaped Blade
 * echo.
 */
final class GoogleAdsDisplay
{
    public const DASH = '—';

    public static function money(?int $micros, ?string $currency): string
    {
        return GoogleAdsMoney::format($micros, $currency);
    }

    /** Google's conversion value (a 6-place decimal string) as money, or a dash. */
    public static function decimalMoney(?string $decimal, ?string $currency): string
    {
        if ($decimal === null) {
            return self::DASH;
        }

        $micros = GoogleAdsMoney::toMicros($decimal);

        return $micros === null ? self::DASH : GoogleAdsMoney::format($micros, $currency);
    }

    /** "3", "2.5", "—": a fractional count without trailing zeros. */
    public static function count(?string $value): string
    {
        if ($value === null) {
            return self::DASH;
        }

        $float = (float) $value;

        return abs($float - round($float)) < 0.005 ? number_format((int) round($float)) : number_format($float, 2);
    }

    public static function integer(?int $value): string
    {
        return $value === null ? self::DASH : number_format($value);
    }

    public static function percent(?float $rate): string
    {
        return $rate === null ? self::DASH : number_format($rate * 100, 1) . '%';
    }

    public static function statusLabel(GoogleAdsEntityStatus $status): string
    {
        return match ($status) {
            GoogleAdsEntityStatus::Enabled => 'Enabled',
            GoogleAdsEntityStatus::Paused => 'Paused',
            GoogleAdsEntityStatus::Removed => 'Removed',
            GoogleAdsEntityStatus::Unknown => 'Unknown',
        };
    }

    public static function statusVariant(GoogleAdsEntityStatus $status): string
    {
        return $status === GoogleAdsEntityStatus::Enabled ? 'success' : 'neutral';
    }

    public static function matchLabel(?GoogleAdsMatchType $match): string
    {
        return $match === null ? self::DASH : ucfirst(strtolower($match->value));
    }

    public static function termClassLabel(GoogleAdsSearchTermClass $class): string
    {
        return match ($class) {
            GoogleAdsSearchTermClass::PotentialWaste => 'Potential waste',
            GoogleAdsSearchTermClass::Converting => 'Converting',
            GoogleAdsSearchTermClass::Unreviewed => 'Unreviewed',
            GoogleAdsSearchTermClass::Excluded => 'Excluded',
            GoogleAdsSearchTermClass::Ignored => 'Ignored',
        };
    }

    /** Potential waste is amber information, never red; converting is green. */
    public static function termClassVariant(GoogleAdsSearchTermClass $class): string
    {
        return match ($class) {
            GoogleAdsSearchTermClass::PotentialWaste => 'warning',
            GoogleAdsSearchTermClass::Converting => 'success',
            default => 'neutral',
        };
    }
}
