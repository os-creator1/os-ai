<?php

namespace App\Models;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankObservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEO Keyword Rank Tracking V1 — one normalized provider fact. `position` is
 * NULL unless status is Found. Written only by SeoRankObservationRecorder.
 *
 * @property int $id
 * @property int $business_id
 * @property int $seo_rank_target_id
 * @property SeoRankCheckType $check_type
 * @property SeoRankObservationStatus $status
 * @property int|null $position
 * @property string|null $result_url
 * @property string|null $result_path
 * @property int $depth_checked
 */
class SeoRankObservation extends Model
{
    protected $table = 'seo_rank_observations';

    protected $guarded = ['*'];

    protected $casts = [
        'check_type' => SeoRankCheckType::class,
        'status' => SeoRankObservationStatus::class,
        'checked_at' => 'datetime',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(SeoRankTarget::class, 'seo_rank_target_id');
    }

    public function isFound(): bool
    {
        return $this->status === SeoRankObservationStatus::Found && $this->position !== null;
    }
}
