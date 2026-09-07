<?php

namespace App\DTO\Analytics;

/**
 * Contract §3.2 — the mandatory honesty notice: how many of the owning
 * customer's legacy rows carry `business_id IS NULL` in a source B5
 * charts, and are therefore EXCLUDED from every figure. The counts are
 * scoped by the owner's user id purely to SIZE the exclusion; they never
 * select a tenant and never enter a KPI.
 */
final class CoverageNotice
{
    public function __construct(
        public readonly int $unattributedMessages,
        public readonly int $unattributedContacts,
    ) {
    }

    public function shouldRender(): bool
    {
        return $this->unattributedMessages > 0 || $this->unattributedContacts > 0;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return ['unattributed_messages' => $this->unattributedMessages, 'unattributed_contacts' => $this->unattributedContacts];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self((int) ($data['unattributed_messages'] ?? 0), (int) ($data['unattributed_contacts'] ?? 0));
    }
}
