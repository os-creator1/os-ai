<?php

namespace App\Library\Seo;

use App\Library\Seo\Audit\HostedRevisionAuditSource;
use App\Library\Seo\Audit\SeoAuditPageFacts;
use App\Library\Seo\Audit\SeoAuditSiteFacts;

/**
 * Contract 18 §8.7, Sub-slice G — the DETERMINISTIC evaluation half of the
 * technical audit: normalised page facts in, a list of finding drafts out.
 *
 * ONE ENGINE FOR EVERY SITE. The evaluator does not know where a site came
 * from. A hosted Website's immutable published snapshot
 * (HostedRevisionAuditSource) and an external website's crawl
 * (ExternalSiteAuditSource) are both reduced to the same source-neutral
 * SeoAuditSiteFacts, and the rules below run over that — the same registry, the
 * same finding vocabulary, the same ordering. `evaluate()` keeps its original
 * hosted signature and simply normalises first.
 *
 * PURE BY CONSTRUCTION, and that is the point. This class holds every rule
 * decision and has no database, no clock, no randomness, no network and no
 * AI — so the same facts always yield the same findings in the same order, and
 * re-auditing can be proven idempotent rather than hoped to be. Persistence and
 * pruning live in SeoAuditRunner (hosted) and ExternalSiteAuditRunner
 * (external); fetching lives in the external crawler. This class never sees a
 * URL, a document or a snapshot.
 *
 * WHAT IT DELIBERATELY DOES NOT CHECK ON A HOSTED SITE (§8.7, G-1/G-2/G-3):
 *   - canonical tags, structured data, Open Graph tags and the sitemap — on a
 *     custom domain the platform emits all of these itself, so a hosted
 *     customer has nothing to fix; they are not "missing", they are the
 *     platform's job. The hosted source never supplies those facts, so the
 *     rules that need them cannot fire (see the external-only rules below);
 *   - the platform-path `noindex` and whether search engines can find the site
 *     at all — reported as an indexability STATUS (SeoIndexability), never a
 *     finding;
 *   - anything Location-specific — deferred (G-1).
 *
 * The EXTERNAL-ONLY rules (page_not_reachable, broken_internal_link,
 * h1_missing, h1_multiple, canonical_missing, open_graph_missing,
 * structured_data_missing) fire only when the source supplied the fact, which
 * only a crawl does: an external site's owner controls those things.
 *
 * ORDER IS STABLE: pages in source order, and within a page the registry's own
 * rule order. Two runs over the same facts produce byte-identical finding sets.
 */
final class SeoAuditEvaluator
{
    public function __construct(private readonly SeoConfig $config)
    {
    }

    /**
     * The original hosted entry point: a parsed published snapshot in.
     *
     * @return array<int, SeoAuditFindingDraft>
     */
    public function evaluate(SeoPublishedContent $content): array
    {
        return $this->evaluateFacts(HostedRevisionAuditSource::factsFor($content));
    }

