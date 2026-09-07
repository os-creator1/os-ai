<?php

namespace App\DTO\Analytics;

/**
 * Contract §5 — M1..M6 for one Business and range. Every figure is an
 * integer count from `reports` scoped by `business_id`; the identity
 * accepted + confirmedFailed + unresolved === outbound always holds by
 * construction (M6 is derived, never queried).
 *
 * "Accepted" means accepted by the provider at send time (M4), never
 * handset delivery.
 */
final class MessageKpis
{
    public function __construct(
        public readonly int $outbound,
        public readonly int $api,
        public readonly int $inbound,
        public readonly int $accepted,
        public readonly int $confirmedFailed,
    ) {
    }

    /** M6 — unresolved / in flight. Always visible. */
    public function unresolved(): int
    {
        return $this->outbound - $this->accepted - $this->confirmedFailed;
    }

    /** M4 rate as a percentage with one decimal, null when M1 is zero. */
    public function acceptedRate(): ?float
    {
        return $this->outbound > 0 ? round($this->accepted / $this->outbound * 100, 1) : null;
    }

    public function confirmedFailedRate(): ?float
    {
        return $this->outbound > 0 ? round($this->confirmedFailed / $this->outbound * 100, 1) : null;
    }

    public function unresolvedRate(): ?float
    {
        return $this->outbound > 0 ? round($this->unresolved() / $this->outbound * 100, 1) : null;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'outbound' => $this->outbound,
            'api' => $this->api,
            'inbound' => $this->inbound,
            'accepted' => $this->accepted,
            'confirmed_failed' => $this->confirmedFailed,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['outbound'] ?? 0),
            (int) ($data['api'] ?? 0),
            (int) ($data['inbound'] ?? 0),
            (int) ($data['accepted'] ?? 0),
            (int) ($data['confirmed_failed'] ?? 0),
        );
    }
}
