<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

/**
 * Immutable, worker-supplied candidate input (RFC-002 §38). Structurally
 * carries no title, summary, actionKey, fingerprint, fingerprintVersion,
 * priorityScore, status, freshness, occurrenceNumber, action-schema
 * metadata, or any handler/validator/callback/class-name property — those
 * are always OpportunityManager/registry output, never worker input.
 */
final class OpportunityCandidateData
{
    /**
     * @param  array<string, mixed>  $templateParameters
     * @param  array<int, string>  $relevantGoalKeys
     * @param  array<int, OpportunityEvidenceFactData>  $evidence
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $context,
        public readonly array $templateParameters,
        public readonly int $impact,
        public readonly int $urgency,
        public readonly int $effort,
        public readonly float $confidence,
        public readonly array $relevantGoalKeys,
        public readonly array $evidence,
        public readonly ?array $actionParameters,
    ) {
    }

    /**
     * Discards every key not explicitly read below (RFC-002 §34) — an
     * injected `priority_score`, `title`, `fingerprint`, etc. on the raw
     * payload never reaches this object at all. Never coerces a malformed
     * primitive into a valid one: each value is passed straight through to
     * this class's typed constructor parameters and fails predictably (a
     * native TypeError, since this file declares strict_types) rather than,
     * for example, silently casting a numeric string to an int.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            type: $raw['type'],
            context: $raw['context'] ?? null,
            templateParameters: $raw['templateParameters'] ?? [],
            impact: $raw['impact'],
            urgency: $raw['urgency'],
            effort: $raw['effort'],
            confidence: $raw['confidence'],
            relevantGoalKeys: $raw['relevantGoalKeys'] ?? [],
            evidence: array_map(
                static fn (array $item): OpportunityEvidenceFactData => OpportunityEvidenceFactData::fromArray($item),
                $raw['evidence'] ?? []
            ),
            actionParameters: $raw['actionParameters'] ?? null,
        );
    }
}
