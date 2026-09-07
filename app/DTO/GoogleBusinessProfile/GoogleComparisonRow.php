<?php

namespace App\DTO\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleComparisonStatus;

/**
 * GBP Slice A contract §22.1 — exactly four columns: Field, Platform
 * value, Google value, Status. `reason` is a short, own-vocabulary
 * explanation shown only for NotComparable rows.
 *
 * This DTO is built at read time and NEVER persisted (contract §13.3).
 * There is no score, percentage, grade or "proposed change" field, and one
 * must not be added until a mutation slice contracts it.
 */
final readonly class GoogleComparisonRow
{
    public function __construct(
        public string $field,
        public ?string $platformValue,
        public ?string $googleValue,
        public GoogleComparisonStatus $status,
        public ?string $reason = null,
    ) {
    }
}
