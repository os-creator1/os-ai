<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoIndexabilityState;
use App\Models\SeoAuditRun;

/**
 * Contract 18 §8.7 — everything the Website SEO audit screen shows.
 *
 * The INDEXABILITY STATE is carried here beside the findings, and is
 * deliberately not one of them: the platform-path `noindex` is a property of
 * the product today (§8.7 G-2/G-3), so it is reported as status, never as
 * something the customer did wrong or could fix.
 */
final class SeoAuditPage
{
    /**
     * @param  array<int, SeoAuditFindingView>  $findings
     * @param  array<int, SeoAuditRun>  $history  newest first, at most the retained window
     */
    public function __construct(
        public readonly SeoIndexabilityState $indexability,
        public readonly ?SeoAuditRun $latestRun,
        public readonly array $findings,
        public readonly array $history,
    ) {
    }

    public function hasRun(): bool
    {
        return $this->latestRun !== null;
    }
}
