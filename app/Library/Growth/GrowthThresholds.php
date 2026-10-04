<?php

declare(strict_types=1);

namespace App\Library\Growth;

/**
 * The ONLY reader of config/growth.php thresholds. Every value is clamped to
 * a range a rule can safely act on, so a bad .env/config value can neither
 * disable a rule by accident (a 0-hour threshold flags everything) nor make
 * it unreachable (a 10-year threshold flags nothing).
 */
final class GrowthThresholds
{
    /** key => [default, min, max] */
    private const BOUNDS = [
        'unanswered_lead_hours' => [24, 1, 24 * 14],
        'stale_deal_days' => [7, 1, 180],
        'high_value_deal_minor' => [50000, 1, 100_000_000],
        'conversation_awaiting_hours' => [24, 1, 24 * 14],
        'conversation_lookback_days' => [30, 1, 365],
        'low_availability_open_minutes' => [240, 0, 60 * 24 * 7],
        'proposal_unsigned_days' => [3, 1, 90],
        'signed_unpaid_days' => [3, 1, 90],
        'failed_payment_lookback_days' => [14, 1, 90],
        'review_request_lookback_days' => [30, 1, 365],
        'citation_priority_directories' => [5, 1, 50],
        'dismiss_cooldown_days' => [30, 1, 365],
        'min_sample' => [8, 1, 1000],
        'automation_failure_min' => [3, 1, 100],
    ];

    public function get(string $key): int
    {
        if (! isset(self::BOUNDS[$key])) {
            throw new \InvalidArgumentException("Unknown Growth threshold [{$key}].");
        }

        [$default, $min, $max] = self::BOUNDS[$key];
        $configured = config('growth.thresholds.' . $key, $default);

        if (! is_numeric($configured)) {
            return $default;
        }

        return max($min, min($max, (int) $configured));
    }

    /** @return array<string, int> every threshold, for the "how this works" panel and snapshots */
    public function all(): array
    {
        $all = [];

        foreach (array_keys(self::BOUNDS) as $key) {
            $all[$key] = $this->get($key);
        }

        return $all;
    }
}
