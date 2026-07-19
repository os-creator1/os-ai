<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

/**
 * Computes the trusted SHA-256 hash of a constructed recommended_action
 * (RFC-002 §28), reusing CanonicalJson unmodified. List order within
 * $recommendedAction is preserved exactly as supplied — a trusted,
 * action-specific validation rule is responsible for normalizing an
 * unordered parameters list before it ever reaches this class; this class
 * makes no ordering decision of its own.
 */
final class OpportunityActionHash
{
    /**
     * @param  array<string, mixed>  $recommendedAction
     */
    public function compute(array $recommendedAction): string
    {
        return hash('sha256', CanonicalJson::encode($recommendedAction));
    }
}
