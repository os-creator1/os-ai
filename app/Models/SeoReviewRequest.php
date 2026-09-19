<?php

namespace App\Models;

use App\Enums\Seo\SeoReviewRequestChannel;
use App\Enums\Seo\SeoReviewRequestStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Contract 18 §8.6 — one line of the review-request ledger. Written only
 * through SeoReviewRequestManager. Holds no message body, no rating and no
 * Google reviewer identity.
 */
class SeoReviewRequest extends Model
{
    use HasUid;

    protected $table = 'seo_review_requests';

    protected $fillable = [
        'business_id',
        'business_location_id',
        'contact_id',
        'crm_opportunity_id',
        'channel',
        'status',
        'requested_at',
        'resolved_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'channel' => SeoReviewRequestChannel::class,
        'status' => SeoReviewRequestStatus::class,
        'requested_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }
}
