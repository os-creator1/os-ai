<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown only by rollback Preflight B (migration 2026_08_24_120003's
 * down()) — never by any request-time path, and never by the forward
 * Slice 3 migrations. Names the exact would-be legacy identity that
 * cannot be restored: two or more business_usage_rates rows would
 * collide on (feature_key, version) once business_usage_rates_feature_key_version_unique
 * is recreated, because the meter-local final allocator legitimately let
 * distinct sibling meters reuse the same version number after Slice 3
 * (RFC-005 Amendment 1 Slice 3 CONTRACT §5.2).
 */
class UsageMeterRollbackVersionCollisionException extends RuntimeException
{
    public function __construct(
        public readonly string $featureKey,
        public readonly int $version,
        public readonly int $collidingRowCount,
    ) {
        parent::__construct(
            "Slice 3 rollback cannot restore business_usage_rates_feature_key_version_unique: "
            . "{$collidingRowCount} row(s) would collide on feature_key '{$featureKey}', version {$version}."
        );
    }
}
