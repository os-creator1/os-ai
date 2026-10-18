<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers\Concerns;

/**
 * Shared bucket arithmetic for readers that report "count, value, uids" per
 * Location. Money is summed ONLY inside one currency: a bucket holding two
 * currencies reports no value at all rather than adding dollars to euros.
 */
trait BucketsByLocation
{
    /** The maximum record uids a bucket keeps as evidence (bounded, PII-free). */
    private const UID_CAP = 10;

    /** @return array{count: int, value_minor: int, currency: string|null, mixed_currency: bool, uids: array<int, string>} */
    private function emptyBucket(): array
    {
        return ['count' => 0, 'value_minor' => 0, 'currency' => null, 'mixed_currency' => false, 'uids' => []];
    }

    /**
     * @param  array{count: int, value_minor: int, currency: string|null, mixed_currency: bool, uids: array<int, string>}  $bucket
     * @return array{count: int, value_minor: int, currency: string|null, mixed_currency: bool, uids: array<int, string>}
     */
    private function addToBucket(array $bucket, string $uid, ?int $valueMinor, ?string $currency): array
    {
        $bucket['count']++;

        if (count($bucket['uids']) < self::UID_CAP) {
            $bucket['uids'][] = $uid;
        }

        if ($valueMinor !== null && $valueMinor > 0 && $currency !== null && $currency !== '') {
            if ($bucket['currency'] === null) {
                $bucket['currency'] = $currency;
            }

            if ($bucket['currency'] !== $currency) {
                $bucket['mixed_currency'] = true;
            } else {
                $bucket['value_minor'] += $valueMinor;
            }
        }

        return $bucket;
    }

    /**
     * The evidence-safe money view of a bucket: value and currency, or both
     * null when there is no canonical value or the bucket mixes currencies.
     *
     * @param  array{count: int, value_minor: int, currency: string|null, mixed_currency: bool, uids: array<int, string>}  $bucket
     * @return array{value_minor: int|null, currency: string|null}
     */
    private function money(array $bucket): array
    {
        if ($bucket['mixed_currency'] || $bucket['currency'] === null || $bucket['value_minor'] <= 0) {
            return ['value_minor' => null, 'currency' => null];
        }

        return ['value_minor' => $bucket['value_minor'], 'currency' => $bucket['currency']];
    }

    /** Location key used inside fact arrays: the Location id, 0 for "no Location". */
    private function locationKey(mixed $locationId): int
    {
        return $locationId === null ? 0 : (int) $locationId;
    }
}
