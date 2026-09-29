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
use Illuminate\Validation\ValidationException;
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
    ) {
    }

    public function generateFull(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $idempotencyKey): WebsiteGuidedGenerationAttempt
    {
        return $this->run($business, $website, $template, $actorUserId, $idempotencyKey, WebsiteGuidedGenerationAttempt::MODE_FULL_GENERATION);
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
    public function rebuild(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $idempotencyKey): WebsiteGuidedGenerationAttempt
    {
        return $this->run($business, $website, $template, $actorUserId, $idempotencyKey, WebsiteGuidedGenerationAttempt::MODE_REBUILD);
    }

    private function run(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $callerIdempotencyKey, string $mode): WebsiteGuidedGenerationAttempt
    {
        $plan = $this->pageStrategy->buildPlan($business, $template, $website);
        $aiPlan = WebsitePageStrategy::withoutAiGallerySections($plan);
        $facts = $this->client->canonicalFacts($business);
        $materialBase = $this->materialIdempotencyBase($template, $mode, $plan, $facts, $callerIdempotencyKey);

        // §8.4/Blocker 6 — serializes every attempt for this ONE website
        // so two genuinely concurrent identical submissions never both
        // reach the create-and-spend-AI step; the second waits, then
        // converges to the first attempt's row below.
        return Cache::lock('website-guided-generation:' . $website->id, 60)->block(15, function () use ($business, $website, $template, $actorUserId, $mode, $plan, $aiPlan, $materialBase) {
            $existing = WebsiteGuidedGenerationAttempt::where('website_id', $website->id)
                ->where('idempotency_key', 'like', $materialBase . ':%')
                ->whereIn('status', [WebsiteGuidedGenerationAttempt::STATUS_PENDING, WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED])
                ->orderByDesc('id')
                ->first();
            if ($existing !== null) {
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

            return $this->generateValidateAndCommit($business, $website, $template, $plan, $aiPlan, $attempt);
        });
    }

    private function generateValidateAndCommit(Business $business, Website $website, WebsiteTemplate $template, array $plan, array $aiPlan, WebsiteGuidedGenerationAttempt $attempt): WebsiteGuidedGenerationAttempt
    {
        $prohibitedClaims = BusinessKnowledgeProfile::where('business_id', $business->id)->value('prohibited_claims') ?? [];

        // AI provider calls stay OUTSIDE any database transaction
        // (Blocker 5) — the only writes below happen after this
        // returns.
        [$aiPages, $retryCount] = $this->generateAndValidate($business, $aiPlan, $prohibitedClaims);

        if ($aiPages === null) {
            $attempt->update([
                'status' => WebsiteGuidedGenerationAttempt::STATUS_FAILED,
                'retry_count' => $retryCount,
                'failure_reason' => $this->client->lastCallWasBudgetExhausted()
                    ? 'The included AI generation budget is used up for this period.'
                    : 'Generation did not produce a valid page batch.',
                'completed_at' => now(),
            ]);

            return $attempt;
        }

        $merged = $this->mergePlanWithContent($plan, $aiPages);
        $bound = $this->mediaBinding->bind($website, $merged);

        try {
            DB::transaction(function () use ($website, $template, $bound, $attempt, $retryCount) {
                // Blocker 4 — real rebuild semantics: lock the Website
                // row, then replace the ENTIRE draft page set atomically.
                // If any createPage() call below throws, the whole
                // transaction (including the delete) rolls back, so the
                // OLD draft is exactly as it was before this call.
                $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
                $locked->pages()->delete();

                foreach ($bound['pages'] as $page) {
                    $this->pages->createPage($locked, [
                        'title' => $page['title'],
                        'slug' => $page['slug'],
                        'is_home' => $page['is_home'],
                        'sections' => $page['sections'],
                        'seo_title' => $page['seo_title'] ?? null,
                        'meta_description' => $page['meta_description'] ?? null,
                        'noindex' => true,
                    ]);
                }

                $locked->update([
                    'theme' => $template->theme,
                    'template_key' => $template->key,
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
    private function generateAndValidate(Business $business, array $aiPlan, array $prohibitedClaims): array
    {
        $attempts = 0;

        // §8.4 — exactly one bounded corrective retry against the whole
        // batch; a second failure ends the attempt.
        while ($attempts <= 1) {
            $pages = $this->client->generate($business, $aiPlan, null);

            if ($pages !== null) {
                try {
                    $this->validator->validate($pages, $aiPlan, $prohibitedClaims);

                    return [$pages, $attempts];
                } catch (ValidationException) {
                    // fall through to retry/give up below
                }
            }

            $attempts++;
        }

        return [null, $attempts - 1];
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
    private function materialIdempotencyBase(WebsiteTemplate $template, string $mode, array $plan, array $facts, string $callerIdempotencyKey): string
    {
        $material = json_encode([
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
