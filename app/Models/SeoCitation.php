<?php

namespace App\Models;

use App\Enums\Seo\SeoCitationStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Contract 18 §8.5 — one user-asserted listing record per (Location,
 * directory). Written only through SeoCitationManager, which owns the
 * Location ACL, the private-address rule and link safety.
 */
class SeoCitation extends Model
{
    use HasUid;

    public const SOURCE_USER_ASSERTED = 'user_asserted';

    protected $table = 'seo_citations';

    protected $fillable = [
        'business_id',
        'business_location_id',
        'seo_citation_directory_id',
        'status',
        'listing_url',
        'listed_name',
        'listed_phone',
        'listed_address',
        'last_verified_at',
        'verification_source',
        'notes',
        'updated_by_user_id',
    ];

    protected $casts = [
        'status' => SeoCitationStatus::class,
        'last_verified_at' => 'date:Y-m-d',
    ];

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

    public function directory(): BelongsTo
    {
        return $this->belongsTo(SeoCitationDirectory::class, 'seo_citation_directory_id');
    }
}
