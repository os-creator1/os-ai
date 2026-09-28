<?php

namespace App\DTO\Calendar;

use Carbon\CarbonInterface;

/**
 * Implementation Contract 15 §5.6 — one provider event as reported by a
 * full, incremental or webhook-triggered read. `deleted` distinguishes a
 * tombstone (Google `status: "cancelled"`, Microsoft Graph `@removed`) from
 * an ordinary busy interval; a tombstone carries no interval.
 */
final class ExternalCalendarBusyEvent
{
    public function __construct(
        public readonly string $providerEventId,
        public readonly bool $deleted,
        public readonly ?CarbonInterface $startAt = null,
        public readonly ?CarbonInterface $endAt = null,
        public readonly ?string $busyType = null,
    ) {
    }
}
