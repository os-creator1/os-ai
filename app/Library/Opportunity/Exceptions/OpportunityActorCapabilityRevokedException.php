<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.4(2) gate 3 — the actor no longer holds the
 * capability that governs this action's domain.
 *
 * Re-derived from the DURABLE permission set on every evaluation, never from
 * the session copy: an execution runs in a queued job that has no session at
 * all, and under the `sync` queue it would otherwise inherit the confirming
 * request's already-loaded permissions and miss a revocation made moments
 * earlier.
 */
class OpportunityActorCapabilityRevokedException extends OpportunityAuthorityRevokedException
{
    public static function forActor(int $actorUserId, string $capability): self
    {
        return new self(
            "Actor [{$actorUserId}] no longer holds the [{$capability}] capability required for this action."
        );
    }
}
