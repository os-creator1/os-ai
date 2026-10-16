<?php

namespace App\Library\ExternalSite\Audit;

/**
 * What the evaluator returns: the findings, the per-page analysis the Pages screen
 * needs, the distinct coverage counts and the priority summary.
 */
final class ExternalAuditResult
{
    /**
     * @param  list<ExternalFindingDraft>  $findings
     * @param  array<string, array{importance: string, inlinks: int, indexable: ?bool, canonical_status: string}>  $analysis  by UrlResolver::pageKey of each audited page
     * @param  array<string, mixed>  $coverage
     * @param  array<string, mixed>  $summary
     * @param  list<array{source: string, target: string, anchor: string, nofollow: bool}>  $edges  internal links between audited pages and targets (source = page URL)
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $analysis,
        public readonly array $coverage,
        public readonly array $summary,
        public readonly array $edges,
    ) {
    }
}
