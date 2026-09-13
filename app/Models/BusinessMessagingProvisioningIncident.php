<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PR #295 Correction Round 1, item 3 — see the creating migration's
 * docblock. Admin-visible reconciliation audit trail only; never read by
 * a customer-facing request path.
 */
class BusinessMessagingProvisioningIncident extends Model
{
    protected $table = 'business_messaging_provisioning_incidents';

    protected $fillable = [
        'business_id',
        'stage',
        'messaging_profile_id',
        'provider_phone_number_id',
        'phone_number',
        'number_type',
        'error_message',
        'resolved_at',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
