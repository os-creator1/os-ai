<?php

namespace App\Library\Ads\Decisions;

/**
 * What the "What should you do now?" block of one provider page shows: the
 * single most urgent decision first, the rest of the goals' verdicts behind it,
 * and the campaigns that cannot be judged yet because nobody told MotionGrove
 * what they are for.
 */
final class AdsDecisionPanel
{
    /**
     * @param  list<AdsDecision>  $decisions  most urgent first
     * @param  list<array{uid: string, name: string, spend_micros: int}>  $unassigned  live campaigns with no goal, biggest spend first
     */
    public function __construct(
        public readonly array $decisions,
        public readonly array $unassigned,
        public readonly bool $hasGoals,
        public readonly bool $hasCampaigns,
    ) {
    }

    public function primary(): ?AdsDecision
    {
        return $this->decisions[0] ?? null;
    }

    /** @return list<AdsDecision> */
    public function others(): array
    {
        return array_slice($this->decisions, 1);
    }

    public function isEmpty(): bool
    {
        return $this->decisions === [] && $this->unassigned === [];
    }
}
