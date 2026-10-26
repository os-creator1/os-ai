<?php

namespace App\Library\Website\GuidedGeneration;

use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePageStrategy;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Website Guided Generation contract §8.2/§8.4, completed by this lane,
 * corrected by acceptance-correction Blockers 1/2/4/5/6. The single
 * orchestrator: build the deterministic plan -> generate -> validate
 * (one bounded retry on the WHOLE batch) -> bind media -> commit,
 * all-or-nothing (§8.2). A failure at any stage leaves the website's
 * EXISTING draft pages completely untouched and records exactly one
 * `failed` WebsiteGuidedGenerationAttempt row — it never partially
 * applies a batch, and never silently omits a required page.
 *
 * "Full generation" and "rebuild" share one identical implementation
 * (`run()`): a template-backed Website already has draft pages the
 * moment it is created (WebsiteStarterDraftService::createFromTemplate()
 * seeds deterministic starter content immediately, so the owner never
 * sees an empty shell), so even the very first guided generation must
 * REPLACE that starter content rather than append to it — the same
 * replace-the-entire-draft semantics a later rebuild needs. Both modes
 * atomically: lock the Website row, delete every existing draft page,
 * insert the complete validated+media-bound replacement batch, update
 * template provenance, and mark the attempt succeeded — all inside ONE
 * transaction, so a crash or exception at any point leaves the OLD
 * draft fully intact and the attempt correctly `failed` (never a
 * half-written page set, never a successful attempt row paired with a
 * page batch that didn't actually commit). The currently PUBLISHED
 * revision is never read or written here at all, so it stays live and
 * unaffected throughout and after every call regardless of mode.
 *
 * Idempotency (§8.4, Blocker 6): identity is NOT the caller-supplied
 * idempotency key alone — it deterministically incorporates the
 * selected template/version, the exact planned pages, and the same
 * canonical confirmed facts the generation is actually built from. Two
 * logically identical submissions (same website, same template, same
 * plan, same facts) for the same underlying request converge to the
 * same attempt via a per-Website Cache lock (Illuminate\Cache\LockProvider,
 * this app's file cache driver included) around the check-and-create
 * step, with the database's own unique constraint
 * (wgga_website_idempotency_unique) as a second, independent backstop
 * should two processes somehow race past the lock. A PRIOR FAILED
 * attempt with the identical material key never blocks a genuine retry
 * — only a `pending`/`succeeded` attempt is ever short-circuited to.
 */
final class GuidedGenerationCommitService
{
    public function __construct(
        private readonly GuidedWebsiteGenerationClient $client,
        private readonly GuidedGenerationOutputValidator $validator,
        private readonly MediaBindingService $mediaBinding,
        private readonly WebsiteDraftPageService $pages,
        private readonly WebsitePageStrategy $pageStrategy,
        private readonly PhotoBoothBlueprint $blueprintWriter,
    ) {
    }

    /**
     * @param  ?string  $fenceToken  independent-review correction round 4 (item 1) — the caller's current
     *         WebsiteGenerationCoordinator lease token. When given, the attempt created below is CAS-linked
     *         to the Website's lease, and the final page-commit transaction re-verifies the Website's lease
     *         still carries this EXACT token before writing anything — a worker whose lease was already
     *         reclaimed as stale can never commit pages. Null skips fencing entirely (a direct/internal
     *         caller that never went through the coordinator, e.g. existing focused tests of this service
     *         in isolation) — never used by either real HTTP controller.
     * @param  ?array{title: string, layout: string, body: ?string, images: array<int, string>}  $customSection  the owner's optional custom-section questionnaire answer (Website Builder redesign) — see WebsitePageStrategy::buildPlan()
     * @param  ?array<int, array{question: string, answer: string}>  $customerFaq  the owner's own FAQ answers, appended verbatim to the FAQ page's section — never sent to AI to rewrite (independent-review correction round 2)
     * @param  ?array<string, mixed>  $blueprint  the guided builder's context (tone, booking action, pricing style, …): when given, the
     *         pages are written by PhotoBoothBlueprint — complete default copy from the owner's confirmed facts, no AI call — and
     *         then bound, validated and committed exactly like AI output. Null keeps the AI-written path.
     */
    public function generateFull(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $idempotencyKey, ?string $fenceToken = null, ?array $customSection = null, ?array $customerFaq = null, ?array $catalogItemUids = null, ?array $serviceAreas = null, ?array $blueprint = null): WebsiteGuidedGenerationAttempt
    {
        return $this->run($business, $website, $template, $actorUserId, $idempotencyKey, WebsiteGuidedGenerationAttempt::MODE_FULL_GENERATION, $fenceToken, $customSection, $customerFaq, $catalogItemUids, $serviceAreas, $blueprint);
    }

    /**
     * Website Generator + Local SEO Completion §"EXISTING WEBSITE
     * REBUILD/UPGRADE" — the CURRENTLY PUBLISHED revision is never
     * touched by this call (see class docblock); only the mutable draft
     * `website_pages` rows are replaced. The owner must still take the
     * separate, explicit "Publish" action (WebsitePublisher, unchanged)
     * before a rebuild is ever visible to a visitor, and the
     * pre-rebuild published revision remains available for rollback
     * exactly as before.
     */
    /**
     * @param  ?array{title: string, layout: string, body: ?string, images: array<int, string>}  $customSection
     */
    public function rebuild(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $idempotencyKey, ?string $fenceToken = null, ?array $customSection = null, ?array $customerFaq = null, ?array $catalogItemUids = null, ?array $serviceAreas = null, ?array $blueprint = null): WebsiteGuidedGenerationAttempt
    {
        return $this->run($business, $website, $template, $actorUserId, $idempotencyKey, WebsiteGuidedGenerationAttempt::MODE_REBUILD, $fenceToken, $customSection, $customerFaq, $catalogItemUids, $serviceAreas, $blueprint);
    }

    /**
     * Independent-review correction round 4 (item 1) — replaces round 3's
     * `recoverStaleAttempt()`, which marked EVERY pending attempt for the
     * Website failed. That was wrong: only the ONE attempt a specific
     * expired lease actually owns (`website.generation_lease_attempt_uid`)
     * may ever be recovered — a different, genuinely active attempt that
     * merely happens to also be `pending` for the same Website must never
     * be touched. This table's own seam discipline (class docblock: "no
     * controller ever writes this table directly") is preserved: the
     * caller is WebsiteGenerationCoordinator, reacting to its own expired
     * lease, but the actual write stays here.
     */
    public function recoverAttemptByUid(string $attemptUid): void
    {
        WebsiteGuidedGenerationAttempt::where('uid', $attemptUid)
            ->where('status', WebsiteGuidedGenerationAttempt::STATUS_PENDING)
            ->update([
                'status' => WebsiteGuidedGenerationAttempt::STATUS_FAILED,
                'failure_reason' => 'Recovered from a stalled generation attempt.',
                'completed_at' => now(),
            ]);
    }

    private function run(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $callerIdempotencyKey, string $mode, ?string $fenceToken, ?array $customSection = null, ?array $customerFaq = null, ?array $catalogItemUids = null, ?array $serviceAreas = null, ?array $blueprint = null): WebsiteGuidedGenerationAttempt
    {
        $plan = $this->pageStrategy->buildPlan($business, $template, $website, $customSection, $catalogItemUids, $serviceAreas);
        $aiPlan = WebsitePageStrategy::withoutAiUnfillableSections($plan);
        $facts = $this->client->canonicalFacts($business);
        $materialBase = $this->materialIdempotencyBase($template, $mode, $plan, $facts, $callerIdempotencyKey, $blueprint);

        // §8.4/Blocker 6 — serializes every attempt for this ONE website
        // so two genuinely concurrent identical submissions never both
        // reach the create-and-spend-AI step; the second waits, then
        // converges to the first attempt's row below. Independent-review
        // correction round 4 — in practice this is now redundant with
        // WebsiteGenerationCoordinator's own non-blocking Website-level
        // lease (a second real HTTP request never reaches this method at
        // all while a lease is held), but is kept as defense in depth for
        // any direct caller that bypasses the coordinator.
        return Cache::lock('website-guided-generation:' . $website->id, 60)->block(15, function () use ($business, $website, $template, $actorUserId, $mode, $plan, $aiPlan, $materialBase, $fenceToken, $customSection, $customerFaq, $blueprint) {
            $existing = WebsiteGuidedGenerationAttempt::where('website_id', $website->id)
                ->where('idempotency_key', 'like', $materialBase . ':%')
                ->whereIn('status', [WebsiteGuidedGenerationAttempt::STATUS_PENDING, WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED])
                ->orderByDesc('id')
                ->first();
            // A SUCCEEDED attempt only stands in for a new request while its
            // pages still exist: if every page was since deleted, converging
            // on it would report success and build nothing (an empty site
            // the owner could never regenerate). A PENDING one always
            // converges — it is genuinely in flight.
            $stale = $existing !== null
                && $existing->status === WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED
                && ! $website->pages()->exists();
            if ($existing !== null && ! $stale) {
                return $existing;
            }

            $attemptNumber = WebsiteGuidedGenerationAttempt::where('website_id', $website->id)
                ->where('idempotency_key', 'like', $materialBase . ':%')
                ->count() + 1;
            $idempotencyKey = $materialBase . ':' . $attemptNumber;

            try {
                $attempt = WebsiteGuidedGenerationAttempt::create([
                    'website_id' => $website->id,
                    'template_key' => $template->key,
                    'mode' => $mode,
                    'idempotency_key' => $idempotencyKey,
                    'status' => WebsiteGuidedGenerationAttempt::STATUS_PENDING,
                    'created_by_user_id' => $actorUserId,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A second process somehow raced past the lock (e.g. a
                // different app server not sharing this cache) — the
                // database's own unique constraint is the backstop;
                // converge to whichever row won.
                return WebsiteGuidedGenerationAttempt::where('website_id', $website->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }

            // Independent-review correction round 4 (item 1) — CAS-link
            // this attempt to the Website's CURRENT lease. If the update
            // affects zero rows, this exact token no longer owns the
            // lease (it was already reclaimed as stale by a recovering
            // worker) — fail the attempt immediately rather than ever
            // calling AI or touching pages for a fenced-out caller.
            if ($fenceToken !== null) {
                $attached = Website::where('id', $website->id)
                    ->where('generation_lease_token', $fenceToken)
                    ->update(['generation_lease_attempt_uid' => $attempt->uid]);

                if ($attached === 0) {
                    $attempt->update([
                        'status' => WebsiteGuidedGenerationAttempt::STATUS_FAILED,
                        'failure_reason' => 'This generation was superseded before it could start.',
                        'completed_at' => now(),
                    ]);

                    return $attempt->fresh();
                }
            }

            return $this->generateValidateAndCommit($business, $website, $template, $plan, $aiPlan, $attempt, $fenceToken, $customSection, $customerFaq, $blueprint);
        });
    }

    /**
     * AI copy may describe the business, but it may never invent social proof or prices:
     *  - a `testimonials` section carries ONLY the owner's own confirmed testimonials (verbatim),
     *    or is dropped when there are none — an AI-written review is a fabricated review;
     *  - a service card's `price_label` survives only when that exact label is already in the
     *    facts the plan was built from (a catalog or service price), otherwise it is removed.
     *
     * @param  array<int, array<string, mixed>>  $pages  the validated, media-bound pages
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<string, mixed>>
     */
    private function groundInventedFacts(array $pages, Business $business, array $plan): array
    {
        $profile = BusinessKnowledgeProfile::where('business_id', $business->id)->first();
        $confirmed = $profile !== null
            && in_array(\App\Enums\Business\BusinessKnowledgeProfileFieldKey::Testimonials->value, app(\App\Library\Business\BusinessKnowledgeProfileManager::class)->completenessCheck($business)->presentFieldKeys, true);
        $real = $confirmed ? array_slice(array_values((array) $profile->testimonials), 0, 10) : [];

        $knownPrices = [];
        array_walk_recursive($plan, function ($value, $key) use (&$knownPrices) {
            if ($key === 'price_label' && is_string($value) && trim($value) !== '') {
                $knownPrices[trim($value)] = true;
            }
        });

        foreach ($pages as $pageIndex => $page) {
            $sections = [];

            foreach ((array) ($page['sections'] ?? []) as $section) {
                if (($section['type'] ?? null) === 'testimonials') {
                    if ($real === []) {
                        continue;
                    }

                    $section['data']['items'] = $real;
                }

                if (($section['type'] ?? null) === 'services') {
                    foreach ((array) ($section['data']['items'] ?? []) as $itemIndex => $item) {
                        $label = trim((string) ($item['price_label'] ?? ''));

                        if ($label !== '' && empty($item['catalog_item_uid']) && ! isset($knownPrices[$label])) {
                            $section['data']['items'][$itemIndex]['price_label'] = null;
                        }
                    }
                }

                $sections[] = $this->withoutInventedLinks($section, $business);
            }

            $pages[$pageIndex]['sections'] = $sections;
        }

        return $pages;
    }

    /**
     * A button the AI wrote may only lead somewhere the business really is: a page of this site ("/slug"),
     * its own phone (tel:), its own email (mailto:) or its own website's host. An invented domain or phone
     * number would break the site's NAP coherence, so such a button is dropped (the site CTA takes over).
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function withoutInventedLinks(array $section, Business $business): array
    {
        $data = (array) ($section['data'] ?? []);

        foreach (['primary_cta', 'secondary_cta'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && ! $this->linkIsReal((string) ($data[$key]['url'] ?? ''), $business)) {
                unset($data[$key]);
            }
        }

        if (isset($data['buttons']) && is_array($data['buttons'])) {
            $data['buttons'] = array_values(array_filter($data['buttons'], fn ($button) => is_array($button) && $this->linkIsReal((string) ($button['url'] ?? ''), $business)));
        }

        $section['data'] = $data;

        return $section;
    }

    private function linkIsReal(string $url, Business $business): bool
    {
        $url = trim($url);

        if ($url === '' || $url === '#') {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $digits = fn (?string $value) => substr((string) preg_replace('/\D+/', '', (string) $value), -10);

        if (stripos($url, 'tel:') === 0) {
            return $digits($business->phone) !== '' && $digits(substr($url, 4)) === $digits($business->phone);
        }

        if (stripos($url, 'mailto:') === 0) {
            return trim((string) $business->email) !== '' && strcasecmp(trim(substr($url, 7)), trim((string) $business->email)) === 0;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $own = strtolower((string) parse_url((string) $business->website_url, PHP_URL_HOST));

        return $host !== '' && $own !== '' && preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $own);
    }

    private function generateValidateAndCommit(Business $business, Website $website, WebsiteTemplate $template, array $plan, array $aiPlan, WebsiteGuidedGenerationAttempt $attempt, ?string $fenceToken, ?array $customSection = null, ?array $customerFaq = null, ?array $blueprint = null): WebsiteGuidedGenerationAttempt
    {
        $prohibitedClaims = BusinessKnowledgeProfile::where('business_id', $business->id)->value('prohibited_claims') ?? [];

        if ($blueprint !== null) {
            // The guided builder: complete default copy from the owner's confirmed facts. No AI call, so no budget
            // and nothing that can be unavailable; the same binding, validation and atomic commit follow.
            $aiPages = $this->blueprintWriter->pages($business, $website, $plan, $blueprint);
            $retryCount = 0;
        } else {
            // AI provider calls stay OUTSIDE any database transaction
            // (Blocker 5) — the only writes below happen after this
            // returns.
            [$aiPages, $retryCount] = $this->generateAndValidate($business, $aiPlan, $prohibitedClaims, $attempt->idempotency_key);
        }

        if ($fenceToken !== null && ! Website::where('id', $website->id)->where('generation_lease_token', $fenceToken)->exists()) {
            // Independent-review correction round 4 (item 1) — the AI
            // call took long enough that this lease was reclaimed as
            // stale while it was running. The work is simply discarded;
            // the recovering worker's own attempt (or the owner's next
            // retry) is the one that gets to commit.
            $attempt->update([
                'status' => WebsiteGuidedGenerationAttempt::STATUS_FAILED,
                'retry_count' => $retryCount,
                'failure_reason' => 'This generation was superseded while it was running.',
                'completed_at' => now(),
            ]);

            return $attempt->fresh();
        }

        if ($aiPages === null) {
            $attempt->update([
                'status' => WebsiteGuidedGenerationAttempt::STATUS_FAILED,
                'retry_count' => $retryCount,
                'failure_reason' => $this->client->lastCallWasBudgetExhausted()
                    ? 'The included AI generation budget is used up for this period.'
                    : ($this->client->lastCallWasUnavailable()
                        ? "Website generation isn't available in this environment right now. Your answers are saved — try again once it is."
                        : 'Generation did not produce a valid page batch.'),
                'completed_at' => now(),
            ]);

            return $attempt;
        }

        try {
            // Acceptance-correction round 2: merging/binding runs inside
            // the SAME catch as the commit transaction below — any
            // unexpected shape or binding failure here must also end in
            // a cleanly recorded `failed` attempt, never an uncaught
            // exception left as a stuck `pending` row or a bare 500.
            $merged = $this->mergePlanWithContent($plan, $aiPages);
            $bound = $this->mediaBinding->bind($website, $merged, $customSection, $customerFaq);
            $bound['pages'] = $this->groundInventedFacts($bound['pages'], $business, $plan);

            DB::transaction(function () use ($business, $website, $template, $bound, $attempt, $retryCount, $fenceToken) {
                // Blocker 4 — real rebuild semantics: lock the Website
                // row, then replace the ENTIRE draft page set atomically.
                // If any createPage() call below throws, the whole
                // transaction (including the delete) rolls back, so the
                // OLD draft is exactly as it was before this call.
                $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();

                // Independent-review correction round 4 (item 1) — the
                // FINAL fencing check, inside the very transaction that
                // writes pages, under the SAME row lock: a worker whose
                // lease was reclaimed between the check above and this
                // transaction's own lock acquisition can still never
                // replace pages. This is what actually makes the
                // guarantee atomic, not merely "checked somewhere first."
                if ($fenceToken !== null && $locked->generation_lease_token !== $fenceToken) {
                    throw new \RuntimeException('This generation was superseded before it could commit.');
                }

                // A rebuild must not undo the owner's indexing choices: once the owner has
                // let search engines find their pages, the rebuilt pages stay open, and a
                // page the owner hid on purpose stays hidden (matched by its address). A
                // first generation, or a site never released, stays hidden until reviewed.
                $previous = $locked->pages()->get(['slug', 'is_home', 'page_role', 'page_intent', 'noindex', 'noindex_explicit']);
                $ownerOpened = $locked->indexing_released_at !== null;
                $ownerHidden = $previous->filter(fn ($old) => $old->noindex && $old->noindex_explicit)->map(fn ($old) => $old->is_home ? '' : (string) $old->slug)->all();

                $locked->pages()->delete();

                foreach ($bound['pages'] as $page) {
                    $hiddenByOwner = in_array($page['is_home'] ? '' : (string) $page['slug'], $ownerHidden, true);

                    $identity = \App\Library\Website\Seo\WebsitePageIntent::forPlanPage($page);

                    $this->pages->createPage($locked, [
                        'title' => $page['title'],
                        'slug' => $page['slug'],
                        'is_home' => $page['is_home'],
                        'page_role' => $identity['role'],
                        'page_intent' => $identity['intent'],
                        'sections' => $page['sections'],
                        'seo_title' => $page['seo_title'] ?? null,
                        'meta_description' => $page['meta_description'] ?? null,
                        'noindex' => $hiddenByOwner || ! $ownerOpened,
                        'noindex_explicit' => $hiddenByOwner,
                    ]);
                }

                // A place page this rebuild no longer creates may already be indexed or linked to. Its address answers a permanent
                // redirect to the home page ONLY when the new home page's "Areas we serve" list names that exact place (a
                // normalized whole-name match — "Spokane" is not "Spokane Valley"). A place the owner dropped is not redirected
                // (an honest 404 beats a misleading landing page). Existing redirects — the owner's own, or generated earlier,
                // which cannot be told apart — are never changed or removed here, and never point anywhere but a live page.
                $newSlugs = collect($bound['pages'])->pluck('slug')->filter()->all();
                $coveredPlaces = $this->homeCoveredPlaces($business, $bound['pages']);
                $lost = $previous->filter(fn ($old) => ! $old->is_home && $old->slug !== null && ($old->page_role === 'location' || str_starts_with((string) $old->slug, 'serving-')))
                    ->reject(fn ($old) => in_array($old->slug, $newSlugs, true))
                    ->filter(fn ($old) => self::placeIsCovered((string) ($old->page_intent['place'] ?? ''), $coveredPlaces))
                    ->pluck('slug')->values()->all();
                $redirects = (array) ($locked->custom_redirects ?? []);
                foreach ($lost as $slug) {
                    // Added only while there is room: the cap never evicts a redirect somebody else may have set.
                    if (! array_key_exists(strtolower((string) $slug), $redirects) && count($redirects) < \App\Library\Website\Seo\WebsiteRedirectManager::MAX) {
                        $redirects[strtolower((string) $slug)] = '';
                    }
                }

                $locked->update([
                    'custom_redirects' => $redirects === [] ? $locked->custom_redirects : $redirects,
                    // The template's own tokens, plus the owner's brand colour,
                    // logo and hero image (never lost to a rebuild).
                    'theme' => array_merge($template->theme, \App\Library\Website\Design\WebsiteLookService::ownerTokens($locked->theme)),
                    'template_key' => $template->key,
                    // Independent-review correction round 3 (item 11) —
                    // the one place real Website page content actually
                    // changes: any "presentation changes pending" flag a
                    // post-generation setup edit set is cleared here,
                    // whether this run is a first-time generation (never
                    // set yet) or the deliberate rebuild that specific
                    // flag exists to require.
                    'presentation_changes_pending_at' => null,
                ]);

                // Blocker 5 — the attempt's success is marked INSIDE the
                // same transaction as the page batch, so "pages
                // committed" and "attempt succeeded" always converge
                // together; a crash between them is impossible because
                // there is no "between" — it is one commit.
                $attempt->update([
                    'status' => WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED,
                    'retry_count' => $retryCount,
                    'warnings' => $bound['warnings'],
                    'completed_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            $attempt->update([
                'status' => WebsiteGuidedGenerationAttempt::STATUS_FAILED,
                'retry_count' => $retryCount,
                'failure_reason' => 'Commit failed: ' . $e->getMessage(),
                'completed_at' => now(),
            ]);

            return $attempt;
        }

        return $attempt->fresh();
    }

    /**
     * @return array{0: ?array, 1: int} the validated page batch (or null
     *                                  on unrecoverable failure) and how many retries were spent
     */
    private function generateAndValidate(Business $business, array $aiPlan, array $prohibitedClaims, string $idempotencyKey): array
    {
        $attempts = 0;

        // §8.4 — exactly one bounded corrective retry against the whole
        // batch; a second failure ends the attempt. Each retry gets its
        // OWN idempotency key (the attempt's own base key plus the
        // retry number) — a retry is a genuinely distinct provider call,
        // never a duplicate of the first.
        while ($attempts <= 1) {
            $pages = $this->client->generate($business, $aiPlan, null, $idempotencyKey . ':retry' . $attempts);

            if ($pages !== null) {
                try {
                    $this->validator->validate($pages, $aiPlan, $prohibitedClaims);

                    return [$pages, $attempts];
                } catch (Throwable) {
                    // Acceptance-correction round 2: catches more than
                    // ValidationException on purpose — a provider that
                    // returns a well-formed JSON envelope with an
                    // unexpected inner shape (e.g. `sections` as a
                    // string, a page entry that isn't an object) must
                    // still fall through to the bounded retry/failure
                    // path below, never an uncaught TypeError reaching
                    // the HTTP layer as a 500 or leaving this attempt
                    // stuck `pending` forever.
                }
            }

            $attempts++;
        }

        return [null, $attempts - 1];
    }

    /**
     * The confirmed service areas (city AND region) that the new home page's "Areas we serve" list actually shows. It mirrors
     * MediaBindingService::bindAreaHub: the primary city first, then the saved areas, one entry per city name, at most 12.
     * Empty when the home page has no such list.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array{0: string, 1: ?string, 2: bool}> [city slug, region code|null, saved under several regions]
     */
    private function homeCoveredPlaces(Business $business, array $pages): array
    {
        $home = collect($pages)->firstWhere('is_home', true);
        $hasHub = collect((array) ($home['sections'] ?? []))->contains(fn ($section) => str_contains(mb_strtolower((string) ($section['data']['heading'] ?? '')), 'areas we serve'));

        if (! $hasHub) {
            return [];
        }

        $location = $business->primaryLocation()->first();
        $entries = array_merge([trim(collect([$location?->city, $location?->region])->filter()->implode(', '))], (array) ($location?->service_area_cities ?? []));
        $covered = [];
        $regionsByCity = [];

        foreach ($entries as $entry) {
            [$city, $region] = self::placeParts((string) $entry);

            if ($city === '') {
                continue;
            }

            $regionsByCity[$city][$region ?? ''] = true;

            if (! isset($covered[$city]) && count($covered) < 12) {
                $covered[$city] = [$city, $region, false];
            }
        }

        // A city saved under several regions ("Portland, OR" and "Portland, ME", or one of them without a region) is ambiguous.
        foreach ($covered as $city => $entry) {
            $covered[$city][2] = count($regionsByCity[$city]) > 1;
        }

        return array_values($covered);
    }

    /**
     * Whether a removed page's place is one of the covered places: the city must match exactly; when both sides name a region it
     * must match too; when either lacks one (older values) it matches only if that city is covered by a single place. Anything
     * ambiguous is not a match.
     *
     * @param  array<int, array{0: string, 1: ?string, 2: bool}>  $covered
     */
    private static function placeIsCovered(string $place, array $covered): bool
    {
        [$city, $region] = self::placeParts($place);

        if ($city === '') {
            return false;
        }

        $sameCity = array_values(array_filter($covered, fn (array $c) => $c[0] === $city));

        if ($sameCity === []) {
            return false;
        }

        if ($region !== null && $sameCity[0][1] !== null) {
            return count(array_filter($sameCity, fn (array $c) => $c[1] === $region)) === 1;
        }

        return count($sameCity) === 1 && ! $sameCity[0][2];
    }

    /**
     * "Portland, OR" => ['portland', 'or']; "Portland" => ['portland', null]. Case, punctuation and spacing are ignored and a US
     * state name is read as its code ("Oregon" = "OR"). The city is the whole name before the comma, so "Spokane Valley" never
     * equals "Spokane".
     *
     * @return array{0: string, 1: ?string}
     */
    private static function placeParts(string $place): array
    {
        $parts = explode(',', $place, 2);
        $city = \Illuminate\Support\Str::slug($parts[0]);
        $region = isset($parts[1]) ? \Illuminate\Support\Str::slug($parts[1]) : '';
        $states = ['alabama' => 'al', 'alaska' => 'ak', 'arizona' => 'az', 'arkansas' => 'ar', 'california' => 'ca', 'colorado' => 'co', 'connecticut' => 'ct', 'delaware' => 'de', 'district-of-columbia' => 'dc', 'florida' => 'fl', 'georgia' => 'ga', 'hawaii' => 'hi', 'idaho' => 'id', 'illinois' => 'il', 'indiana' => 'in', 'iowa' => 'ia', 'kansas' => 'ks', 'kentucky' => 'ky', 'louisiana' => 'la', 'maine' => 'me', 'maryland' => 'md', 'massachusetts' => 'ma', 'michigan' => 'mi', 'minnesota' => 'mn', 'mississippi' => 'ms', 'missouri' => 'mo', 'montana' => 'mt', 'nebraska' => 'ne', 'nevada' => 'nv', 'new-hampshire' => 'nh', 'new-jersey' => 'nj', 'new-mexico' => 'nm', 'new-york' => 'ny', 'north-carolina' => 'nc', 'north-dakota' => 'nd', 'ohio' => 'oh', 'oklahoma' => 'ok', 'oregon' => 'or', 'pennsylvania' => 'pa', 'rhode-island' => 'ri', 'south-carolina' => 'sc', 'south-dakota' => 'sd', 'tennessee' => 'tn', 'texas' => 'tx', 'utah' => 'ut', 'vermont' => 'vt', 'virginia' => 'va', 'washington' => 'wa', 'west-virginia' => 'wv', 'wisconsin' => 'wi', 'wyoming' => 'wy'];

        return [$city, $region === '' ? null : ($states[$region] ?? $region)];
    }

    /**
     * Merges the AI's written content into the deterministic plan's OWN
     * authoritative page_type/is_home/slug — never the reverse. AI's
     * output can only ever supply title/seo_title/meta_description/
     * sections for a page_key the plan already named (the validator
     * already proved every plan page_key has exactly one matching
     * output entry before this is ever called).
     *
     * @return array<int, array{page_key: string, page_type: string, is_home: bool, slug: ?string, title: string, seo_title: ?string, meta_description: ?string, sections: array}>
     */
    private function mergePlanWithContent(array $plan, array $aiPages): array
    {
        $contentByKey = collect($aiPages)->keyBy('page_key');

        return array_values(array_map(function ($planPage) use ($contentByKey) {
            $content = $contentByKey->get($planPage['page_key']);

            return [
                'page_key' => $planPage['page_key'],
                'page_type' => $planPage['page_type'],
                'is_home' => $planPage['is_home'],
                'slug' => $planPage['slug'],
                'title' => $content['title'] ?? $planPage['title'],
                'entity' => $planPage['entity'] ?? null,
                'seo_title' => $content['seo_title'] ?? null,
                'meta_description' => $content['meta_description'] ?? null,
                'sections' => $content['sections'] ?? [],
            ];
        }, $plan));
    }

    /**
     * Deterministic identity (Blocker 6): a change in template version,
     * planned pages, or canonical confirmed facts always produces a
     * different base — so a stale cached form resubmission against
     * now-different underlying data is never mistaken for a duplicate
     * of a materially different request. The caller-supplied key is
     * still folded in (distinguishes two genuinely separate, deliberate
     * submissions that happen to share identical facts — e.g. two
     * different rebuild form renders) but is never trusted alone.
     */
    private function materialIdempotencyBase(WebsiteTemplate $template, string $mode, array $plan, array $facts, string $callerIdempotencyKey, ?array $blueprint = null): string
    {
        $material = json_encode(($blueprint !== null ? ['blueprint' => $blueprint] : []) + [
            'template_key' => $template->key,
            'manifest_version' => $template->manifest_version,
            'mode' => $mode,
            'plan' => $plan,
            'facts' => $facts,
            'caller_key' => $callerIdempotencyKey,
        ]);

        return substr(hash('sha256', (string) $material), 0, 55);
    }
}
