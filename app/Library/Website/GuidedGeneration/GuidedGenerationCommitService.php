<?php

namespace App\Library\Website\GuidedGeneration;

use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use App\Library\Website\WebsiteDraftPageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Website Guided Generation contract §8.2/§8.4, completed by this lane.
 * The single orchestrator: generate -> validate (one bounded retry on
 * the WHOLE batch) -> bind media -> commit, all-or-nothing (§8.2). A
 * failure at any stage leaves ZERO pages created or modified and
 * records exactly one `failed` WebsiteGuidedGenerationAttempt row — it
 * never partially applies a batch, and never silently omits a required
 * page.
 *
 * Idempotent: a second call with the SAME (website, idempotency_key)
 * pair short-circuits to the already-recorded attempt instead of
 * generating (or spending AI budget) twice for one logical submission.
 */
final class GuidedGenerationCommitService
{
    public function __construct(
        private readonly GuidedWebsiteGenerationClient $client,
        private readonly GuidedGenerationOutputValidator $validator,
        private readonly MediaBindingService $mediaBinding,
        private readonly WebsiteDraftPageService $pages,
    ) {
    }

    public function generateFull(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $idempotencyKey): WebsiteGuidedGenerationAttempt
    {
        return $this->run($business, $website, $template, $actorUserId, $idempotencyKey, WebsiteGuidedGenerationAttempt::MODE_FULL_GENERATION);
    }

    /**
     * Website Generator + Local SEO Completion §"EXISTING WEBSITE
     * REBUILD/UPGRADE" — identical pipeline and identical atomicity
     * guarantee as a full generation. The caller (the rebuild
     * controller action) is responsible for the product-level safety
     * rule that makes this non-destructive: it never touches
     * `websites.published_revision_id`, so the CURRENTLY PUBLISHED
     * revision stays live throughout and after this call — only the
     * mutable draft `website_pages` rows this method creates are new;
     * nothing here publishes them.
     */
    public function rebuild(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $idempotencyKey): WebsiteGuidedGenerationAttempt
    {
        return $this->run($business, $website, $template, $actorUserId, $idempotencyKey, WebsiteGuidedGenerationAttempt::MODE_REBUILD);
    }

    private function run(Business $business, Website $website, WebsiteTemplate $template, int $actorUserId, string $idempotencyKey, string $mode): WebsiteGuidedGenerationAttempt
    {
        $existing = WebsiteGuidedGenerationAttempt::where('website_id', $website->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $attempt = WebsiteGuidedGenerationAttempt::create([
            'website_id' => $website->id,
            'template_key' => $template->key,
            'mode' => $mode,
            'idempotency_key' => $idempotencyKey,
            'status' => WebsiteGuidedGenerationAttempt::STATUS_PENDING,
            'created_by_user_id' => $actorUserId,
        ]);

        $prohibitedClaims = BusinessKnowledgeProfile::where('business_id', $business->id)->value('prohibited_claims') ?? [];

        [$pages, $retryCount] = $this->generateAndValidate($business, $template, $prohibitedClaims);

        if ($pages === null) {
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

        $bound = $this->mediaBinding->bind($website, $pages);

        try {
            DB::transaction(function () use ($website, $bound) {
                foreach ($bound['pages'] as $page) {
                    $isHome = ($page['page_type'] ?? null) === 'home';
                    $slug = $isHome ? null : (! empty($page['slug']) ? $page['slug'] : Str::slug($page['title']));

                    $this->pages->createPage($website, [
                        'title' => $page['title'],
                        'slug' => $slug,
                        'is_home' => $isHome,
                        'sections' => $page['sections'],
                        'seo_title' => $page['seo_title'] ?? null,
                        'meta_description' => $page['meta_description'] ?? null,
                        'noindex' => true,
                    ]);
                }
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

        $attempt->update([
            'status' => WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED,
            'retry_count' => $retryCount,
            'warnings' => $bound['warnings'],
            'completed_at' => now(),
        ]);

        return $attempt;
    }

    /**
     * @return array{0: ?array, 1: int} the validated page batch (or null
     *                                  on unrecoverable failure) and how many retries were spent
     */
    private function generateAndValidate(Business $business, WebsiteTemplate $template, array $prohibitedClaims): array
    {
        $attempts = 0;

        // §8.4 — exactly one bounded corrective retry against the whole
        // batch; a second failure ends the attempt.
        while ($attempts <= 1) {
            $pages = $this->client->generate($business, $template, null);

            if ($pages !== null) {
                try {
                    $this->validator->validate($pages, $template, $prohibitedClaims);

                    return [$pages, $attempts];
                } catch (ValidationException) {
                    // fall through to retry/give up below
                }
            }

            $attempts++;
        }

        return [null, $attempts - 1];
    }
}
