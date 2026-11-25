<?php

namespace App\Library\Website\GuidedGeneration;

use App\Library\Website\Seo\WebsitePageIntent;
use App\Library\Website\Seo\WebsitePageIntentClassifier;
use App\Library\Website\Seo\WebsiteRedirectManager;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;

/**
 * Decides, deterministically and without writing anything, what a guided regeneration does to each
 * EXISTING draft page, so an established website never silently loses pages or URLs just because the
 * latest plan is smaller than what the site already has.
 *
 *  - rewrite:  the existing page is the same logical page as a planned page (Home; the same address;
 *              or the same one-per-site role such as FAQ/Gallery/About/Contact/Packages/Services at a
 *              different address). It is replaced by the freshly written page; a changed address gets a
 *              redirect from the old address.
 *  - preserve: every other page of an established site that no earlier generation stamped with a role (legacy, migrated, hand-made), and legal pages of ANY site. Never touched.
 *  - remove:   only on a site never published/released and never generated before, where the pages are
 *              just the template's starter content that a first generation has always replaced.
 *  - create:   planned pages with no existing counterpart.
 *
 * Anything that cannot be done without losing a page (page ceiling, redirect ceiling) is a blocker: the
 * caller must stop before touching the draft.
 */
final class DraftPageReconciler
{
    /** One-per-site roles that identify "the same logical page" even when the address differs. */
    private const SINGLETON_ROLES = [
        WebsitePageIntent::SERVICES_HUB, WebsitePageIntent::PACKAGES, WebsitePageIntent::ABOUT,
        WebsitePageIntent::FAQ, WebsitePageIntent::GALLERY, WebsitePageIntent::BACKDROPS, WebsitePageIntent::CONTACT,
    ];

    /** Roles safe to infer from an unstamped (legacy/owner) page's address; e.g. "check-availability" is never assumed to be Contact. */
    private const DERIVABLE_ROLES = [WebsitePageIntent::ABOUT, WebsitePageIntent::FAQ, WebsitePageIntent::GALLERY, WebsitePageIntent::BACKDROPS];

    private const PAGE_CEILING = \App\Library\Website\WebsitePageStrategy::MAX_PAGES_PER_GENERATION;

    /**
     * @param  array<int, array<string, mixed>>  $plan  WebsitePageStrategy plan entries (page_key, slug, is_home, page_type, title)
     * @return array{established: bool, rewrite: array<int, array>, preserve: array<int, array>, remove: array<int, array>, create: array<int, array>, redirects: array<string, string>, blockers: array<int, string>}
     */
    public function reconcile(Website $website, Business $business, array $plan): array
    {
        $existing = $website->pages()->get(['id', 'title', 'slug', 'is_home', 'page_role', 'page_intent', 'sections']);
        $established = $this->isEstablished($website);
        $classifier = WebsitePageIntentClassifier::forBusiness($business);

        $rows = $existing->map(fn ($page) => [
            'source' => $classifier->classify(['slug' => (string) $page->slug, 'is_home' => (bool) $page->is_home, 'title' => (string) $page->title, 'role' => $page->page_role, 'intent' => $page->page_intent])['source'],
            'id' => $page->id,
            'title' => (string) $page->title,
            'slug' => $page->is_home ? '' : strtolower((string) $page->slug),
            'is_home' => (bool) $page->is_home,
            'role' => $classifier->classify(['slug' => (string) $page->slug, 'is_home' => (bool) $page->is_home, 'title' => (string) $page->title, 'role' => $page->page_role, 'intent' => $page->page_intent])['role'],
            'has_custom_section' => collect($page->sections)->contains(fn ($section) => ($section['type'] ?? null) === 'custom_section'),
            'legal' => self::isLegal((string) $page->slug, (string) $page->title, $page->page_role),
        ])->all();

        $matchedExisting = [];
        $matchedPlan = [];

        // Pass 1: the same address (Home matches Home).
        foreach ($plan as $planIndex => $planPage) {
            $planSlug = $planPage['is_home'] ? '' : strtolower((string) $planPage['slug']);
            foreach ($rows as $rowIndex => $row) {
                if (! isset($matchedExisting[$rowIndex]) && $row['is_home'] === (bool) $planPage['is_home'] && $row['slug'] === $planSlug) {
                    $matchedExisting[$rowIndex] = $planIndex;
                    $matchedPlan[$planIndex] = $rowIndex;
                    break;
                }
            }
        }

        // Pass 2: the same one-per-site role at a different address (a page stamped by an earlier generation, or an
        // unstamped one whose address unambiguously says FAQ/About/Gallery/Backdrops). Legal pages are never "rewritten".
        $redirects = [];
        foreach ($plan as $planIndex => $planPage) {
            if (isset($matchedPlan[$planIndex]) || $planPage['is_home']) {
                continue;
            }
            $planRole = WebsitePageIntent::roleForPlanType((string) $planPage['page_type']);
            // The owner's editorial "custom section" page is named after its title, so a renamed one has a new address:
            // the existing page that carries a custom_section section IS that page.
            if ($planPage['page_type'] === 'custom_section') {
                foreach ($rows as $rowIndex => $row) {
                    if (! isset($matchedExisting[$rowIndex]) && ! $row['is_home'] && ! $row['legal'] && $row['has_custom_section']) {
                        $matchedExisting[$rowIndex] = $planIndex;
                        $matchedPlan[$planIndex] = $rowIndex;
                        $redirects[$row['slug']] = strtolower((string) $planPage['slug']);
                        break;
                    }
                }

                continue;
            }
            if (! in_array($planRole, self::SINGLETON_ROLES, true)) {
                continue;
            }
            foreach ($rows as $rowIndex => $row) {
                if (! isset($matchedExisting[$rowIndex]) && ! $row['is_home'] && ! $row['legal'] && $row['role'] === $planRole && ($row['source'] === 'stored' || in_array($row['role'], self::DERIVABLE_ROLES, true))) {
                    $matchedExisting[$rowIndex] = $planIndex;
                    $matchedPlan[$planIndex] = $rowIndex;
                    $redirects[$row['slug']] = strtolower((string) $planPage['slug']);
                    break;
                }
            }
        }

        $report = ['established' => $established, 'rewrite' => [], 'preserve' => [], 'remove' => [], 'create' => [], 'redirects' => $redirects, 'blockers' => [], 'duplicate_intent' => []];

        foreach ($rows as $rowIndex => $row) {
            if (isset($matchedExisting[$rowIndex])) {
                $report['rewrite'][] = $row;
            } elseif ($row['legal'] || ($established && ! $this->isGeneratorOwned($row))) {
                $report['preserve'][] = $row;
            } else {
                $report['remove'][] = $row;
            }
        }

        foreach ($plan as $planIndex => $planPage) {
            if (! isset($matchedPlan[$planIndex])) {
                $report['create'][] = ['page_key' => $planPage['page_key'], 'title' => $planPage['title'], 'slug' => $planPage['slug']];
            }
        }

        // Report-only: a NEW page that targets the same thing as a page the owner already has (kept untouched). Nothing is merged,
        // redirected or deleted automatically; the owner decides which one stays.
        $report['duplicate_intent'] = $this->duplicateIntent($report['create'], $report['preserve']);

        $finalCount = count($report['preserve']) + count($plan);
        if ($finalCount > self::PAGE_CEILING) {
            $report['blockers'][] = 'This rebuild would leave ' . $finalCount . ' pages (' . count($report['preserve']) . ' existing pages kept plus ' . count($plan) . ' written), but a website holds at most ' . self::PAGE_CEILING . '. Delete or merge ' . ($finalCount - self::PAGE_CEILING) . ' of the kept pages (' . collect($report['preserve'])->pluck('title')->take(5)->implode(', ') . '…), then rebuild again. Nothing was changed.';
        }

        if (count((array) $website->custom_redirects) + count($redirects) > WebsiteRedirectManager::MAX) {
            $report['blockers'][] = 'Keeping every old address working needs more redirects than the ' . WebsiteRedirectManager::MAX . ' a website allows. Remove unused redirects, then rebuild again. Nothing was changed.';
        }

        return $report;
    }

