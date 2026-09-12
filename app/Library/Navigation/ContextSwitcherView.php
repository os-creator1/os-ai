<?php

namespace App\Library\Navigation;

/**
 * Everything the one context switcher renders, for either frame.
 *
 * Built only by ContextSwitcherPresenter from the already-resolved
 * CustomerContext, so the block at the top of the sidebar and the compact
 * control the horizontal layout shows are the same control with the same
 * rules — never two selectors that could disagree.
 */
final class ContextSwitcherView
{
    /**
     * @param  array<int, ContextSwitcherOption>  $businesses
     * @param  array<int, ContextSwitcherOption>  $accounts
     * @param  array<int, ContextSwitcherLink>  $links
     */
    public function __construct(
        public readonly bool $interactive,
        public readonly string $frameLabel,
        public readonly string $currentName,
        public readonly string $toggleAriaLabel,
        public readonly string $identityAriaLabel,
        public readonly string $businessesHeading,
        public readonly string $accountsHeading,
        public readonly array $businesses,
        public readonly array $accounts,
        public readonly array $links,
        public readonly bool $showsFilter,
        public readonly string $filterLabel,
    ) {
    }
}
