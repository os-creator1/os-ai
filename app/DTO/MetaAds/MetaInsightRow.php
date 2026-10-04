<?php

namespace App\DTO\MetaAds;

use InvalidArgumentException;

/**
 * Meta Ads Module V1 contract §5 — one day of insights for one entity.
 *
 * `level` is the LOCAL vocabulary `campaign|ad_set|ad`. Absence is null, never
 * 0. `spendMicros` is BIGINT micros of the account currency. `results` holds
 * ONLY action types present in config('meta_ads.result_types'):
 * actionType => ['count' => int, 'value' => ?int] where `value` is the
 * Meta-reported `action_values` amount in MICROS (null when Meta sent none).
 * An empty `results` means Meta reported no allow-listed action that day.
 */
final readonly class MetaInsightRow
{
    public const LEVELS = ['campaign', 'ad_set', 'ad'];

    /**
     * @param  array<string, array{count: int, value: ?int}>  $results
     */
    public function __construct(
        public string $level,
        public string $externalId,
        public string $date,
        public ?int $spendMicros,
        public ?int $impressions,
        public ?int $clicks,
        public ?int $linkClicks,
        public array $results = [],
    ) {
        if (! in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('Unknown insight level.');
        }

        if (preg_match('/\A\d{1,20}\z/', $externalId) !== 1 || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            throw new InvalidArgumentException('Invalid insight row identity.');
        }
    }
}
