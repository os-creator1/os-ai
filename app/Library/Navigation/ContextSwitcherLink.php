<?php

namespace App\Library\Navigation;

/**
 * A plain GET destination inside the context switcher (for example the
 * account's own settings page). Always a link the actor is authorized to
 * open: the page it names enforces that authorization itself, and the
 * switcher only mirrors the same rule so it never offers a 404.
 */
final class ContextSwitcherLink
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
    ) {
    }
}
