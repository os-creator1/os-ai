<?php

namespace App\Library\Timeline;

/**
 * A person's timeline, oldest first, ready to render.
 *
 * `truncated` means older activity exists than the window shows. The window is
 * cut at one moment for every source, so what IS shown is complete: never newer
 * messages alongside only some of the older automation events.
 */
final class TimelinePage
{
    /**
     * @param  list<TimelineItem>  $items
     */
    public function __construct(
        public readonly TimelineSubject $subject,
        public readonly array $items,
        public readonly bool $truncated,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
