<?php

namespace App\Exceptions\NicheBlueprint;

/**
 * A draft with no components cannot be published.
 *
 * CONTRACT 20 DOES NOT STATE THIS RULE EXPLICITLY, and that is worth being
 * honest about. It is applied here because the codebase's own closest analogue
 * does — `PipelineBlueprint` refuses `$stages === []` outright — and because a
 * published version containing nothing is an operator mistake rather than a
 * meaningful Blueprint: it would install nothing, surface nothing, and still
 * occupy the Blueprint's single `published` slot, hiding the previous version
 * that did work.
 *
 * Fail-closed and trivially reversible: if the product later wants publishable
 * empty versions, removing this one check is the entire change.
 */
class EmptyDraftVersionException extends BlueprintAuthoringException
{
    public function __construct(public readonly int $versionId)
    {
        parent::__construct(
            "Blueprint version [{$versionId}] has no components; an empty version cannot be published."
        );
    }
}
