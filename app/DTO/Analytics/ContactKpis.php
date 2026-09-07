<?php

namespace App\DTO\Analytics;

/**
 * Contract §7 — K1 (total now), K2 (new in range), K4 (current
 * subscribed/unsubscribed split), K5 (contact group count). K3 is the
 * DailySeries `contact_growth`. No unsubscribe trend is ever derived: no
 * status-history ledger exists.
 */
final class ContactKpis
{
    public function __construct(
        public readonly int $totalNow,
        public readonly int $newInRange,
        public readonly int $subscribedNow,
        public readonly int $unsubscribedNow,
        public readonly int $groupCount,
    ) {
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'total_now' => $this->totalNow,
            'new_in_range' => $this->newInRange,
            'subscribed_now' => $this->subscribedNow,
            'unsubscribed_now' => $this->unsubscribedNow,
            'group_count' => $this->groupCount,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['total_now'] ?? 0),
            (int) ($data['new_in_range'] ?? 0),
            (int) ($data['subscribed_now'] ?? 0),
            (int) ($data['unsubscribed_now'] ?? 0),
            (int) ($data['group_count'] ?? 0),
        );
    }
}
