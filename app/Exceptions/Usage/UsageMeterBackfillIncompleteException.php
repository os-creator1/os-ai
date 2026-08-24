<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by Slice 3's global forward preflight (migration
 * 2026_08_24_120001's up()) and by rollback Preflight A (migration
 * 2026_08_24_120003's down()) — never by reserve(), setActiveRate(),
 * activateMetering(), or any other request-time code path (RFC-005
 * Amendment 1 Slice 3 CONTRACT §5.1).
 */
class UsageMeterBackfillIncompleteException extends RuntimeException
{
    public function __construct(public readonly string $table, public readonly int $remainingCount)
    {
        parent::__construct(
            "UsageMeter backfill incomplete: {$remainingCount} row(s) in {$table} still have no meter_key."
        );
    }
}
