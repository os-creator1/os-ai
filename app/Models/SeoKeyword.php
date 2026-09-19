<?php

namespace App\Models;

use App\Enums\Seo\SeoKeywordLifecycleState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Contract 18 §8.4 — an SEO keyword: a search phrase a Business wants to be
 * found for, Business-wide (no Location) or with a Location as local-intent
 * attribution.
 *
 * NOT the legacy `Keywords` model (inbound-SMS text-in keywords). Different
 * table, different domain; nothing is shared.
 *
 * `lifecycle_state` and `archived_at` are deliberately NOT fillable, mirroring
 * BusinessLocation: SeoKeywordManager::archive()/reactivate() are the only
 * writers. `business_id` is not fillable either — a keyword's Business is set
 * once, by the manager, from the already-authorized Business. `location_key`
 * is a database-generated column and is never written.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int|null $business_location_id
 * @property string $phrase
 * @property string $phrase_normalized
 * @property SeoKeywordLifecycleState $lifecycle_state
 */
class SeoKeyword extends Model
{
    use HasUid;

    protected $table = 'seo_keywords';

    protected $fillable = [
        'uid',
        'business_location_id',
        'phrase',
        'phrase_normalized',
        'source',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'lifecycle_state' => SeoKeywordLifecycleState::class,
        'archived_at' => 'datetime',
    ];

    /** seo_keywords.uid is a database UUID column (HasUid's default is uniqid()). */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    public function isActive(): bool
    {
        return ($this->lifecycle_state ?? SeoKeywordLifecycleState::Active) === SeoKeywordLifecycleState::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('lifecycle_state', SeoKeywordLifecycleState::Active->value);
    }
}
