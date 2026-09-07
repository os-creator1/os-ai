<?php

namespace App\DTO\Analytics;

/**
 * Contract §6 — C1 (created in range) and C2 (CURRENT status snapshot,
 * explicitly not a period state). C3 is the paginated table and lives in
 * CampaignPerformanceRow.
 */
final class CampaignKpis
{
    /**
     * @param  array<string, int>  $statusSnapshot  status => count of ALL the Business's campaigns right now
     */
    public function __construct(
        public readonly int $createdInRange,
        public readonly array $statusSnapshot,
    ) {
    }

    public function totalNow(): int
    {
        return (int) array_sum($this->statusSnapshot);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['created_in_range' => $this->createdInRange, 'status_snapshot' => $this->statusSnapshot];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['created_in_range'] ?? 0), array_map('intval', $data['status_snapshot'] ?? []));
    }
}
