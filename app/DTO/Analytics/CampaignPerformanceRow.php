<?php

namespace App\DTO\Analytics;

/**
 * Contract §6 C3 — one row of the campaign performance table. `attempted`,
 * `accepted` and `confirmedFailed` come from `reports` (the exact M4/M5
 * predicates restricted to this campaign AND this Business);
 * `contactsTargeted` is COUNT(DISTINCT tracking_logs.contact_id) under the
 * same Business scope. Never campaigns.cache, readCache(),
 * deliveredCount(), failedCount(), notDeliveredCount() or contactCount().
 */
final class CampaignPerformanceRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly string $name,
        public readonly ?string $status,
        public readonly string $createdAtLocal,
        public readonly int $attempted,
        public readonly int $accepted,
        public readonly int $confirmedFailed,
        public readonly int $contactsTargeted,
    ) {
    }

    public function acceptedRate(): ?float
    {
        return $this->attempted > 0 ? round($this->accepted / $this->attempted * 100, 1) : null;
    }

    public function confirmedFailedRate(): ?float
    {
        return $this->attempted > 0 ? round($this->confirmedFailed / $this->attempted * 100, 1) : null;
    }
}
