<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoAuditSeverity;

/**
 * Contract 18 §8.7 — one finding the evaluator decided on, before it is
 * persisted.
 *
 * Carries no prose, exactly like the row it becomes: a registry rule key, the
 * snapshot page it belongs to (null = site-level) and validated scalar facts.
 * The severity is not a constructor argument on purpose — it is read from the
 * registry, so the code that FINDS a problem cannot also decide how loudly to
 * report it.
 */
final class SeoAuditFindingDraft
{
    /**
     * @param  array<string, scalar|null>  $facts  already validated by SeoAuditRuleRegistry
     */
    public function __construct(
        public readonly string $ruleKey,
        public readonly ?string $pageUid,
        public readonly array $facts,
    ) {
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function make(string $ruleKey, ?string $pageUid, array $facts = []): self
    {
        // Validate at construction: an invalid rule key or fact can never
        // reach a persisted row, even from a future caller.
        return new self($ruleKey, $pageUid, SeoAuditRuleRegistry::validateFacts($ruleKey, $facts));
    }

    public function severity(): SeoAuditSeverity
    {
        return SeoAuditRuleRegistry::severityFor($this->ruleKey);
    }

    public function describe(): string
    {
        return SeoAuditRuleRegistry::describe($this->ruleKey, $this->facts);
    }
}
