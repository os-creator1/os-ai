<?php

namespace App\Models;

use App\Enums\GoogleAds\LeadAttributionEntrySurface;
use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Enums\GoogleAds\LeadAttributionTouchRole;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Google Ads Module V1 contract §10 — one APPEND-ONLY attribution touch for a
 * conversion event (first / last). Inserted only by LeadAttributionRecorder;
 * any Eloquent update or delete is refused here so first-touch history can
 * never be rewritten (a contact's removal cascades at the database level).
 *
 * `business_id` is the server-resolved Business of the public page, never a
 * cookie value. `landing_page` is a path only. No IP address / user agent.
 */
class LeadAttributionTouch extends Model
{
    use HasUid;

    public $timestamps = false;

    /** Never mass-assigned from a request; the recorder sets every column explicitly. */
    protected $guarded = ['id', 'uid'];

    protected $casts = [
        'subject_type' => LeadAttributionSubjectType::class,
        'entry_surface' => LeadAttributionEntrySurface::class,
        'touch_role' => LeadAttributionTouchRole::class,
        'captured_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A lead attribution touch is append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('A lead attribution touch is never deleted.');
        });
    }

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

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contact_id');
    }
}
