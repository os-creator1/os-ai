<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthRuleOutcomeStatus;

/**
 * What one rule concluded: its status plus, for a Finding, the findings, and
 * for a Passing rule an optional factual "what's working" statement.
 */
final class GrowthRuleOutcome
{
    /**
     * @param  array<int, GrowthFinding>  $findings
     * @param  array<string, mixed>|null  $positive  {facts for the positive-insight template} or null
     */
    private function __construct(
        public readonly GrowthRuleOutcomeStatus $status,
        public readonly array $findings,
        public readonly ?array $positive,
    ) {
    }

    /** @param  array<int, GrowthFinding>  $findings */
    public static function findings(array $findings): self
    {
        return new self(GrowthRuleOutcomeStatus::Finding, array_values($findings), null);
    }

    /** @param  array<string, mixed>|null  $positive */
    public static function passing(?array $positive = null): self
    {
        return new self(GrowthRuleOutcomeStatus::Passing, [], $positive);
    }

    public static function notApplicable(): self
    {
        return new self(GrowthRuleOutcomeStatus::NotApplicable, [], null);
    }

    public static function insufficient(): self
    {
        return new self(GrowthRuleOutcomeStatus::Insufficient, [], null);
    }
}
