<?php

namespace App\Models;

use App\Enums\Seo\ArticleStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * SEO Content Engine V1 — a blog article. See the 2026_11_05_100001 migration for why this is its
 * own record and not a Website page.
 *
 * `business_id`, `website_id`, `status` and the three lifecycle timestamps are deliberately NOT
 * fillable: ArticleManager is the only writer, from an already-authorized Business, so no request
 * payload can move an article to another Business or straight to Published.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int $website_id
 * @property int|null $business_location_id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string|null $body
 * @property ArticleStatus $status
 * @property int|null $featured_asset_id
 * @property string|null $seo_title
 * @property string|null $meta_description
 * @property bool $noindex
 * @property string|null $author_name
 * @property string|null $primary_topic
 * @property string|null $topic_signature
 * @property string|null $search_intent
 * @property string|null $supports_page_uid
 * @property string|null $opportunity_key
 * @property string $source
 * @property bool $ai_generated
 * @property array|null $referenced_catalog_uids
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property \Illuminate\Support\Carbon|null $content_updated_at
 */
class WebsiteArticle extends Model
{
    use HasUid;

    protected $table = 'website_articles';

    protected $fillable = [
        'business_location_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'featured_asset_id',
        'seo_title',
        'meta_description',
        'noindex',
        'author_name',
        'primary_topic',
        'topic_signature',
        'search_intent',
        'supports_page_uid',
        'opportunity_key',
        'source',
        'ai_generated',
        'referenced_catalog_uids',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'status' => ArticleStatus::class,
        'noindex' => 'boolean',
        'ai_generated' => 'boolean',
        'referenced_catalog_uids' => 'array',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'archived_at' => 'datetime',
        'content_updated_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
    ];

    /** website_articles.uid is a database UUID column (HasUid's default is uniqid()). */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function featuredAsset(): BelongsTo
    {
        return $this->belongsTo(WebsiteAsset::class, 'featured_asset_id');
    }

    public function slugHistory(): HasMany
    {
        return $this->hasMany(WebsiteArticleSlugHistory::class);
    }

    /** The only articles a visitor may ever see. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Published->value)
            ->whereNotNull('published_at');
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::Published;
    }

    /** True once the article has ever been live: its URL may be indexed, so changes must keep it reachable. */
    public function hasBeenPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function dateModified(): \Illuminate\Support\Carbon
    {
        return $this->content_updated_at ?? $this->published_at ?? $this->updated_at;
    }
}
