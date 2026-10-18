<?php

namespace App\Library\MetaAds;

/**
 * Meta Ads Module V1 — the small, pure presentation helpers the Meta data
 * pages share (absent is a dash, never 0; provider status codes become owner
 * words). Mirrors GoogleAdsDisplay under Meta names. No database, no provider;
 * every returned string is plain text for an escaped Blade echo.
 */
final class MetaAdsDisplay
{
    public const DASH = '—';

    /** Effective statuses Meta quotes as a delivery problem (contract 24 §12, delivery_issue). */
    public const ISSUE_STATUSES = ['WITH_ISSUES', 'DISAPPROVED', 'PENDING_BILLING_INFO'];

    public static function money(?int $micros, ?string $currency): string
    {
        return MetaAdsMoney::format($micros, $currency);
    }

    /** A Meta budget (minor units of the account currency) as money, or a dash. */
    public static function minorMoney(?int $minor, ?string $currency): string
    {
        if ($minor === null) {
            return self::DASH;
        }

        try {
            return MetaAdsMoney::format(MetaAdsMoney::minorToMicros($minor, (string) $currency), $currency);
        } catch (\Throwable) {
            return self::DASH;
        }
    }

    /** Meta's reported value (a 6-place decimal string) as money, or a dash. */
    public static function decimalMoney(?string $decimal, ?string $currency): string
    {
        if ($decimal === null) {
            return self::DASH;
        }

        $micros = MetaAdsMoney::tryParseToMicros($decimal);

        return $micros === null ? self::DASH : MetaAdsMoney::format($micros, $currency);
    }

    /** "3", "2.5", dash: a fractional count without trailing zeros. */
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
        return $rate === null ? self::DASH : number_format($rate * 100, 2) . '%';
    }

    /** A frequency decimal string ("2.4000") as "2.4", or a dash. */
    public static function frequency(?string $value): string
    {
        if ($value === null || ! is_numeric($value)) {
            return self::DASH;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /** The account's chosen result label ("Leads (website)"), or null when none is chosen. */
    public static function resultLabel(?string $actionType): ?string
    {
        if ($actionType === null || $actionType === '') {
            return null;
        }

        return app(MetaAdsConfig::class)->resultTypes()[$actionType] ?? null;
    }

    /**
     * The status word to show: the provider's effective status, EXCEPT when it
     * only contradicts the configured on/off switch (a plain ACTIVE / PAUSED
     * that disagrees with `status`). That happens right after a pause / resume
     * we made, before the next sync refreshes `effective_status`; showing the
     * stale word would put "Active" next to a "Resume" button.
     */
    private static function displayKey(string $status, ?string $effective): string
    {
        $configured = strtoupper($status);
        $key = strtoupper($effective !== null && $effective !== '' ? $effective : $status);

        if (in_array($key, ['ACTIVE', 'PAUSED'], true) && $key !== $configured
            && in_array($configured, ['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED'], true)) {
            return $configured;
        }

        return $key;
    }

    /** Provider status (effective status first) in owner words. */
    public static function statusLabel(string $status, ?string $effective): string
    {
        $key = self::displayKey($status, $effective);

        return match ($key) {
            'ACTIVE' => 'Active',
            'PAUSED' => 'Paused',
            'CAMPAIGN_PAUSED' => 'Paused (campaign paused)',
            'ADSET_PAUSED' => 'Paused (ad set paused)',
            'DELETED' => 'Deleted',
            'ARCHIVED' => 'Archived',
            'IN_PROCESS' => 'Processing',
            'WITH_ISSUES' => 'Active, with issues',
            'PENDING_REVIEW' => 'In review',
            'DISAPPROVED' => 'Not approved',
            'PENDING_BILLING_INFO' => 'Billing info needed',
            default => ucfirst(strtolower(str_replace('_', ' ', mb_substr($key, 0, 40)))),
        };
    }

    public static function statusVariant(string $status, ?string $effective): string
    {
        $key = self::displayKey($status, $effective);

        return match (true) {
            $key === 'ACTIVE' => 'success',
            in_array($key, self::ISSUE_STATUSES, true), $key === 'PENDING_REVIEW' => 'warning',
            default => 'neutral',
        };
    }

    /** Meta's own words for a delivery problem, quoted ("Meta reports: ..."), or null. */
    public static function issueText(?string $effective): ?string
    {
        $key = strtoupper((string) $effective);

        if (! in_array($key, self::ISSUE_STATUSES, true)) {
            return null;
        }

        return 'Meta reports: ' . strtolower(str_replace('_', ' ', $key));
    }

    /** Whether a pause / resume button is offered for a configured status. */
    public static function actionFor(string $status, ?string $effective = null, bool $isAd = false): ?string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'pause',
            'PAUSED' => $isAd && in_array(strtoupper((string) $effective), ['DISAPPROVED', 'PENDING_REVIEW'], true) ? null : 'resume',
            default => null,
        };
    }
}
