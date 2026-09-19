<?php

namespace App\Exceptions\NicheBlueprint;

/**
 * Contract 20 §6.2 check 6 — `component_key` must be unique within a version.
 *
 * `component_key` is the durable installation identity (§5.3): two components
 * sharing one key inside a version would make "this Business already has this
 * component" ambiguous forever.
 */
class DuplicateComponentKeyException extends BlueprintAuthoringException
{
    public function __construct(public readonly int $versionId, public readonly string $componentKey)
    {
        parent::__construct(
            "Blueprint version [{$versionId}] already has a component keyed [{$componentKey}]."
        );
    }
}
