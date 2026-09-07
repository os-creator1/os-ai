<?php

namespace App\Models;

use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * GBP Slice A contract §11.3 / §27 — the safe operation and audit ledger.
 * This model IS the GBP audit trail.
 *
 * business_id is denormalized and has no foreign key, so a disconnect that
 * deletes the connection cannot erase the audit record of that disconnect
 * (contract §11.3.1).
 *
 * `summary` and `failure_classification` are bounded, own-vocabulary
 * strings written exclusively by
 * App\Library\GoogleBusinessProfile\GoogleBusinessProfileOperationLedger.
 * Nothing else in the codebase may write this table.
 */
class BusinessGoogleOperation extends Model
{
    use HasUid;

    /**
     * Contract §11.1.2 / §11.3 — the closed failure vocabulary. No raw
     * provider error string, HTTP body or exception message may ever be
     * stored; a provider failure is normalized to one of these before it
     * reaches the database (contract §25.11, security criterion G-10).
     */
    public const FAILURE_INVALID_GRANT = 'invalid_grant';

    public const FAILURE_ACCESS_DENIED = 'access_denied';

    public const FAILURE_RATE_LIMITED = 'rate_limited';

    public const FAILURE_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const FAILURE_TIMEOUT = 'timeout';

    public const FAILURE_UNEXPECTED_RESPONSE = 'unexpected_response';

    /**
     * Correction pass item 6 — the per-Business hourly provider-call
     * budget refused this request. Deliberately DISTINCT from
     * rate_limited: Google did not throttle us, we throttled ourselves,
     * and zero outbound requests were made. Deferrable, so the ledger
     * records "deferred" rather than "failed".
     */
    public const FAILURE_BUDGET_EXHAUSTED = 'budget_exhausted';

    /**
     * @var array<int, string>
     */
    public const FAILURE_CLASSIFICATIONS = [
        self::FAILURE_INVALID_GRANT,
        self::FAILURE_ACCESS_DENIED,
        self::FAILURE_RATE_LIMITED,
        self::FAILURE_PROVIDER_UNAVAILABLE,
        self::FAILURE_TIMEOUT,
        self::FAILURE_UNEXPECTED_RESPONSE,
        self::FAILURE_BUDGET_EXHAUSTED,
    ];

    protected $fillable = [
        'uid',
        'business_id',
        'business_google_location_id',
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
        'operation_type' => GoogleOperationType::class,
        'status' => GoogleOperationStatus::class,
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

    public function googleLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessGoogleLocation::class, 'business_google_location_id');
    }
}
