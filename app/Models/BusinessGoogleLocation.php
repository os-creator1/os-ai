<?php

namespace App\Models;

use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Library\Traits\HasUid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * GBP Slice A contract §11.2 — the BusinessLocation-owned binding to
 * exactly one Google location, plus its bounded read-only mirror.
 *
 * BOTH provider resource names are persisted (contract §8.4, correction
 * A-2). There is deliberately NO street-address attribute (contract
 * §23.4 point 4) — the private-address invariant is structural here.
 *
 * mirrorIsFresh() is computed AT READ TIME and is never persisted
 * (contract §13.3, §5 of the implementation brief: "Never persist a
 * derived is_stale, expired, or equivalent comparison result"). Only the
 * two authorized timestamps are stored.
 */
class BusinessGoogleLocation extends Model
{
    use HasUid;

    protected $fillable = [
        'uid',
        'business_google_connection_id',
        'business_id',
        'business_location_id',
        'provider_account_resource_name',
        'provider_location_resource_name',
        'bound_title_snapshot',
        'bound_locality_snapshot',
        'bound_region_code_snapshot',
        'verification_state',
        'has_voice_of_merchant',
        'has_pending_edits',
        'open_status',
        'duplicate_of_resource_name',
        'profile_mirror',
        'mirror_fetched_at',
        'mirror_expires_at',
        'last_synced_at',
        'bound_by_user_id',
    ];

    protected $casts = [
        'verification_state' => GoogleLocationHealth::class,
        'has_voice_of_merchant' => 'boolean',
        'has_pending_edits' => 'boolean',
        'profile_mirror' => 'array',
        'mirror_fetched_at' => 'datetime',
        'mirror_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    /**
     * See BusinessGoogleConnection::generateUid() — uniqid() is forbidden
     * for GBP identifiers (contract §9.3).
     */
    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BusinessGoogleConnection::class, 'business_google_connection_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'business_location_id');
    }

    /**
     * Contract §13.3 — read-time freshness. A mirror is usable only when
     * it was fetched AND has not passed its stored expiry. There is no
     * persisted `is_stale`/`expired` column anywhere, by design: an
     * expired mirror must never continue rendering as current, and a
     * stored boolean would go stale the moment the clock moved.
     *
     * A row whose mirror_expires_at is null (never fetched, or already
     * purged) is NOT fresh — fail closed.
     */
    public function mirrorIsFresh(?CarbonInterface $now = null): bool
    {
        if ($this->mirror_fetched_at === null || $this->mirror_expires_at === null) {
            return false;
        }

        if ($this->profile_mirror === null || $this->profile_mirror === []) {
            return false;
        }

        return $this->mirror_expires_at->greaterThan($now ?? now());
    }

    /**
     * The bounded mirror payload, or an empty array when the mirror is
     * absent or expired. Every reader goes through this accessor so an
     * expired mirror can never leak into a view or a comparison.
     */
    public function freshMirror(?CarbonInterface $now = null): array
    {
        return $this->mirrorIsFresh($now) ? (array) $this->profile_mirror : [];
    }
}
