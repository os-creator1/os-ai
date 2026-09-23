<?php

namespace App\Library\Seo;

/**
 * Contract 18 §8.7, Sub-slice G — the DETERMINISTIC evaluation half of the
 * technical audit: a parsed published snapshot in, a list of finding drafts
 * out.
 *
 * PURE BY CONSTRUCTION, and that is the point. This class holds every rule
 * decision and has no database, no clock, no randomness, no network and no
 * AI — so the same revision always yields the same findings in the same
 * order, and re-auditing can be proven idempotent rather than hoped to be.
 * Persistence and pruning live in SeoAuditRunner; the split keeps the only
 * class permitted to write as small as possible.
 *
 * IT NEVER SEES A URL. Its whole input is the immutable
 * `website_revisions.snapshot`, already parsed by SeoPublishedContentReader.
 * Nothing here fetches the public site, and there is no crawler anywhere in
 * this slice (§8.7: "never crawls, never fetches a URL").
 *
 * WHAT IT DELIBERATELY DOES NOT CHECK (§8.7, G-1/G-2/G-3):
 *   - canonical tags and JSON-LD / structured data — the platform provides
 *     no control for either, so their absence is a platform limitation, never
 *     a customer mistake;
 *   - the platform-path `noindex` every hosted site carries — reported as an
 *     indexability STATUS elsewhere, never as a finding;
 *   - sitemap scope — same reason;
 *   - anything Location-specific — deferred (G-1) until Website provides a
 *     canonical page<->Location association. Nothing here reads a slug, URL
 *     or page text to guess which Location a page belongs to.
 *
 * ORDER IS STABLE: pages in snapshot order, and within a page the registry's
 * own rule order. Two runs over one revision therefore produce byte-identical
 * finding sets.
 */
final class SeoAuditEvaluator
{
    public function __construct(private readonly SeoConfig $config)
    {
    }

    /**
     * @return array<int, SeoAuditFindingDraft>
     */
    public function evaluate(SeoPublishedContent $content): array
    {
        $maxTitle = $this->config->auditSeoTitleMaxRecommended();
        $minDescription = $this->config->auditMetaDescriptionMinRecommended();

        $titleCounts = $this->duplicateCounts($content, fn (SeoPublishedPage $p): ?string => $p->hasSeoTitle() ? $p->seoTitle : null);
        $descriptionCounts = $this->duplicateCounts($content, fn (SeoPublishedPage $p): ?string => $p->hasMetaDescription() ? $p->metaDescription : null);

        $drafts = [];

        foreach ($content->pages as $page) {
            $uid = trim($page->uid);

            if ($uid === '') {
                // A page the snapshot never identified cannot be deep-linked
                // to its editor, so reporting it would be advice the customer
                // cannot act on. Skipped rather than reported under a fake id.
                continue;
            }

            foreach ($this->pageDrafts($page, $uid, $maxTitle, $minDescription, $titleCounts, $descriptionCounts) as $draft) {
                $drafts[] = $draft;
            }
        }

        $missingAlt = count($content->assetsMissingAltText());

        if ($missingAlt > 0) {
            // Site-level: the snapshot's asset list is not page-scoped, so
            // attributing these to a page would be a guess. One honest
            // finding with a count instead of N unactionable ones.
            $drafts[] = SeoAuditFindingDraft::make(
                SeoAuditRuleRegistry::ASSET_MISSING_ALT,
                null,
                ['asset_count' => $missingAlt],
            );
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
        SeoPublishedPage $page,
        string $uid,
        int $maxTitle,
        int $minDescription,
        array $titleCounts,
        array $descriptionCounts,
    ): array {
        $drafts = [];

        if (! $page->hasSeoTitle()) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::SEO_TITLE_BLANK, $uid);
        } else {
            $length = mb_strlen(trim((string) $page->seoTitle));

            if ($length > $maxTitle) {
                $drafts[] = SeoAuditFindingDraft::make(
                    SeoAuditRuleRegistry::SEO_TITLE_OVER_RECOMMENDED,
                    $uid,
                    ['length' => $length, 'recommended_max' => $maxTitle],
                );
            }

            $shared = ($titleCounts[$this->normalize((string) $page->seoTitle)] ?? 1) - 1;

            if ($shared > 0) {
                $drafts[] = SeoAuditFindingDraft::make(
                    SeoAuditRuleRegistry::DUPLICATE_SEO_TITLE,
                    $uid,
                    ['shared_by' => $shared],
                );
            }
        }

        if (! $page->hasMetaDescription()) {
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::META_DESCRIPTION_BLANK, $uid);
        } else {
            $length = mb_strlen(trim((string) $page->metaDescription));

            if ($length < $minDescription) {
                $drafts[] = SeoAuditFindingDraft::make(
                    SeoAuditRuleRegistry::META_DESCRIPTION_SHORT,
                    $uid,
                    ['length' => $length, 'recommended_min' => $minDescription],
                );
            }

            $shared = ($descriptionCounts[$this->normalize((string) $page->metaDescription)] ?? 1) - 1;

            if ($shared > 0) {
                $drafts[] = SeoAuditFindingDraft::make(
                    SeoAuditRuleRegistry::DUPLICATE_META_DESCRIPTION,
                    $uid,
                    ['shared_by' => $shared],
                );
            }
        }

        if ($page->noindex) {
            // The CUSTOMER'S own per-page noindex, which they can clear — not
            // the platform-path directive of §8.7's G-2/G-3, which is never a
            // finding and is reported as an indexability status instead.
            $drafts[] = SeoAuditFindingDraft::make(SeoAuditRuleRegistry::PAGE_MARKED_NOINDEX, $uid);
        }

        return $drafts;
    }

    /**
     * How many identified pages share each normalised value.
     *
     * @param  callable(SeoPublishedPage): ?string  $value
     * @return array<string, int>
     */
    private function duplicateCounts(SeoPublishedContent $content, callable $value): array
    {
        $counts = [];

        foreach ($content->pages as $page) {
            if (trim($page->uid) === '') {
                continue;
            }

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
