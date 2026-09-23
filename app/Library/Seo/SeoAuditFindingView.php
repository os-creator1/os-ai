<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoAuditSeverity;

/**
 * Contract 18 §8.7 — one finding, rendered.
 *
 * `title` and `description` come from SeoAuditRuleRegistry (template +
 * validated facts); `pageName` is the page's own name from the AUDITED
 * snapshot, used only as a label so the customer knows which page to open.
 * Nothing here is page body text, provider text or model output.
 */
final class SeoAuditFindingView
{
    public function __construct(
        public readonly string $ruleKey,
        public readonly SeoAuditSeverity $severity,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $pageUid,
        public readonly ?string $pageName,
    ) {
    }

    public function isSiteLevel(): bool
    {
        return $this->pageUid === null;
    }
}
