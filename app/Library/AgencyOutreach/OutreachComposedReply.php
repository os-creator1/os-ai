<?php

namespace App\Library\AgencyOutreach;

/**
 * The text OutreachReplyComposer produced, or the reason there is none.
 * `source` is the ledger source: `deterministic` (script / FAQ) or `ai` (a model
 * wrote the one answer sentence in front of the exact stage message).
 */
final class OutreachComposedReply
{
    public function __construct(
        public readonly ?string $text,
        public readonly string $source,
        public readonly string $intent,
        public readonly string $reason = '',
    ) {
    }

    public static function nothing(string $intent, string $reason): self
    {
        return new self(null, 'deterministic', $intent, $reason);
    }

    public function hasText(): bool
    {
        return $this->text !== null && trim($this->text) !== '';
    }
}
