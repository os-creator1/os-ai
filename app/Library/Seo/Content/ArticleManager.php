<?php

namespace App\Library\Seo\Content;

use App\Enums\Seo\ArticleIntent;
use App\Enums\Seo\ArticleStatus;
use App\Exceptions\Seo\ArticleException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Website;
use App\Models\WebsiteArticle;
use App\Models\WebsiteArticleSlugHistory;
use App\Models\WebsiteAsset;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * SEO Content Engine V1 — THE write authority for articles. Controllers, the AI drafter and the
 * scheduler all go through here, so there is one place that decides:
 *
 *  - tenancy: every read and write is scoped to the Business it is called with. An article uid that
 *    belongs to another Business is simply "not found";
 *  - the lifecycle (ArticleStatus::allowedNext) and what each step requires;
 *  - slugs, and the redirect row that keeps an indexed URL alive when a published article's slug changes;
 *  - that an article is never published by an AI or a payload: only publish()/publishDue(), called from an
 *    explicit owner action or an owner-set schedule, can make one public.
 *
 * Nothing here calls an AI or a provider, and nothing here deletes an article: archiving is how a published
 * article leaves the index, and its slug stays reserved so the URL is never re-used by accident.
 */
final class ArticleManager
{
    /** Fields a caller may set. Everything else (status, business, website, timestamps) is managed here. */
    private const EDITABLE = [
        'title', 'slug', 'excerpt', 'body', 'seo_title', 'meta_description', 'noindex', 'author_name',
        'primary_topic', 'search_intent', 'supports_page_uid', 'opportunity_key', 'source', 'ai_generated',
    ];

    public function __construct(
        private readonly ArticleAnalyzer $analyzer,
        private readonly ArticleCannibalizationGuard $guard,
        private readonly ArticleSiteInventory $inventory,
        private readonly ArticleCatalogFacts $catalog,
    ) {
    }

