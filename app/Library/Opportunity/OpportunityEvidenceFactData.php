<?php

declare(strict_types=1);

namespace App\Library\Opportunity;

use App\Library\Opportunity\Exceptions\OpportunityEvidenceValidationException;
use Carbon\CarbonImmutable;

/**
 * Immutable, worker-supplied evidence-item input (RFC-002 §27, §38).
 * Structurally carries no `summary` property — the persisted summary is
 * always rendered by OpportunityEvidenceValidator from the type registry's
 * own evidence_summary_templates, never worker input.
 */
final class OpportunityEvidenceFactData
{
    /**
     * A raw evidence item supplying any of these keys is rejected outright
     * at hydration — never silently discarded and never applied. `summary`
     * is the registry-owned text itself (RFC-002 §13.2, §27); the rest are
     * attempts to supply executable/dynamically-resolved behavior, which an
     * evidence payload may never carry.
     */
    private const FORBIDDEN_KEYS = ['summary', 'class', 'handler', 'validator', 'callback', 'command'];

    public function __construct(
        public readonly string $sourceType,
        public readonly string $sourceIdentifier,
        public readonly string $factKey,
        public readonly mixed $observedValue,
        public readonly CarbonImmutable $retrievedAt,
        public readonly ?CarbonImmutable $observedAt,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $sourceUrl,
        public readonly ?string $contentHash,
    ) {
    }

    /**
     * Discards every key not explicitly read below, except the forbidden
     * keys above, which fail loudly instead. A missing required key or a
     * malformed primitive is never coerced — it is passed straight through
     * to this class's typed constructor and fails predictably (a native
     * TypeError, since this file declares strict_types).
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        foreach (self::FORBIDDEN_KEYS as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $raw)) {
                throw new OpportunityEvidenceValidationException(
                    "Evidence payload may not supply a [{$forbiddenKey}] key."
                );
            }
        }

        return new self(
            sourceType: $raw['source_type'],
            sourceIdentifier: $raw['source_identifier'],
            factKey: $raw['fact_key'],
            observedValue: $raw['observed_value'] ?? null,
            retrievedAt: CarbonImmutable::parse($raw['retrieved_at']),
            observedAt: isset($raw['observed_at']) ? CarbonImmutable::parse($raw['observed_at']) : null,
            expiresAt: isset($raw['expires_at']) ? CarbonImmutable::parse($raw['expires_at']) : null,
            sourceUrl: $raw['source_url'] ?? null,
            contentHash: $raw['content_hash'] ?? null,
        );
    }
}
