<?php

namespace App\Models;

use App\Enums\AgencyBilling\AgencyStripeConnectionStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Lane C §C3.1 — the Stripe account that receives ONE Agency's SaaS revenue.
 *
 * Deliberately NOT `BusinessStripeConnection`. That row is lane B's — a
 * Business's own connected account for its own customer revenue — and making it
 * the implicit authority for lane-C subscriptions is the conflation §C1
 * forbids. The same physical Stripe account may serve both; the records do not.
 *
 * NOTHING SECRET IS STORED OR EXPOSED. `stripe_account_id` is a provider
 * identifier, not a credential, and there is no attribute here that could hold
 * a secret key, a webhook secret or an OAuth token.
 *
 * Readiness columns are provider truth, written only by the manager after
 * re-reading the account, never by mass assignment.
 */
class AgencyStripeConnection extends Model
{
    use HasUid;

    protected $fillable = [
        'agency_workspace_id',
        'stripe_account_id',
    ];

    protected $casts = [
        'status' => AgencyStripeConnectionStatus::class,
        'charges_enabled' => 'boolean',
        'payouts_enabled' => 'boolean',
        'details_submitted' => 'boolean',
        'lock_version' => 'integer',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function agencyWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'agency_workspace_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(AgencySaasPlan::class, 'agency_stripe_connection_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(AgencyClientSubscription::class, 'agency_stripe_connection_id');
    }

    /**
     * §C5.1 — may a NEW lane-C charge be taken through this connection right
     * now? Both the local status and the provider's own `charges_enabled` must
     * agree; a stale local flag is never enough on its own.
     */
    public function canCharge(): bool
    {
        return $this->status->canCharge() && (bool) $this->charges_enabled;
    }

    /** Safe for an operator screen: never the full account id, never a secret. */
    public function maskedAccountId(): ?string
    {
        $id = (string) $this->stripe_account_id;

        if ($id === '') {
            return null;
        }

        return mb_strlen($id) <= 8
            ? str_repeat('•', mb_strlen($id))
            : mb_substr($id, 0, 5) . str_repeat('•', 6) . mb_substr($id, -4);
    }
}
