<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Contract 18 §8.6 — a Location's manual review link. Written only through
 * SeoReviewLinkManager.
 */
class SeoLocationReviewLink extends Model
{
    use HasUid;

    protected $table = 'seo_location_review_links';

    protected $fillable = [
        'business_id',
        'business_location_id',
        'review_url',
        'set_by_user_id',
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