    public function find(Business $business, string $uid): ?WebsiteArticle
    {
        return WebsiteArticle::query()->where('business_id', $business->id)->where('uid', $uid)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes  see EDITABLE, plus `featured_asset_uid` and `business_location_id`
     */
    public function create(int $actorId, Business $business, array $attributes): WebsiteArticle
    {
        $website = $this->websiteOrFail($business);

        return DB::transaction(function () use ($actorId, $business, $website, $attributes) {
            $article = new WebsiteArticle();
            $article->business_id = $business->id;
            $article->website_id = $website->id;
            $article->status = ArticleStatus::Draft;
            $article->noindex = false;
            $article->ai_generated = false;
            $article->source = 'manual';
            $article->created_by_user_id = $actorId;

            $title = trim((string) ($attributes['title'] ?? ''));
            $article->title = $title !== '' ? $title : 'Untitled article';

            $slug = trim((string) ($attributes['slug'] ?? ''));
            $article->slug = $slug !== '' ? $this->assertSlug($website, $slug, null) : ArticleSlugger::unique($website->id, $article->title);

            $this->fill($article, $business, $website, $attributes);
            $article->source = in_array($article->source, ['manual', 'opportunity', 'ai_draft', 'autopilot'], true) ? $article->source : 'manual';
            $article->updated_by_user_id = $actorId;
            $article->save();

            return $article;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(int $actorId, Business $business, WebsiteArticle $article, array $attributes): WebsiteArticle
    {
        $website = $this->websiteOrFail($business);

        return DB::transaction(function () use ($actorId, $business, $website, $article, $attributes) {
            $article = $this->locked($business, $article);

            // A PUBLISHED article is never changed by Save: edits go to its pending draft, and the public page
            // stays exactly as it is until publish() ("Publish update") deliberately replaces it.
            if ($article->status === ArticleStatus::Published) {
                return $this->saveDraftChanges($actorId, $business, $website, $article, $attributes);
            }

            $before = [$article->title, $article->body, $article->excerpt];

            if (array_key_exists('slug', $attributes)) {
                $new = trim((string) $attributes['slug']);

                if ($new !== '' && $new !== $article->slug) {
                    $new = $this->assertSlug($website, $new, $article->id);
                    $this->retireSlug($article, $website, $new);
                    $article->slug = $new;
                }
            }

            unset($attributes['slug']);
            $this->fill($article, $business, $website, $attributes);

            if ($article->hasBeenPublished() && $before !== [$article->title, $article->body, $article->excerpt]) {
                $article->content_updated_at = now();
            }

            $article->updated_by_user_id = $actorId;
            $article->save();

            return $article;
        });
    }

    public function publish(int $actorId, Business $business, WebsiteArticle $article, bool $acknowledgeOverlap = false): WebsiteArticle
    {
        return DB::transaction(function () use ($actorId, $business, $article, $acknowledgeOverlap) {
            $article = $this->locked($business, $article);

            $this->assertTransition($article, ArticleStatus::Published);
            // The readiness checks judge what WILL be live: the pending draft laid over the article.
            $this->assertReady($business, $article->effective(), $acknowledgeOverlap);

            if ($article->status === ArticleStatus::Published && $article->hasPendingDraft()) {
                $this->applyPendingDraft($business, $article);
            }

            $article->status = ArticleStatus::Published;
            $article->published_at ??= now();
            $article->scheduled_at = null;
            $article->archived_at = null;
            $article->updated_by_user_id = $actorId;
            $article->save();

            return $article;
        });
    }

    public function schedule(int $actorId, Business $business, WebsiteArticle $article, CarbonInterface $at, bool $acknowledgeOverlap = false): WebsiteArticle
    {
        if ($at->lessThanOrEqualTo(now()->addMinute())) {
            throw new ArticleException(ArticleException::BAD_SCHEDULE, 'Choose a time in the future.');
        }

        return DB::transaction(function () use ($actorId, $business, $article, $at, $acknowledgeOverlap) {
            $article = $this->locked($business, $article);

            $this->assertTransition($article, ArticleStatus::Scheduled);
            $this->assertReady($business, $article, $acknowledgeOverlap);

            $article->status = ArticleStatus::Scheduled;
            $article->scheduled_at = $at;
            $article->updated_by_user_id = $actorId;
            $article->save();

            return $article;
        });
    }

    /** Throws away a published article's pending draft; the live article was never touched. */
    public function discardDraft(int $actorId, Business $business, WebsiteArticle $article): WebsiteArticle
    {
        return DB::transaction(function () use ($actorId, $business, $article) {
            $article = $this->locked($business, $article);
            $article->draft_payload = null;
            $article->draft_saved_at = null;
            $article->updated_by_user_id = $actorId;
            $article->save();

            return $article;
        });
    }

    /** Scheduled or Archived back to Draft. */
    public function backToDraft(int $actorId, Business $business, WebsiteArticle $article): WebsiteArticle
    {
        return DB::transaction(function () use ($actorId, $business, $article) {
            $article = $this->locked($business, $article);

            $this->assertTransition($article, ArticleStatus::Draft);

            $article->status = ArticleStatus::Draft;
            $article->scheduled_at = null;
            $article->archived_at = null;
            $article->updated_by_user_id = $actorId;
            $article->save();

            return $article;
        });
    }

    public function archive(int $actorId, Business $business, WebsiteArticle $article): WebsiteArticle
    {
        return DB::transaction(function () use ($actorId, $business, $article) {
            $article = $this->locked($business, $article);

            $this->assertTransition($article, ArticleStatus::Archived);

            $article->status = ArticleStatus::Archived;
            $article->draft_payload = null;
            $article->draft_saved_at = null;
            $article->scheduled_at = null;
            $article->archived_at = now();
            $article->updated_by_user_id = $actorId;
            $article->save();

            return $article;
        });
    }

    /**
     * Publishes every Scheduled article whose time has come. Safety rule: what was schedule-able
     * yesterday is RE-CHECKED now — an article that no longer passes (a catalog price removed, the body
     * edited into a blocker) goes back to Draft instead of going live.
     *
     * @return array{published: int, returned_to_draft: int}
     */
    public function publishDue(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $published = 0;
        $returned = 0;

        $due = WebsiteArticle::query()
            ->where('status', ArticleStatus::Scheduled->value)
            ->where('scheduled_at', '<=', $now)
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');

        foreach ($due as $id) {
            DB::transaction(function () use ($id, $now, &$published, &$returned) {
                $article = WebsiteArticle::query()->whereKey($id)->lockForUpdate()->first();

                if ($article === null || $article->status !== ArticleStatus::Scheduled || $article->scheduled_at === null || $article->scheduled_at->greaterThan($now)) {
                    return;
                }

                $business = Business::query()->find($article->business_id);

                if ($business === null || $this->analyzer->blockers($business, $article) !== []) {
                    $article->status = ArticleStatus::Draft;
                    $article->scheduled_at = null;
                    $article->save();
                    $returned++;

                    return;
                }

                $article->status = ArticleStatus::Published;
                $article->published_at ??= $now;
                $article->scheduled_at = null;
                $article->save();
                $published++;
            });
        }

        return ['published' => $published, 'returned_to_draft' => $returned];
    }

    /** Marks the article as reviewed today (clears a freshness reminder without changing the text). */
    public function markReviewed(Business $business, WebsiteArticle $article): WebsiteArticle
    {
        $article = $this->locked($business, $article);
        $article->last_reviewed_at = now();
        $article->save();

        return $article;
    }

    /** Fields a pending draft may hold (everything the editor can change, plus what is derived from it). */
    private const DRAFTABLE = [
        'title', 'slug', 'excerpt', 'body', 'seo_title', 'meta_description', 'noindex', 'author_name', 'primary_topic',
        'topic_signature', 'search_intent', 'supports_page_uid', 'featured_asset_id', 'business_location_id', 'referenced_catalog_uids',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveDraftChanges(int $actorId, Business $business, Website $website, WebsiteArticle $article, array $attributes): WebsiteArticle
    {
        // Start from the existing pending draft (or the live article), validate exactly as an ordinary edit would.
        $working = clone $article->effective();

        if (array_key_exists('slug', $attributes)) {
            $new = trim((string) $attributes['slug']);

            if ($new !== '' && $new !== $working->slug) {
                $working->slug = $this->assertSlug($website, $new, $article->id);
            }
        }

        unset($attributes['slug']);
        $this->fill($working, $business, $website, $attributes);

        // Only what differs from the live article is kept; an identical save leaves no pending draft at all.
        $live = $article->only(self::DRAFTABLE);
        $diff = array_filter(
            $working->only(self::DRAFTABLE),
            fn ($value, $key) => $value !== $live[$key],
            ARRAY_FILTER_USE_BOTH,
        );

        $article->draft_payload = $diff === [] ? null : $diff;
        $article->draft_saved_at = $diff === [] ? null : now();
        $article->updated_by_user_id = $actorId;
        $article->save();

        return $article;
    }

    /** "Publish update": the pending draft becomes the live article; a changed slug leaves its redirect. */
    private function applyPendingDraft(Business $business, WebsiteArticle $article): void
    {
        $website = $this->websiteOrFail($business);
        $draft = $article->draft_payload;

        if (isset($draft['slug']) && $draft['slug'] !== $article->slug) {
            $this->assertSlug($website, (string) $draft['slug'], $article->id);
            $this->retireSlug($article, $website, (string) $draft['slug']);
        }

        $before = [$article->title, $article->body, $article->excerpt];
        $article->forceFill($draft);

        if ($before !== [$article->title, $article->body, $article->excerpt]) {
            $article->content_updated_at = now();
        }

        $article->draft_payload = null;
        $article->draft_saved_at = null;
    }

    private function websiteOrFail(Business $business): Website
    {
        $website = Website::query()->where('business_id', $business->id)->first();

        if ($website === null) {
            throw new ArticleException(ArticleException::NO_WEBSITE, 'Create your website first. Articles are published through it.');
        }

        return $website;
    }

    private function locked(Business $business, WebsiteArticle $article): WebsiteArticle
    {
        $fresh = WebsiteArticle::query()->where('business_id', $business->id)->whereKey($article->id)->lockForUpdate()->first();

        if ($fresh === null) {
            throw new ArticleException(ArticleException::NOT_FOUND, 'Article not found.');
        }

        return $fresh;
    }

    private function assertTransition(WebsiteArticle $article, ArticleStatus $to): void
    {
        if (! in_array($to, $article->status->allowedNext(), true)) {
            throw new ArticleException(ArticleException::BAD_TRANSITION, 'A ' . strtolower($article->status->label()) . ' article cannot become ' . strtolower($to->label()) . '.');
        }
    }

    private function assertReady(Business $business, WebsiteArticle $article, bool $acknowledgeOverlap): void
    {
        $blockers = $this->analyzer->blockers($business, $article);

        if ($blockers !== []) {
            throw new ArticleException(ArticleException::NOT_READY, 'This article is not ready to publish.', $blockers);
        }

        $overlap = $this->guard->check($business, array_filter([$article->title, $article->primary_topic]), (int) $article->id);

        if ($overlap->strong() && ! $acknowledgeOverlap) {
            throw new ArticleException(
                ArticleException::OVERLAP_UNACKNOWLEDGED,
                'This topic strongly overlaps something you already have.',
                array_map(fn (array $f) => $f['reason'], $overlap->strongFindings()),
            );
        }
    }

    private function assertSlug(Website $website, string $slug, ?int $exceptId): string
    {
        if (! ArticleSlugger::isValid($slug)) {
            throw new ArticleException(ArticleException::INVALID_SLUG, 'Use lowercase letters, numbers and hyphens only (for example "how-much-does-a-photo-booth-cost").');
        }

        if (ArticleSlugger::isTaken($website->id, $slug, $exceptId)) {
            throw new ArticleException(ArticleException::SLUG_TAKEN, 'Another article already uses that web address.');
        }

        return $slug;
    }

    /**
     * A published (or once-published) article's old slug becomes a permanent redirect. If the new slug is
     * one the article itself used to have, that history row is released so it is simply live again.
     */
    private function retireSlug(WebsiteArticle $article, Website $website, string $newSlug): void
    {
        WebsiteArticleSlugHistory::query()
            ->where('website_id', $website->id)
            ->where('website_article_id', $article->id)
            ->where('slug', $newSlug)
            ->delete();

        if (! $article->hasBeenPublished()) {
            return;
        }

        WebsiteArticleSlugHistory::query()->firstOrCreate(
            ['website_id' => $website->id, 'slug' => $article->slug],
            ['website_article_id' => $article->id, 'created_at' => now()],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fill(WebsiteArticle $article, Business $business, Website $website, array $attributes): void
    {
        foreach (self::EDITABLE as $field) {
            if ($field === 'slug' || ! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];

            $article->{$field} = match ($field) {
                'noindex', 'ai_generated' => (bool) $value,
                'body' => $value === null ? null : (string) $value,
                default => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            };
        }

        if ($article->title === null || trim((string) $article->title) === '') {
            $article->title = 'Untitled article';
        }

        if ($article->search_intent !== null && ArticleIntent::tryFrom((string) $article->search_intent) === null) {
            $article->search_intent = null;
        }

        if (array_key_exists('featured_asset_uid', $attributes)) {
            $uid = $attributes['featured_asset_uid'];
            $article->featured_asset_id = $uid === null || $uid === '' ? null : $this->assetId($website, (string) $uid);
        }

        if (array_key_exists('business_location_id', $attributes)) {
            $locationId = $attributes['business_location_id'];
            $article->business_location_id = $locationId === null || $locationId === ''
                ? null
                : $this->locationId($business, (int) $locationId);
        }

        if ($article->supports_page_uid !== null && $article->supports_page_uid !== '') {
            if ($this->inventory->find($business, (string) $article->supports_page_uid) === null) {
                throw new ArticleException(ArticleException::BAD_REFERENCE, 'Choose one of your published pages to support.');
            }
        } else {
            $article->supports_page_uid = null;
        }

        $article->topic_signature = ArticleTopicSignature::key((string) ($article->primary_topic ?: $article->title));
        $article->referenced_catalog_uids = $this->referencedCatalogUids($business, (string) $article->body . ' ' . (string) $article->title);
    }

    private function assetId(Website $website, string $uid): int
    {
        $asset = WebsiteAsset::query()->where('website_id', $website->id)->where('uid', $uid)->first();

        if ($asset === null) {
            throw new ArticleException(ArticleException::BAD_REFERENCE, 'That image is not in your website\'s image library.');
        }

        return (int) $asset->id;
    }

    private function locationId(Business $business, int $locationId): int
    {
        if (! BusinessLocation::query()->where('business_id', $business->id)->whereKey($locationId)->exists()) {
            throw new ArticleException(ArticleException::BAD_REFERENCE, 'That location does not belong to this business.');
        }

        return $locationId;
    }

    /**
     * The catalog items the text actually names — so a later change to one of them can be flagged.
     *
     * @return array<int, string>|null
     */
    private function referencedCatalogUids(Business $business, string $text): ?array
    {
        $haystack = mb_strtolower($text);
        $uids = [];

        foreach ($this->catalog->active($business) as $item) {
            $name = mb_strtolower(trim($item['name']));

            if ($name !== '' && str_contains($haystack, $name)) {
                $uids[] = $item['uid'];
            }
        }

        return $uids === [] ? null : $uids;
    }
}
