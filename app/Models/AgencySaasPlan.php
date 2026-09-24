<?php

namespace App\Models;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Lane C §C3.2 — ONE resale plan belonging to ONE Agency.
 *
 * This is the AGENCY's product, not ours. The Agency chooses the customer-facing
 * name, the description, the price, the currency, the billing cycle and whether
 * there is a trial. What it may not choose is a capability set the product does
 * not know how to entitle, so `tier` maps the plan onto a canonical
 * WorkspacePlanTier — and never onto Agency, because reselling the Agency tier
 * would let a client manage its own clients on somebody else's lane-A
 * subscription.
 *
 * `provider_price_id` is an immutable Stripe Price ON THE AGENCY'S CONNECTED
 * ACCOUNT. A Price belonging to the platform, or to a different Agency, is not
 * even retrievable with this Agency's `Stripe-Account` header — which is what
 * makes cross-account substitution structurally impossible rather than merely
 * checked for.
 */
class AgencySaasPlan extends Model
{
    use HasUid;

    /** §C3.2 — an Agency may resell capability, never the Agency tier itself. */
    public const RESELLABLE_TIERS = ['core', 'growth'];

    protected $fillable = [
        'agency_workspace_id',
        'name',
        'description',
        'tier',
        'price',
        'currency_id',
        'currency_code',
        'billing_cycle',
        'trial_enabled',
        'trial_days',
    ];

    protected $casts = [
        'tier' => WorkspacePlanTier::class,
        'trial_enabled' => 'boolean',
        'trial_days' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function agencyWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'agency_workspace_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AgencyStripeConnection::class, 'agency_stripe_connection_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(AgencyClientSubscription::class, 'agency_saas_plan_id');
    }

    public function pricingChanges(): HasMany
    {
        return $this->hasMany(AgencySaasPlanPricingChange::class, 'agency_saas_plan_id');
    }

    /**
     * §C5.2 — is this plan actually sellable to a client right now? Published,
     * priced, and bound to a verified provider Price. Whether the AGENCY's
     * connection can still charge is a separate, live question the manager asks
     * of the provider, never a cached answer read from here.
     */
    public function isSellable(): bool
    {
        return (bool) $this->is_published
            && $this->price !== null
            && $this->currency_id !== null
            && ! blank($this->provider_price_id);
    }

    /**
     * §C3.2 — the trial the Agency configured, or null. `trial_enabled` false
     * means no trial regardless of a leftover day count, so turning a trial off
     * cannot be undone by a stale number.
     */
    public function configuredTrialDays(): ?int
    {
        if (! (bool) $this->trial_enabled) {
            return null;
        }

        $days = (int) $this->trial_days;

        return $days > 0 ? $days : null;
    }
}
