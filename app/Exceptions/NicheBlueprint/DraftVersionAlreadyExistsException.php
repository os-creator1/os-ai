<?php

namespace App\Exceptions\NicheBlueprint;

/**
 * Contract 20 §5.2 — at most one draft per Blueprint.
 *
 * Raised by the domain, under the Blueprint row lock, BEFORE the `draft_guard`
 * unique index would reject the insert. The index remains the final backstop;
 * this exception exists so a losing concurrent caller receives a domain
 * refusal it can act on rather than a raw SQL integrity error.
 */
class DraftVersionAlreadyExistsException extends BlueprintAuthoringException
{
    public function __construct(
        public readonly int $blueprintId,
        public readonly int $existingDraftVersionId,
    ) {
        parent::__construct(
            "Blueprint [{$blueprintId}] already has draft version [{$existingDraftVersionId}]; finish or discard it first."
        );
    }
}
