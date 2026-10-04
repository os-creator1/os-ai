<?php

namespace App\Library\AgencyOutreach;

/**
 * The Outreach readiness checklist (contract section 12): a read-only value
 * object. Each item is ok|blocked with an exact, plain-language reason and,
 * where the fix lives on an existing page, a link to it.
 *
 * `info` carries display-only facts (number, balance, auto-recharge, estimated
 * cost) the Overview panel shows; none of them is ever used to decide anything.
 */
final class OutreachReadiness
{
    /**
     * @param list<array{key: string, label: string, ok: bool, reason: ?string, url?: ?string}> $items
     * @param array<string, mixed> $info
     */
    public function __construct(private readonly array $items, private readonly array $info = [])
    {
    }

    public function isReady(): bool
    {
        foreach ($this->items as $item) {
            if (! $item['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{key: string, label: string, ok: bool, reason: ?string, url?: ?string}> */
    public function items(): array
    {
        return $this->items;
    }

    public function firstBlockingReason(): ?string
    {
        foreach ($this->items as $item) {
            if (! $item['ok']) {
                return $item['reason'] ?? $item['label'];
            }
        }

        return null;
    }

    public function item(string $key): ?array
    {
        foreach ($this->items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        return $this->info;
    }
}