    /** Words that name the kind of page, not what it is about. */
    private const GENERIC_WORDS = ['photo', 'photos', 'booth', 'booths', 'rental', 'rentals', 'hire', 'service', 'services', 'serving', 'page', 'the', 'and', 'for', 'in', 'a', 'of', 'our', 'your', 'us', 'event', 'events'];

    /**
     * @param  array<int, array{page_key: string, title: string, slug: ?string}>  $create
     * @param  array<int, array<string, mixed>>  $preserve
     * @return array<int, array{planned: string, existing: string, keywords: array<int, string>}>
     */
    private function duplicateIntent(array $create, array $preserve): array
    {
        $keywords = fn (string $text) => array_values(array_diff(preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [], self::GENERIC_WORDS));
        $found = [];

        foreach ($create as $planned) {
            $plannedWords = $keywords((string) ($planned['slug'] ?? ''));

            if ($plannedWords === []) {
                continue;
            }

            foreach ($preserve as $existing) {
                if ($existing['legal'] || $existing['is_home']) {
                    continue;
                }

                $shared = array_values(array_intersect($plannedWords, $keywords((string) $existing['slug'] . ' ' . (string) $existing['title'])));

                if (count($shared) === count($plannedWords)) {
                    $found[] = ['planned' => (string) $planned['slug'], 'existing' => (string) $existing['slug'], 'keywords' => $shared];
                }
            }
        }

        return $found;
    }

    /**
     * A page stamped with a real role by an earlier generation is generator-owned: when the plan no longer produces it
     * (e.g. the owner dropped that service area) it is removed and the existing place-redirect rules apply. Pages at the generator's own address
     * conventions are treated the same. Other unstamped pages (legacy, migrated, hand-made) and 'other'/'legal' stamped pages are never generator-owned.
     */
    private function isGeneratorOwned(array $row): bool
    {
        if ($row['source'] === 'stored') {
            return ! in_array($row['role'], [WebsitePageIntent::OTHER, WebsitePageIntent::LEGAL], true);
        }

        // The generator's own address conventions ("serving-<place>") are never owner-chosen addresses.
        return $row['source'] === 'convention' && $row['role'] === WebsitePageIntent::LOCATION;
    }

    /** A site the owner could already be relying on: published, released to search engines, or generated before. */
    public function isEstablished(Website $website): bool
    {
        return $website->published_revision_id !== null
            || $website->indexing_released_at !== null
            || WebsiteGuidedGenerationAttempt::where('website_id', $website->id)->where('status', WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED)->exists();
    }

    public static function isLegal(string $slug, string $title, ?string $role): bool
    {
        return $role === WebsitePageIntent::LEGAL
            || preg_match('/(privacy|terms|legal|cookie|disclaimer|refund)/i', $slug . ' ' . $title) === 1;
    }
}
