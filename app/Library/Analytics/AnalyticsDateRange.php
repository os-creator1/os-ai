<?php

namespace App\Library\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * B5 Business Analytics — the selected reporting window (contract §4).
 *
 * Everything is computed in the Business's own timezone and then applied
 * as ONE explicit half-open interval on the storage timezone
 * (`created_at >= startUtc AND created_at < endUtc`, where endUtc is the
 * start of the local day AFTER the final selected local date). Each daily
 * bucket boundary is derived independently from its own local calendar
 * date — never by adding a fixed 86 400-second offset — so a 23-hour
 * spring-forward day and a 25-hour fall-back day are each exactly one
 * bucket (§4.5). Carbon's own DST resolution is used, never a custom one.
 *
 * Forbidden mechanics (§4.4) never appear here or in the queries built
 * from this object: whereDate(), DATE()/DAY(), date-only whereBetween,
 * a silent strtotime() fallback, or CONVERT_TZ() in the range filter.
 */
final class AnalyticsDateRange
{
    public const PRESET_LAST_7_DAYS = 'last_7_days';
    public const PRESET_LAST_30_DAYS = 'last_30_days';
    public const PRESET_LAST_90_DAYS = 'last_90_days';
    public const PRESET_CUSTOM = 'custom';

    public const DEFAULT_PRESET = self::PRESET_LAST_30_DAYS;

    /** Contract §4.2 — inclusive maximum for a custom range. */
    public const MAX_CUSTOM_DAYS = 92;

    public const PRESETS = [
        self::PRESET_LAST_7_DAYS => 7,
        self::PRESET_LAST_30_DAYS => 30,
        self::PRESET_LAST_90_DAYS => 90,
    ];

    private function __construct(
        public readonly string $preset,
        public readonly string $timezone,
        public readonly CarbonImmutable $startLocal,
        public readonly CarbonImmutable $endLocal,
        public readonly CarbonImmutable $startUtc,
        public readonly CarbonImmutable $endUtc,
    ) {
    }

    /**
     * Builds the range from ALREADY shape-validated request input (the
     * shape rules live in AnalyticsRangeRequest). Semantic rules — the
     * 92-day cap, a reversed range, an unparseable date — are enforced
     * here and raised as ValidationException so both HTML and JSON callers
     * answer with a validation failure, never a silent clamp or a 1970
     * fallback.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, string $timezone, ?CarbonImmutable $today = null): self
    {
        $preset = (string) ($input['range'] ?? self::DEFAULT_PRESET);

        if ($preset === '') {
            $preset = self::DEFAULT_PRESET;
        }

        $todayLocal = ($today ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();

        if (isset(self::PRESETS[$preset])) {
            $days = self::PRESETS[$preset];

            return self::build($preset, $timezone, $todayLocal->subDays($days - 1), $todayLocal);
        }

        if ($preset !== self::PRESET_CUSTOM) {
            throw ValidationException::withMessages(['range' => 'Choose a supported date range.']);
        }

        $start = self::parseLocalDate((string) ($input['start'] ?? ''), $timezone, 'start');
        $end = self::parseLocalDate((string) ($input['end'] ?? ''), $timezone, 'end');

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['end' => 'The end date must be on or after the start date.']);
        }

        $inclusiveDays = (int) $start->diffInDays($end) + 1;

        if ($inclusiveDays > self::MAX_CUSTOM_DAYS) {
            throw ValidationException::withMessages(['end' => 'A custom range may cover at most ' . self::MAX_CUSTOM_DAYS . ' days.']);
        }

        return self::build(self::PRESET_CUSTOM, $timezone, $start, $end);
    }

    public static function preset(string $preset, string $timezone, ?CarbonImmutable $today = null): self
    {
        return self::fromInput(['range' => $preset], $timezone, $today);
    }

    private static function build(string $preset, string $timezone, CarbonImmutable $startLocal, CarbonImmutable $endLocal): self
    {
        return new self(
            $preset,
            $timezone,
            $startLocal->startOfDay(),
            $endLocal->startOfDay(),
            self::localDayStartInStorageTz($startLocal),
            self::localDayStartInStorageTz($endLocal->addDay()),
        );
    }

    /**
     * Strict `Y-m-d` only: the parsed value must round-trip to the input,
     * so `2026-02-31` or free text can never silently become another day.
     */
    private static function parseLocalDate(string $value, string $timezone, string $field): CarbonImmutable
    {
        $parsed = null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            } catch (InvalidArgumentException) {
                $parsed = null;
            }
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'Enter the ' . $field . ' date as YYYY-MM-DD.']);
        }

        return $parsed->startOfDay();
    }

    /**
     * The storage timezone is config('app.timezone') — UTC in this
     * application (§4.3) — so this IS the UTC boundary; it is read from
     * config rather than hardcoded so the interval can never disagree with
     * what the database actually stores.
     */
    private static function localDayStartInStorageTz(CarbonImmutable $localDate): CarbonImmutable
    {
        return $localDate->startOfDay()->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** Number of local calendar dates in the range (inclusive). */
    public function days(): int
    {
        return (int) $this->startLocal->diffInDays($this->endLocal) + 1;
    }

    /**
     * One bucket per local calendar date, each boundary computed from its
     * own date (§4.5).
     *
     * @return array<int, array{date: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function dailyBuckets(): array
    {
        $buckets = [];
        $cursor = $this->startLocal;

        while ($cursor->lessThanOrEqualTo($this->endLocal)) {
            $buckets[] = [
                'date' => $cursor->format('Y-m-d'),
                'start' => self::localDayStartInStorageTz($cursor),
                'end' => self::localDayStartInStorageTz($cursor->addDay()),
            ];

            $cursor = $cursor->addDay();
        }

        return $buckets;
    }

    /** Contract §11.3 — participates in the Business-scoped cache key. */
    public function cacheKey(): string
    {
        if ($this->preset === self::PRESET_CUSTOM) {
            return 'custom_' . $this->startLocal->format('Y-m-d') . '_' . $this->endLocal->format('Y-m-d');
        }

        return $this->preset;
    }

    public function label(): string
    {
        return match ($this->preset) {
            self::PRESET_LAST_7_DAYS => 'Last 7 days',
            self::PRESET_LAST_30_DAYS => 'Last 30 days',
            self::PRESET_LAST_90_DAYS => 'Last 90 days',
            default => $this->startLocal->format('M j, Y') . ' to ' . $this->endLocal->format('M j, Y'),
        };
    }

    /** @return array<string, string> */
    public function queryParameters(): array
    {
        if ($this->preset === self::PRESET_CUSTOM) {
            return ['range' => self::PRESET_CUSTOM, 'start' => $this->startLocal->format('Y-m-d'), 'end' => $this->endLocal->format('Y-m-d')];
        }

        return ['range' => $this->preset];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'label' => $this->label(),
            'timezone' => $this->timezone,
            'start_local' => $this->startLocal->format('Y-m-d'),
            'end_local' => $this->endLocal->format('Y-m-d'),
            'start_utc' => $this->startUtc->toIso8601String(),
            'end_utc' => $this->endUtc->toIso8601String(),
            'days' => $this->days(),
        ];
    }
}
