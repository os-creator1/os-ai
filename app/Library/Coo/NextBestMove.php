<?php

namespace App\Library\Coo;

use App\Library\Dashboard\AttentionItem;
use App\Models\Opportunity;

/**
 * Unified Business Home §6.4 (C-2) — the ONE move the Home recommends.
 *
 * It is either a status exception the customer can act on (an attention item,
 * already resolved to a destination this actor may open) or the head of the
 * Opportunity work queue. There is no third kind and nothing is persisted:
 * a move is recomputed on every render, so a waiting customer or a broken
 * connection is on the very next page, and a fixed problem is gone from it.
 */
final class NextBestMove
{
    public const KIND_ATTENTION = 'attention';

    public const KIND_OPPORTUNITY = 'opportunity';

    private function __construct(
        public readonly string $kind,
        public readonly ?AttentionItem $attention,
        public readonly ?Opportunity $opportunity,
    ) {
    }

    public static function fromAttention(AttentionItem $item): self
    {
        return new self(self::KIND_ATTENTION, $item, null);
    }

    public static function fromOpportunity(Opportunity $opportunity): self
    {
        return new self(self::KIND_OPPORTUNITY, null, $opportunity);
    }

    public function isAttention(): bool
    {
        return $this->kind === self::KIND_ATTENTION;
    }

    public function isOpportunity(): bool
    {
        return $this->kind === self::KIND_OPPORTUNITY;
    }
}
