<?php

declare(strict_types=1);

namespace App\Library\Growth;

/**
 * One detected problem, produced by a rule from facts. Not yet an
 * Opportunity: GrowthWorkerProducer turns it into the engine's
 * OpportunityCandidateData, and the engine validates and persists it.
 *
 * impact / urgency / effort are the engine's 0-5 ranks. `confidence` is the
 * engine's 0..1 decimal; rules use only 1.0 (a direct canonical fact), 0.8 (a
 * strong deterministic inference) or 0.6 (limited data) so the owner-facing
 * label is honest rather than pseudo-precise.
 */
final class GrowthFinding
{
    /**
     * @param  array<string, mixed>  $evidence  the closed per-rule observed_value
     * @param  int|null  $locationId  null = Business-wide
     */
    public function __construct(
        public readonly ?int $locationId,
        public readonly int $impact,
        public readonly int $urgency,
        public readonly int $effort,
        public readonly float $confidence,
        public readonly array $evidence,
    ) {
    }
}
