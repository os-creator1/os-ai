<?php

namespace App\DTO\Analytics;

/**
 * Contract §8 — AI Business Advisor RECOMMENDATIONS (never a sales
 * pipeline, deal value or close rate): O1 open + current now, O2
 * completed in range, O3 dismissed in range, O4 last successful advisor
 * run (ISO 8601 string or null).
 */
final class AdvisorKpis
{
    public function __construct(
        public readonly int $openCurrent,
        public readonly int $completedInRange,
        public readonly int $dismissedInRange,
        public readonly ?string $lastSuccessfulRunAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'open_current' => $this->openCurrent,
            'completed_in_range' => $this->completedInRange,
            'dismissed_in_range' => $this->dismissedInRange,
            'last_successful_run_at' => $this->lastSuccessfulRunAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['open_current'] ?? 0),
            (int) ($data['completed_in_range'] ?? 0),
            (int) ($data['dismissed_in_range'] ?? 0),
            $data['last_successful_run_at'] ?? null,
        );
    }
}
