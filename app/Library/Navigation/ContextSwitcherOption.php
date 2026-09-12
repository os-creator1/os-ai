<?php

namespace App\Library\Navigation;

/**
 * One destination the context switcher offers: a Business to enter, or an
 * account whose frame to enter.
 *
 * Presentation data only. Every option posts to a server-authorized action
 * (SwitchBusinessAction / SwitchAccountAction) that re-resolves the uids and
 * re-runs the canonical authorization check; nothing is ever granted because
 * an option was rendered (Slice 1B contract §18 S-3).
 */
final class ContextSwitcherOption
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $subtitle,
        public readonly string $switchUrl,
        public readonly string $workspaceUid,
        public readonly ?string $businessUid,
        public readonly bool $isCurrent,
        public readonly ?string $viewAsUrl = null,
    ) {
    }

    /**
     * Whether choosing this option would actually change anything. The
     * current context is listed for orientation, never as an action.
     */
    public function isActionable(): bool
    {
        return ! $this->isCurrent;
    }
}
