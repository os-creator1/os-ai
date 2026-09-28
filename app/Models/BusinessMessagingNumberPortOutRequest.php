<?php

namespace App\Models;

use App\Enums\Messaging\PortOutRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phone Numbers + A2P lane — messaging contract §13.4. Tracks a customer's
 * request to port a managed number out; never itself performs the port,
 * release, replace, transfer or purchase of a number.
 *
 * cancelled_at/cancelled_by_user_id are deliberately absent from
 * $fillable, mirroring BusinessMessagingProvisioningIncident's own
 * resolution columns: PortOutRequestManager::cancel()'s idempotent
 * conditional UPDATE is the only path that may ever write them.
 */
class BusinessMessagingNumberPortOutRequest extends Model
{
    protected $table = 'business_messaging_number_port_out_requests';

    protected $fillable = [
        'business_id',
        'business_messaging_number_id',
        'phone_number',
        'status',
        'requested_by_user_id',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'business_messaging_number_id' => 'integer',
        'status' => PortOutRequestStatus::class,
        'requested_by_user_id' => 'integer',
        'cancelled_at' => 'datetime',
        'cancelled_by_user_id' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(BusinessMessagingNumber::class, 'business_messaging_number_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === PortOutRequestStatus::Requested;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PortOutRequestStatus::Requested->value);
    }
}
