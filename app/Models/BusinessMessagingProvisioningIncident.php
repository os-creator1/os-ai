<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PR #295 Correction Round 1, item 3 — see the creating migration's
 * docblock. Admin-visible reconciliation audit trail only; never read by
 * a customer-facing request path.
 *
 * Phone Numbers + A2P lane — resolved_by_user_id/resolution_note added so
 * resolution is auditable (who reconciled it, and what was verified). All
 * three resolution columns (resolved_at, resolved_by_user_id,
 * resolution_note) are deliberately absent from $fillable: the ONLY path
 * that may ever write them is ProvisioningIncidentRecorder::resolve()'s
 * idempotent conditional UPDATE, run through the query builder, not through
 * this model's own mass assignment. Making them fillable here would let a
 * second, uncontrolled write path bypass resolve()'s "already resolved"
 * check and its auditability guarantee.
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
    ];

    protected $casts = [
        'business_id' => 'integer',
        'resolved_at' => 'datetime',
        'resolved_by_user_id' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
