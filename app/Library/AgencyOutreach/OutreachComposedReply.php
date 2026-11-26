<?php

namespace App\Library\AgencyOutreach;

/**
 * The text OutreachReplyComposer produced, or the reason there is none.
 * `source` is the ledger source: `deterministic` (script / approved answers) or `ai`
 * (a model SELECTED one of the approved answers; the text itself is still the owner's).
 *
 * `unanswered` is the prospect's wording no approved answer covered (for the Unanswered
 * questions queue). `stepDeferred` means the decided step was NOT sent and stays pending:
 * the reply answers only, the stage does not move and no step is recorded as delivered.
 */
final class OutreachComposedReply
{
    /** @param list<string> $unanswered */
    public function __construct(
        public readonly ?string $text,
        public readonly string $source,
        public readonly string $intent,
        public readonly string $reason = '',
        public readonly array $unanswered = [],
        public readonly bool $stepDeferred = false,
    ) {
    }

    /** @param list<string> $unanswered */
    public static function nothing(string $intent, string $reason, array $unanswered = [], bool $stepDeferred = false): self
    {
        return new self(null, 'deterministic', $intent, $reason, $unanswered, $stepDeferred);
    }

    public function hasText(): bool
    {
        return $this->text !== null && trim($this->text) !== '';
    }
}
