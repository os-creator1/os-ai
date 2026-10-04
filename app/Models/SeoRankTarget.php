<?php

namespace App\Models;

use App\Enums\Seo\SeoRankTrackingState;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * SEO Keyword Rank Tracking V1 — keyword + provider search geography + device.
 * The unit of paid tracking. Only SeoRankTargetManager writes it; nothing is
 * mass-assignable so a request body can never set business, state or schedule.
 *
 * @property int $id
 * @property string $uid
 * @property int $business_id
 * @property int $seo_keyword_id
 * @property string $provider
 * @property int $seo_rank_location_id
 * @property int $search_location_code
 * @property string $language_code
 * @property string $device
 * @property SeoRankTrackingState $tracking_state
 */
class SeoRankTarget extends Model
{
    use HasUid;

    protected $table = 'seo_rank_targets';

    protected $guarded = ['*'];

    protected $casts = [
        'tracking_state' => SeoRankTrackingState::class,
        'tracked_since' => 'datetime',
        'stopped_at' => 'datetime',
        'next_check_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(SeoKeyword::class, 'seo_keyword_id');
    }

    public function searchLocation(): BelongsTo
    {
        return $this->belongsTo(SeoRankLocation::class, 'seo_rank_location_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SeoRankCheckRun::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(SeoRankObservation::class);
    }

    public function isTracking(): bool
    {
        return $this->tracking_state === SeoRankTrackingState::Tracking;
    }

    public function scopeTracking(Builder $query): Builder
    {
        return $query->where('tracking_state', SeoRankTrackingState::Tracking->value);
    }
}
