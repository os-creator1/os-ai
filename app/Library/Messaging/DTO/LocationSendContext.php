<?php

namespace App\Library\Messaging\DTO;

/**
 * The Location a send speaks for — handed to the messaging seam by a caller that
 * must PROVE its sender belongs to a Location (Automations). A caller that passes
 * none gets today's behaviour: the Business's one sender, unchanged.
 *
 * `locationId` is the one Location the send is pinned to (null when its journey has
 * none). `scopeBound` says the workflow itself is limited to Locations (one or
 * selected), which is what makes an unassigned Business-level sender unprovable
 * for a Business with several Locations.
 */
final readonly class LocationSendContext
{
    public function __construct(
        public ?int $locationId,
        public bool $scopeBound,
    ) {
    }

    /** Stable text for an idempotency key; empty for no pinned Location. */
    public function keyFragment(): string
    {
        return $this->locationId === null ? '' : '|loc:' . $this->locationId;
    }
}
