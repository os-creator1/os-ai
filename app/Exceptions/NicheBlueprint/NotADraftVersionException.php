<?php

namespace App\Exceptions\NicheBlueprint;

/**
 * Contract 20 §5.2 — a published or superseded version, and every component
 * belonging to it, is immutable. Only a `draft` may be edited or published.
 *
 * This is the refusal that makes "an update to the platform Blueprint MUST NOT
 * silently update or reactivate components inside an already-installed
 * Business" enforceable at its source: an issued version can never be
 * rewritten underneath the installations that cite it.
 */
class NotADraftVersionException extends BlueprintAuthoringException
{
    public function __construct(public readonly int $versionId, public readonly string $state)
    {
        parent::__construct(
            "Blueprint version [{$versionId}] is [{$state}], not a draft; issued versions are immutable."
        );
    }
}
