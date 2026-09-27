<?php

namespace App\Models;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Website Generation + Hosting Slice B (custom domains, hosting
 * contract §40). One row per hostname a Website answers to — a Website
 * may have zero domains (still served at /sites/{public_id}), one
 * primary domain, or a primary plus one or more aliases that
 * permanently redirect to it. See WebsiteDomainStatus for the lifecycle
 * and WebsiteDomainService for the sole writer of this table.
 */
class WebsiteDomain extends Model
{
    use HasUid;

    protected $fillable = [
        'uid',
        'website_id',
        'domain',
        'is_primary',
        'status',
        'verification_token',
        'failure_reason',
        'certificate_reference',
        'verified_at',
        'activated_at',
        'last_checked_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'status' => WebsiteDomainStatus::class,
        'verified_at' => 'datetime',
        'activated_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isActive(): bool
    {
        return $this->status === WebsiteDomainStatus::Active;
    }

    /**
     * The exact hostname the owner must create a TXT record on to prove
     * they control DNS for this domain, before any traffic-serving or
     * certificate work ever happens.
     */
    public function verificationTxtHost(): string
    {
        return '_platform-verify.'.$this->domain;
    }
}
