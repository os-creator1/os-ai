<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The reviewable "existing website -> MotionGrove-hosted website" plan (one per Business). A plan is data about the
 * owner's current site: building it, confirming it or starting the rebuild never changes that site, the Business's
 * address, a domain or a published revision.
 */
class ExternalRebuildPlan extends Model
{
    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    public const STARTED = 'started';

    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'pages' => 'array',
        'facts' => 'array',
        'decisions' => 'array',
        'confirmed_at' => 'datetime',
        'started_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $plan) {
            $plan->uid ??= (string) Str::uuid();
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isConfirmed(): bool
    {
        return in_array($this->status, [self::CONFIRMED, self::STARTED], true);
    }
}
