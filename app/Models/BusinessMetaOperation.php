<?php

namespace App\Models;

use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §3 — the Meta operation and audit ledger.
 *
 * business_id is denormalised and has no foreign key so a disconnect cannot
 * erase its own audit record. summary / failure_classification are bounded
 * own-vocabulary strings written only by the (later) Meta operation ledger
 * service; no raw provider text or token may ever be stored.
 */
class BusinessMetaOperation extends Model
{
    use HasUid;

    protected $fillable = [
        'business_id',
        'operation_type',
        'local_operation_key',
        'request_fingerprint',
        'provider_call_count',
        'provider_operation_reference',
        'status',
        'actor_user_id',
        'summary',
        'failure_classification',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'operation_type' => MetaOperationType::class,
        'status' => MetaOperationStatus::class,
        'provider_call_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function generateUid()
    {
        $this->uid = (string) Str::uuid();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeOfType($query, MetaOperationType $type)
    {
        return $query->where('operation_type', $type->value);
    }

    public function scopeWithStatus($query, MetaOperationStatus $status)
    {
        return $query->where('status', $status->value);
    }
}
