<?php

declare(strict_types=1);

namespace App\Library\Growth;

/**
 * One deterministic detector (Growth Center §3-4). Pure: it receives a
 * GrowthFactSnapshot and returns a verdict. It performs no query, no write,
 * no provider call and no AI call — which is what makes rule count and data
 * volume independent of one another.
 */
interface GrowthRule
{
    public function definition(): GrowthRuleDefinition;

    /** Only invoked when the rule's fact domain is Available. */
    public function evaluate(GrowthFactSnapshot $facts): GrowthRuleOutcome;

    /**
     * The owner-facing one-sentence statement of what was found, e.g.
     * "3 new leads have had no reply for 24+ hours." Built ONLY from the
     * persisted closed evidence — a fixed template, never generated text.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function headline(array $evidence): string;

    /**
     * The "What's working" sentence for a clean, adequately-sampled result,
     * or null when the rule has nothing worth celebrating.
     *
     * @param  array<string, mixed>  $positive
     */
    public function positiveStatement(array $positive): ?string;
}
