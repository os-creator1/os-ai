<?php

namespace App\Models;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\CampaignAssignmentOutcome;
use App\Enums\Messaging\PhoneNumberType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Slice 3 §4.2 — one managed phone number, belonging to exactly one
 * identity. The owning Business is resolved by joining through that
 * identity, so ownership has a single source of truth.
 *
 * phone_number is always stored in canonical E.164 (§4.9); there is no
 * second, differently-formatted copy. Carries no credential (T-MSG-4).
 *
 * active_or_pending_phone_number and active_primary_identity_id are MySQL
 * STORED generated columns — never assignable, deliberately absent from
 * $fillable.
 *
 * `number_type` (text messaging setup/compliance hub) decides which
 * carrier registration regime applies to this number — 10DLC for
 * `local`, toll-free verification for `toll_free` — never both.
 *
 * Phone Numbers + A2P lane — the nine lifecycle columns (next_renewal_at,
 * renewal_warning_sent_at, suspended_at, grace_expires_at,
 * release_notice_delivered_at, release_notice_failed_at,
 * release_decided_at, carrier_release_failed_at,
 * carrier_release_failure_reason), plus released_at, are deliberately
 * absent from $fillable: NumberLifecycleManager is the single writer for
 * all of them, exactly the same discipline ProvisioningIncidentRecorder
 * and PortOutRequestManager already apply to their own
 * resolution/cancellation columns.
 *
 * release_decided_at records only an audited platform-operator DECISION
 * to release. `status` becomes Released, and released_at is finally
 * written, only once NumberLifecycleManager::confirmCarrierRelease() gets
 * a genuine (or explicitly-faked-in-a-test) confirmation from
 * MessagingProvisioningAdapter::releaseNumber() — never merely because a
 * decision was recorded.
 *
 * Review correction — the five campaign_assignment_* columns
 * (campaign_assignment_status, campaign_assignment_task_id,
 * campaign_assignment_confirmed_at, campaign_assignment_failed_at,
 * campaign_assignment_failure_reason) are likewise absent from $fillable:
 * BusinessMessagingProvisioningService is their single writer. Null on
 * every toll-free number and on any number that never went through this
 * platform's own verify-first local sequence — deliberately treated as
 * "not applicable", never as a passed check (see
 * isCampaignAssignmentConfirmedOrNotRequired()'s own docblock).
 */
class BusinessMessagingNumber extends Model
{
    protected $table = 'business_messaging_numbers';

    protected $fillable = [
        'business_messaging_identity_id',
        'phone_number',
        'number_type',
        'provider_number_reference',
        'status',
        'is_primary',
        'activated_at',
    ];

    protected $casts = [
        'business_messaging_identity_id' => 'integer',
        'number_type' => PhoneNumberType::class,
        'status' => BusinessMessagingNumberStatus::class,
        'is_primary' => 'boolean',
        'activated_at' => 'datetime',
        'released_at' => 'datetime',
        'next_renewal_at' => 'datetime',
        'renewal_warning_sent_at' => 'datetime',
        'suspended_at' => 'datetime',
        'grace_expires_at' => 'datetime',
        'release_notice_delivered_at' => 'datetime',
        'release_notice_failed_at' => 'datetime',
        'release_decided_at' => 'datetime',
        'carrier_release_failed_at' => 'datetime',
        'campaign_assignment_confirmed_at' => 'datetime',
        'campaign_assignment_failed_at' => 'datetime',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(BusinessMessagingIdentity::class, 'business_messaging_identity_id');
    }

    public function isActive(): bool
    {
        return $this->status === BusinessMessagingNumberStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === BusinessMessagingNumberStatus::Suspended;
    }

    public function isReleased(): bool
    {
        return $this->status === BusinessMessagingNumberStatus::Released;
    }

    public function isInGracePeriod(): bool
    {
        return $this->isSuspended() && $this->grace_expires_at !== null && $this->grace_expires_at->isFuture();
    }

    public function graceHasExpired(): bool
    {
        return $this->isSuspended() && $this->grace_expires_at !== null && ! $this->grace_expires_at->isFuture();
    }

    /**
     * §13.3's "meaningful opportunity to act after notice" — true only once
     * the release notice has been CONFIRMED DELIVERED (never merely
     * dispatched) and the configured minimum notice window has elapsed
     * since that confirmed delivery. Read-only convenience for admin
     * views; NumberLifecycleManager::recordReleaseDecision() re-derives and
     * enforces the identical condition itself and is the only writer of
     * release_decided_at.
     */
    public function releaseNoticeHasMatured(): bool
    {
        return $this->release_notice_delivered_at !== null
            && $this->release_notice_delivered_at->copy()
                ->addDays((int) config('messaging.number_release_minimum_notice_days', 7))
                ->isPast();
    }

    /**
     * Read-only mirror of NumberLifecycleManager::recordReleaseDecision()'s
     * own preconditions (excluding the port-out check, which requires a
     * query this model does not itself perform) — for admin-view gating
     * only, never the source of truth for whether a decision may actually
     * be recorded.
     */
    public function isEligibleForReleaseDecision(): bool
    {
        return $this->isSuspended()
            && $this->graceHasExpired()
            && $this->releaseNoticeHasMatured()
            && $this->release_decided_at === null;
    }

    /**
     * Read-only mirror of NumberLifecycleManager::confirmCarrierRelease()'s
     * own non-port-out preconditions, for admin-view gating only — never
     * the source of truth for whether the carrier call may actually be
     * attempted.
     */
    public function isEligibleForCarrierReleaseConfirmation(): bool
    {
        return $this->isSuspended()
            && $this->release_decided_at !== null
            && filled($this->provider_number_reference);
    }

    /**
     * Review correction — the fail-closed gate both
     * TextMessagingController::situation()'s Ready state and
     * ManagedMessageDispatcher::dispatch()'s own send boundary share, so
     * neither can drift from the other. True for a toll-free number
     * (never subject to this mechanism at all — its own carrier
     * verification is submitted directly against the number, no separate
     * profile-to-campaign link exists) or for a local number whose
     * campaign_assignment_status is null (never put through this
     * platform's own verify-first sequence — a test fixture, or a number
     * predating this mechanism; there is no real customer-facing path
     * today that reaches an Active local number with a null status,
     * since orderNumber() calls
     * BusinessMessagingProvisioningService::assignToApprovedCampaign()
     * for every local purchase). False for a local number whose
     * assignment was Requested or Failed — true only once it is
     * genuinely Confirmed by checkCampaignAssignmentStatus() actually
     * polling the carrier. "Requested" must never be treated as
     * "Confirmed" anywhere this method's callers are the source of truth.
     */
    public function isCampaignAssignmentConfirmedOrNotRequired(): bool
    {
        if ($this->number_type !== PhoneNumberType::Local || $this->campaign_assignment_status === null) {
            return true;
        }

        return $this->campaign_assignment_status === CampaignAssignmentOutcome::Confirmed->value;
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', BusinessMessagingNumberStatus::Suspended->value);
    }
}
