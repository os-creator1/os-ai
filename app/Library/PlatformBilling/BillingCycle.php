<?php

namespace App\Library\PlatformBilling;

/**
 * THE single source of truth for what a lane-A billing cycle MEANS.
 *
 * `workspace_plan_catalog.billing_cycle` is a free-form short string, and three
 * different places used to interpret it on their own (the Price parity check,
 * the owner forms, and the customer-facing price copy). Centralising it here is
 * what keeps "Every 28 days" from being "monthly" in one place and 28 days in
 * another.
 *
 *   every_28_days  Stripe `recurring.interval=day`, `interval_count=28`. A
 *                  calendar month is 28 to 31 days, so a "monthly" Stripe Price
 *                  is NOT an exact 28-day cadence; this is the platform's
 *                  standard Core / Growth / Agency cadence.
 *   yearly         Stripe `recurring.interval=year`, `interval_count=1`. Kept
 *                  for any plan that is sold yearly.
 *   monthly        LEGACY. Stripe `recurring.interval=month`, `interval_count=1`.
 *                  Still understood so that existing subscriptions and any
 *                  catalog row that has not been moved are described honestly,
 *                  but the owner forms no longer offer it for new configuration.
 */
final class BillingCycle
{
    public const EVERY_28_DAYS = 'every_28_days';
    public const YEARLY = 'yearly';
    public const MONTHLY = 'monthly';

    /** The exact length of one standard billing period, in days. */
    public const PERIOD_DAYS = 28;

    /** @return array<int, string> the cycles an owner may CONFIGURE today */
    public static function configurable(): array
    {
        return [self::EVERY_28_DAYS, self::YEARLY];
    }

    /** @return array<int, string> every cycle the platform can still describe */
    public static function known(): array
    {
        return [self::EVERY_28_DAYS, self::YEARLY, self::MONTHLY];
    }

    /**
     * The validation rule for an owner-submitted cycle. A catalog row that is
     * still on legacy `monthly` may keep it (so saving an unrelated field on an
     * un-migrated row does not suddenly fail), but nothing new is moved onto it.
     */
    public static function validationList(?string $current = null): string
    {
        $allowed = self::configurable();

        if ($current === self::MONTHLY) {
            $allowed[] = self::MONTHLY;
        }

        return implode(',', $allowed);
    }

    /**
     * @return array{0: string, 1: int}|null Stripe [interval, interval_count]
     */
    public static function stripeRecurrence(string $cycle): ?array
    {
        return match (mb_strtolower(trim($cycle))) {
            self::EVERY_28_DAYS => ['day', self::PERIOD_DAYS],
            self::YEARLY => ['year', 1],
            self::MONTHLY => ['month', 1],
            default => null,
        };
    }

    /** Customer- and owner-facing wording. Never "monthly" for the 28-day cycle. */
    public static function label(string $cycle): string
    {
        return match (mb_strtolower(trim($cycle))) {
            self::EVERY_28_DAYS => __('Every 28 days'),
            self::YEARLY => __('Yearly'),
            self::MONTHLY => __('Monthly'),
            default => $cycle,
        };
    }

    /** Short suffix form: "/ every 28 days". */
    public static function perLabel(string $cycle): string
    {
        return match (mb_strtolower(trim($cycle))) {
            self::EVERY_28_DAYS => __('every 28 days'),
            self::YEARLY => __('year'),
            self::MONTHLY => __('month'),
            default => $cycle,
        };
    }

    /**
     * The instant `$periods` whole billing periods after `$from`.
     *
     * Day-based cycles are exact multiples of 24 hours; Stripe advances a
     * `day` interval in whole days from the billing anchor, so 28 days after an
     * anchor is precisely where the 28-day period boundary falls. The other
     * cycles use calendar arithmetic without month overflow.
     */
    public static function addPeriods(\Carbon\CarbonInterface $from, string $cycle, int $periods): \Carbon\CarbonImmutable
    {
        $start = \Carbon\CarbonImmutable::instance($from);

        return match (mb_strtolower(trim($cycle))) {
            self::YEARLY => $start->addYearsNoOverflow($periods),
            self::MONTHLY => $start->addMonthsNoOverflow($periods),
            default => $start->addDays(self::PERIOD_DAYS * $periods),
        };
    }

    public static function isEvery28Days(string $cycle): bool
    {
        return mb_strtolower(trim($cycle)) === self::EVERY_28_DAYS;
    }
}
