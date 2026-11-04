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
    protected static function booted(): void
    {
        static::deleted(function (self $domain) {
            $reservations = app(\App\Library\Domains\DomainReservations::class);
            $reservations->release(\App\Library\Domains\DomainReservations::WEBSITE, (int) $domain->id);
            $reservations->release(\App\Library\Domains\DomainReservations::WEBSITE_WWW, (int) $domain->id);
        });
    }

    use HasUid;

    protected $fillable = [
        'uid',
        'website_id',
        'domain',
        'is_primary',
        'status',
        'verification_token',
        'failure_reason',
        'forge_domain_id',
        'certificate_reference',
        'verified_at',
        'activated_at',
        'last_checked_at',
        'www_forge_domain_id',
        'www_certificate_reference',
        'dns_a_ok',
        'dns_cname_ok',
        'attempts',
        'certificate_attempts',
        'next_attempt_at',
        'processing_until',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'status' => WebsiteDomainStatus::class,
        'verified_at' => 'datetime',
        'activated_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'processing_until' => 'datetime',
        'dns_a_ok' => 'boolean',
        'dns_cname_ok' => 'boolean',
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

    public function wwwHost(): string
    {
        return 'www.'.$this->domain;
    }

    /**
     * This domain's own CNAME target: unique and unpredictable (the token is random), under MotionGrove's CNAME zone.
     * Pointing `www` here is both the routing and the proof of DNS control. Null when the zone is not configured.
     */
    public function cnameTarget(): ?string
    {
        $zone = trim((string) config('services.forge.cname_zone'), '. ');

        return $zone === '' || $this->verification_token === '' ? null : strtolower($this->verification_token).'.'.strtolower($zone);
    }

    /**
     * How many trailing labels make up the registrable "root" domain: two, or three under common second-level
     * suffixes (example.co.uk). A guess without the public-suffix list, used only to refuse subdomains.
     */
    public static function rootLabelCount(string $domain): int
    {
        $labels = explode('.', strtolower($domain));

        return count($labels) >= 3 && strlen(end($labels)) === 2 && in_array($labels[count($labels) - 2], ['co', 'com', 'org', 'net', 'gov', 'ac', 'edu'], true) ? 3 : 2;
    }

    public static function isRootDomain(string $domain): bool
    {
        return count(explode('.', $domain)) === self::rootLabelCount($domain);
    }
}
