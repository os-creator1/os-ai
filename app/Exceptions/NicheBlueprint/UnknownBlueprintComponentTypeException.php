<?php

namespace App\Exceptions\NicheBlueprint;

use RuntimeException;

/**
 * Contract 20 §6.2 check 1 / §10 — no adapter is registered for a
 * `component_type`.
 *
 * In practice this is a PUBLISH-time refusal: a version naming a component
 * type with no registered adapter cannot be published, which is what keeps
 * adapters additive and stops an installation ever reaching a missing
 * installer. It exists as a typed exception rather than a bare
 * `RuntimeException` so the publisher (Sub-slice B) can turn it into a precise
 * operator-facing refusal, and so a lookup never degrades into a null the
 * caller might ignore.
 */
class UnknownBlueprintComponentTypeException extends RuntimeException
{
    public function __construct(public readonly string $componentType)
    {
        parent::__construct(
            "No Blueprint component adapter is registered for component type [{$componentType}]."
        );
    }
}
