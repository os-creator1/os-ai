<?php

namespace Tests\Feature\Conversations\Support;

use App\Library\Timeline\Contracts\TimelineSource;
use App\Library\Timeline\TimelineItem;
use App\Library\Timeline\TimelineSubject;

/**
 * A future domain, stood in for by the test: whatever items the test puts in
 * `$items`, returned the way a real source must — newest first, bounded by the
 * limit the timeline asks for.
 */
final class StubTimelineSource implements TimelineSource
{
    /** @var list<TimelineItem> newest first */
    public static array $items = [];

    public function recent(TimelineSubject $subject, int $limit): array
    {
        return array_slice(self::$items, 0, $limit);
    }
}
