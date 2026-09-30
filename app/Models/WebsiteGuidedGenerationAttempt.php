<?php

namespace App\Models;

use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Guided Generation contract §8.2/§8.4, completed by this lane.
 * One immutable-in-spirit row per generation attempt — see the
 * migration's own docblock. Written and transitioned exclusively by
 * App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
 * no controller ever writes this table directly (mirrors the seam
 * discipline already enforced for WebsiteDraftPageService).
 */
class WebsiteGuidedGenerationAttempt extends Model
{
    use HasUid;

    public const MODE_FULL_GENERATION = 'full_generation';

    public const MODE_REBUILD = 'rebuild';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uid',
        'website_id',
        'template_key',
        'mode',
        'idempotency_key',
        'status',
        'warnings',
        'failure_reason',
        'retry_count',
        'created_by_user_id',
        'completed_at',
    ];

    protected $casts = [
        'warnings' => 'array',
        'retry_count' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
