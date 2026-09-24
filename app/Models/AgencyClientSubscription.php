<?php

namespace App\Models;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Lane C §C3.4 — what ONE client pays ONE Agency, and what the provider has
 * confirmed about it.
 *
 * THE ENTITLEMENT AUTHORITY IS STILL THE CLIENT'S OWN
 * `workspace_plan_assignments` ROW. This table records the COMMERCIAL
 * relationship and supplies the provider-confirmed reasons that make
 * EntitlementManager's existing lifecycle writers fire. It is never consulted to
 * answer "may this Workspace use feature X", and it never speaks for the
 * Agency's own subscription to the platform (that is lane A).
 *
 * Only identity and offered terms are mass-assignable. Provider identities,
 * `status`, period, trial, cancellation, attempt and operation bookkeeping are
 * provider truth owned by AgencyClientSubscriptionFinalizer.
 *
 * There is deliberately NO card attribute of any kind.
 */
class AgencyClientSubscription extends Model
{
    use HasUid;

    protected $fillable = [
        'agency_workspace_id',
        'client_workspace_id',
        'agency_saas_plan_id',
        'local_idempotency_key',
        'price_snapshot',
        'currency_id',
        'currency_code',
        'billing_cycle_snapshot',
        'trial_days_snapshot',
    ];

    protected $casts = [
        'status' => AgencyClientSubscriptionStatus::class,
        'cancel_at_period_end' => 'boolean',
        'trial_days_snapshot' => 'integer',
        'checkout_attempt_generation' => 'integer',
        'retired_provider_subscription_ids' => 'array',
        'offered_at' => 'datetime',
        'consented_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
        'pending_effective_at' => 'datetime',
        'checkout_attempt_started_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function agencyWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'agency_workspace_id');
    }

    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'client_workspace_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AgencySaasPlan::class, 'agency_saas_plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(AgencySaasPlan::class, 'pending_plan_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AgencyStripeConnection::class, 'agency_stripe_connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgencyClientSubscriptionEvent::class, 'agency_client_subscription_id');
    }

    /**
     * §C7 — the provider idempotency key derived from this row's own durable
     * UID, so repeating an uncertain call cannot originate a second
     * subscription on the Agency's account.
     *
     * The `agency-subscription:` prefix is deliberate: a lane-C key and a
     * lane-A key can never collide even if both lanes ever addressed the same
     * Stripe account.
     */
    public static function idempotencyKeyFor(string $uid): string
    {
        return 'agency-subscription:' . $uid;
    }

    /** §C7 — the key for ONE checkout attempt. */
    public function checkoutAttemptKey(): ?string
    {
        return $this->checkout_attempt_uid === null
            ? null
            : self::idempotencyKeyFor((string) $this->uid) . ':attempt:' . $this->checkout_attempt_uid;
    }

    /**
     * §C7 — the key for ONE plan-change operation.
     *
     * Keyed on the OPERATION, never on the target plan id: an Agency that
     * reprices a plan gives it a different immutable Stripe Price, and reusing
     * one key with different parameters is an error at the provider.
     */
    public function planChangeKey(): ?string
    {
        return $this->pending_operation_uid === null
            ? null
            : self::idempotencyKeyFor((string) $this->uid) . ':change:' . $this->pending_operation_uid;
    }

    /**
     * §C7 — provider subscriptions this row has finished with. A late event
     * from one of them must never take ownership of the current relationship.
     *
     * @return array<int, string>
     */
    public function retiredProviderSubscriptionIds(): array
    {
        return array_values(array_filter((array) ($this->retired_provider_subscription_ids ?? [])));
    }

    /** §C6 — an offer is a proposal: nothing is owed and no card exists. */
    public function isOffer(): bool
    {
        return $this->status === AgencyClientSubscriptionStatus::Offered;
    }
}
