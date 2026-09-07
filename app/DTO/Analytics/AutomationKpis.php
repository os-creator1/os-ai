<?php

namespace App\DTO\Analytics;

/**
 * Contract §9 — read-only over B4's automation_executions. A1 executions
 * in range; A2 success rate = succeeded / (succeeded + failed) with
 * `pending` and `skipped` EXCLUDED from both sides and shown separately
 * (B4 defines `skipped` as a deliberate at-most-once outcome, not a
 * failure); A3 status distribution; A4 trigger-type distribution.
 */
final class AutomationKpis
{
    /**
     * @param  array<string, int>  $byStatus  status => count
     * @param  array<string, int>  $byTrigger  trigger_type => count
     */
    public function __construct(
        public readonly int $executionsInRange,
        public readonly array $byStatus,
        public readonly array $byTrigger,
    ) {
    }

    public function succeeded(): int
    {
        return $this->byStatus['succeeded'] ?? 0;
    }

    public function failed(): int
    {
        return $this->byStatus['failed'] ?? 0;
    }

    public function skipped(): int
    {
        return $this->byStatus['skipped'] ?? 0;
    }

    public function pending(): int
    {
        return $this->byStatus['pending'] ?? 0;
    }

    /** A2 — null when there is no succeeded/failed outcome to rate (zero denominator). */
    public function successRate(): ?float
    {
        $denominator = $this->succeeded() + $this->failed();

        return $denominator > 0 ? round($this->succeeded() / $denominator * 100, 1) : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['executions_in_range' => $this->executionsInRange, 'by_status' => $this->byStatus, 'by_trigger' => $this->byTrigger];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['executions_in_range'] ?? 0), array_map('intval', $data['by_status'] ?? []), array_map('intval', $data['by_trigger'] ?? []));
    }
}
