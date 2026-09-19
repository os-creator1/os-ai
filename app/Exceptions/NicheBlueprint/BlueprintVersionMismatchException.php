<?php

namespace App\Exceptions\NicheBlueprint;

/**
 * Contract 20 §5.3 — a version, or a component, does not belong to the
 * Blueprint the caller named.
 *
 * The composite foreign key already makes the component/version/Blueprint
 * triple impossible to store inconsistently; this is that same invariant
 * checked at the service boundary, so a caller passing a mismatched pair gets
 * a precise refusal instead of a confusing downstream failure.
 */
class BlueprintVersionMismatchException extends BlueprintAuthoringException
{
    public function __construct(
        public readonly int $versionId,
        public readonly int $expectedBlueprintId,
        public readonly int $actualBlueprintId,
    ) {
        parent::__construct(
            "Blueprint version [{$versionId}] belongs to Blueprint [{$actualBlueprintId}], not [{$expectedBlueprintId}]."
        );
    }
}