    /**
     * @return array<int, SeoAuditFindingDraft>
     */
    public function evaluateFacts(SeoAuditSiteFacts $site): array
    {
        $maxTitle = $this->config->auditSeoTitleMaxRecommended();
        $minDescription = $this->config->auditMetaDescriptionMinRecommended();

        // Duplicates are judged among pages that actually loaded and carry the value.
        $reachable = array_values(array_filter($site->pages, fn (SeoAuditPageFacts $p): bool => $p->isReachable()));
        $titleCounts = $this->duplicateCounts($reachable, fn (SeoAuditPageFacts $p): ?string => $p->hasTitle() ? $p->explicitTitle : null);
        $descriptionCounts = $this->duplicateCounts($reachable, fn (SeoAuditPageFacts $p): ?string => $p->hasMetaDescription() ? $p->metaDescription : null);

        $drafts = [];

        foreach ($site->pages as $page) {
            foreach ($this->pageDrafts($page, $maxTitle, $minDescription, $titleCounts, $descriptionCounts) as $draft) {
                $drafts[] = $draft;
            }
        }

        if ($site->imagesMissingAlt > 0) {
            // Site-level: attributing these to a page would be a guess. One
            // honest finding with a count instead of N unactionable ones.
            $drafts[] = SeoAuditFindingDraft::make(
                SeoAuditRuleRegistry::ASSET_MISSING_ALT,
                null,
                ['asset_count' => $site->imagesMissingAlt],
            );
        }

        // External only: no reachable page carries any structured data.
        $known = array_filter($reachable, fn (SeoAuditPageFacts $p): bool => $p->hasStructuredData !== null);

        if ($known !== [] && count(array_filter($known, fn (SeoAuditPageFacts $p): bool => $p->hasStructuredData === true)) === 0) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::STRUCTURED_DATA_MISSING, null);
        }

        return $drafts;
    }

    /**
     * The per-page rules, in the registry's fixed order.
     *
     * @param  array<string, int>  $titleCounts
     * @param  array<string, int>  $descriptionCounts
     * @return array<int, SeoAuditFindingDraft>
     */
    private function pageDrafts(
        SeoAuditPageFacts $page,
        int $maxTitle,
        int $minDescription,
        array $titleCounts,
        array $descriptionCounts,
    ): array {
        $key = $page->key;
        $drafts = [];

        if (! $page->isReachable()) {
            // A page that did not load has no title or description to judge: the one
            // honest finding is that it did not load.
            return [SeoAuditFindingDraft::make(SeoAuditRuleRegistry::PAGE_NOT_REACHABLE, $key, ['http_status' => $page->httpStatus])];
        }

        if (! $page->hasTitle()) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::SEO_TITLE_BLANK, $key);
        }

        // The length that matters is the REAL <title> searchers get.
        $length = mb_strlen($page->effectiveTitle);

        if ($length > $maxTitle) {
            $drafts[] = SeoAuditFindingDraft::make(
                SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED,
                $key,
                ['length' => $length, 'recommended_max' => $maxTitle],
            );
        }

        if ($page->hasTitle()) {
            $shared = ($titleCounts[$this->normalize((string) $page->explicitTitle)] ?? 1) - 1;

            if ($shared > 0) {
                $drafts[] = SeoAuditFindingDraft::make(
                    SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE,
                    $key,
                    ['shared_by' => $shared],
                );
            }
        }

        if (! $page->hasMetaDescription()) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::META_DESCRIPTION_BLANK, $key);
        } else {
            $length = mb_strlen(trim((string) $page->metaDescription));

            if ($length < $minDescription) {
                $drafts[] = SeoAuditFindingDraft::make(
                    SeoAuditRuleRegistry::META_DESCRIPTION_SHORT,
                    $key,
                    ['length' => $length, 'recommended_min' => $minDescription],
                );
            }

            $shared = ($descriptionCounts[$this->normalize((string) $page->metaDescription)] ?? 1) - 1;

            if ($shared > 0) {
                $drafts[] = SeoAuditFindingDraft::make(
                    SeoAuditRuleRegistry::DUPLICATE_META_DESCRIPTION,
                    $key,
                    ['shared_by' => $shared],
                );
            }
        }

        if ($page->noindex) {
            // The CUSTOMER'S own per-page noindex, which they can clear — not
            // the platform-path directive of §8.7's G-2/G-3.
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::PAGE_MARKED_NOINDEX, $key);
        }

        // ---- External-only rules: each needs a fact only a crawl supplies. ----

        if ($page->h1Count === 0) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::H1_MISSING, $key);
        } elseif ($page->h1Count !== null && $page->h1Count > 1) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::H1_MULTIPLE, $key, ['h1_count' => $page->h1Count]);
        }

        if ($page->hasCanonical === false) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::CANONICAL_MISSING, $key);
        }

        if ($page->hasOpenGraph === false) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::OPEN_GRAPH_MISSING, $key);
        }

        if ($page->brokenInternalLinks !== null && $page->brokenInternalLinks > 0) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::BROKEN_INTERNAL_LINK, $key, ['link_count' => $page->brokenInternalLinks]);
        }

        return $drafts;
    }

    /**
     * How many pages share each normalised value.
     *
     * @param  list<SeoAuditPageFacts>  $pages
     * @param  callable(SeoAuditPageFacts): ?string  $value
     * @return array<string, int>
     */
    private function duplicateCounts(array $pages, callable $value): array
    {
        $counts = [];

        foreach ($pages as $page) {
            $raw = $value($page);

            if ($raw === null) {
                continue;
            }

            $key = $this->normalize($raw);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Comparison-only normalisation. The normalised string is used as an
     * array key to COUNT matches and is never stored or shown, so no page
     * text can escape through a finding.
     */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
