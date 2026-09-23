<?php

namespace App\Models;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Implementation Contract 21 §6 — the canonical local lane-A subscription.
 *
 * Lane A only (§1/§2): the Workspace owner's Core/Growth/Agency subscription
 * to the PLATFORM. Nothing here relates to a Business's own connected Stripe
 * account (lane B), an Agency's (lane C), or a usage wallet (lane D).
 *
 * THE ENTITLEMENT AUTHORITY IS STILL `workspace_plan_assignments` (§4). This
 * row records the COMMERCIAL relationship — who is paying, on what terms, and
 * what the provider has confirmed — and supplies the provider-confirmed
 * reasons that make EntitlementManager's existing lifecycle writers fire. It
 * is never consulted to answer "may this Workspace use feature X".
 *
 * Only identity/terms are mass-assignable. Provider identities, `status`,
 * period, trial, cancellation and event bookkeeping are provider truth owned
 * by PlatformSubscriptionFinalizer and are written under its locks, never by
 * mass assignment.
 *
 * There is deliberately NO card attribute of any kind (§6).
 */
class PlatformSubscription extends Model
{
    use HasUid;

    protected $fillable = [
        'workspace_id',
        'workspace_plan_catalog_id',
        'local_idempotency_key',
        'price_snapshot',
        'currency_id',
        'currency_code',
        'billing_cycle_snapshot',
        'trial_days_snapshot',
    ];

    protected $casts = [
        'status' => PlatformSubscriptionStatus::class,
        'cancel_at_period_end' => 'boolean',
        'trial_days_snapshot' => 'integer',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
        'pending_effective_at' => 'datetime',
        'last_event_at' => 'datetime',
        'checkout_attempt_started_at' => 'datetime',
    ];

    public function generateUid(): void
    {
        $this->uid = (string) Str::uuid();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlanCatalog::class, 'workspace_plan_catalog_id');
    }

    /** §10.2 — the tier a scheduled downgrade will move to at period end. */
    public function pendingCatalog(): BelongsTo
    {
        return $this->belongsTo(WorkspacePlanCatalog::class, 'pending_plan_catalog_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PlatformSubscriptionEvent::class, 'platform_subscription_id');
    }

    /**
     * §5 — the provider idempotency key, derived from this row's own durable
     * UID, so repeating an uncertain call cannot originate a second
     * subscription.
     */
    public static function idempotencyKeyFor(string $uid): string
    {
        return 'platform-subscription:' . $uid;
    }

    /**
     * §7 — the provider idempotency key for ONE checkout attempt.
     *
     * Derived from the subscription's durable key PLUS the attempt's own uid,
     * so it is globally unique, stable for a retry of the same request, and
     * different for a deliberate new attempt. Stripe's idempotency layer
     * rejects the same key carrying different parameters, which is precisely
     * why a plan switch must not reuse the previous attempt's key.
     *
     * Comfortably inside Stripe's 255-character limit, and carries no personal
     * data — both documented requirements for an idempotency key.
     */
    public function checkoutAttemptKey(): ?string
    {
        return $this->checkout_attempt_uid === null
            ? null
            : self::idempotencyKeyFor((string) $this->uid) . ':attempt:' . $this->checkout_attempt_uid;
    }
}
