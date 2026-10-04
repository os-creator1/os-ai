<?php

namespace App\Library\GoogleAds\Reporting;

use App\Models\Business;
use App\Models\GoogleAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Google Ads Module V1 contract §9 — the four reporting periods, resolved to
 * INCLUSIVE local calendar dates [from, to] in the Ads ACCOUNT time zone.
 *
 *   last_7          today - 6 days .. today          (rolling, like the Analytics presets)
 *   last_30         today - 29 days .. today         (default)
 *   this_month      first of the month .. today
 *   previous_month  first .. last day of the previous calendar month
 *
 * "Today" is the account-local date at $now, so a Business whose month rolls
 * over at 02:00 UTC but whose Ads account is in Los Angeles still reports the
 * old month until the account's own midnight. Dates are plain Y-m-d day
 * numbers compared against `metric_date` DATE columns; no time-of-day
 * arithmetic exists anywhere, so DST can never move a day.
 *
 * An unknown / invalid account time zone falls back to the Business timezone
 * and then UTC (never throws). An unknown period key falls back to last_30.
 *
 * Pure value object apart from resolve()'s optional Business lookup. It never
 * touches a provider: changing the period is a different cached-row filter.
 */
final class GoogleAdsPeriod
{
    public const LAST_7 = 'last_7';

    public const LAST_30 = 'last_30';

    public const THIS_MONTH = 'this_month';

    public const PREVIOUS_MONTH = 'previous_month';

    public const DEFAULT = self::LAST_30;

    /** Display order of the range control. */
    public const KEYS = [self::LAST_7, self::LAST_30, self::THIS_MONTH, self::PREVIOUS_MONTH];

    /** @var array<int, string>|null */
    private static ?array $knownZones = null;

    private function __construct(
        public readonly string $key,
        public readonly string $timezone,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly CarbonImmutable $today,
    ) {
    }

    /** Resolves in the account's own time zone (with the documented fallbacks). */
    public static function resolve(?string $key, GoogleAdsAccount $account, ?CarbonImmutable $now = null): self
    {
        return self::resolveIn($key, self::timezoneFor($account), $now);
    }

    /** Pure resolution in an already-valid IANA zone (invalid => UTC). */
    public static function resolveIn(?string $key, string $timezone, ?CarbonImmutable $now = null): self
    {
        $timezone = self::isValidTimezone($timezone) ? $timezone : 'UTC';
        $key = in_array($key, self::KEYS, true) ? (string) $key : self::DEFAULT;
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();

        [$from, $to] = match ($key) {
            self::LAST_7 => [$today->subDays(6), $today],
            self::THIS_MONTH => [$today->startOfMonth(), $today],
            self::PREVIOUS_MONTH => [
                $today->startOfMonth()->subMonthNoOverflow(),
                $today->startOfMonth()->subMonthNoOverflow()->endOfMonth()->startOfDay(),
            ],
            default => [$today->subDays(29), $today],
        };

        return new self($key, $timezone, $from, $to, $today);
    }

    /** Account zone, else Business zone, else UTC. */
    public static function timezoneFor(GoogleAdsAccount $account): string
    {
        $zone = trim((string) $account->time_zone);

        if (self::isValidTimezone($zone)) {
            return $zone;
        }

        $business = trim((string) Business::query()->whereKey($account->business_id)->value('timezone'));

        return self::isValidTimezone($business) ? $business : 'UTC';
    }

    public static function isValidTimezone(string $zone): bool
    {
        self::$knownZones ??= \DateTimeZone::listIdentifiers();

        return $zone !== '' && in_array($zone, self::$knownZones, true);
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->format('Y-m-d');
    }

    /** Inclusive number of local calendar dates. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * The comparison window: the same number of days immediately before a
     * rolling period; for this_month the previous month over the same
     * day-of-month span; for previous_month the month before it.
     */
    public function comparison(): self
    {
        $previousMonthStart = $this->from->startOfMonth()->subMonthNoOverflow();

        [$from, $to] = match ($this->key) {
            self::THIS_MONTH => [
                $previousMonthStart,
                $previousMonthStart->addDays(min($this->to->day, $previousMonthStart->daysInMonth) - 1),
            ],
            self::PREVIOUS_MONTH => [$previousMonthStart, $previousMonthStart->endOfMonth()->startOfDay()],
            default => [$this->from->subDays($this->days()), $this->from->subDay()],
        };

        return new self('comparison', $this->timezone, $from, $to, $this->today);
    }

    /** @return array{key: string, from: string, to: string, timezone: string, days: int} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'timezone' => $this->timezone,
            'days' => $this->days(),
        ];
    }
}
