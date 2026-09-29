<?php

namespace App\DTO\Calendar;

/**
 * Implementation Contract 15 §5.6/§12.F — the result of one full or
 * incremental provider read.
 *
 * `complete` is true only for a full sync whose ENTIRE paginated read
 * succeeded — the reconciliation (delete-by-absence) step in
 * ExternalCalendarSyncService is conditional on this flag, never applied
 * for an incremental page or a partial/aborted full read. A provider client
 * implementation must throw ExternalCalendarProviderException rather than
 * return a partial page with complete=true.
 */
final class ExternalCalendarSyncPage
{
    /**
     * @param  array<int, ExternalCalendarBusyEvent>  $events
     */
    public function __construct(
        public readonly array $events,
        public readonly ?string $nextCursor,
        public readonly bool $complete,
    ) {
    }
}
