<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessFundingAttempt;
use Illuminate\Support\Collection;

interface BusinessFundingAttemptRepository extends BaseRepository
{
    public function findById(int $id): ?BusinessFundingAttempt;

    public function findForUpdateById(int $id): ?BusinessFundingAttempt;

    public function findByLocalIdempotencyKey(string $key): ?BusinessFundingAttempt;

    public function findByProviderReference(string $reference): ?BusinessFundingAttempt;

    /**
     * RFC-005 Remediation #6 §3 — resolves by the independently-unique
     * provider_payment_intent_reference column.
     */
    public function findByProviderPaymentIntentReference(string $reference): ?BusinessFundingAttempt;

    /**
     * RFC-005 Remediation #6 §3 — resolves by the independently-unique
     * provider_charge_reference column.
     */
    public function findByProviderChargeReference(string $reference): ?BusinessFundingAttempt;

    /**
     * The most recent outstanding (not yet terminal) attempt for a Business
     * and purpose, used by auto-recharge's own outstanding-attempt
     * idempotency check (M3 contract §15).
     */
    public function findOutstandingForBusiness(int $businessId, string $purpose): ?BusinessFundingAttempt;

    public function create(array $attributes): BusinessFundingAttempt;

    public function update(BusinessFundingAttempt $attempt, array $attributes): BusinessFundingAttempt;

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.4 — a plain,
     * non-locking, bounded read for the admin dashboard's own "recent
     * funding attempts" panel. Deliberately distinct from, and never
     * delegates to, the locking findOutstandingForBusiness() (which
     * exists only as initiateCharge()'s own duplicate-attempt guard and
     * must never be used for a read-only display).
     */
    public function recentForBusiness(int $businessId, int $limit = 20): Collection;

    /**
     * Customer Experience Slice 5, Correction Round 1 §5.1/§5.3 — the
     * automatic top-up capacity already durably claimed but not yet
     * settled: the sum of expected_amount_micro over every AutoRecharge
     * attempt of the given Businesses that is still in an outstanding
     * (non-terminal) state and may therefore still become a charge.
     * Optionally narrowed to one frozen payer_type_snapshot (the Workspace
     * aggregate counts only attempts the Workspace pays for). Terminal
     * attempts (succeeded, failed, canceled, refunded, disputed) never
     * count here — a settled success is already in the wallet's own
     * recharged_this_period_micro, and a failure released its claim.
     *
     * @param list<int> $businessIds
     */
    public function outstandingAutoRechargeAmountMicroForBusinesses(array $businessIds, ?string $payerTypeSnapshot = null): int;

    /**
     * Correction Round 2 §1.1/§1.3 — how many automatically initiated
     * top-ups of one Business count against the rolling window: EVERY
     * AutoRecharge funding attempt whose row was created strictly after
     * $since, whatever its state is now.
     *
     * A frequency slot is consumed the moment the durable AutoRecharge row
     * is created (after every local policy check) and stays consumed for
     * the full rolling window — pending, succeeded, failed, canceled,
     * refunded and disputed alike. It is deliberately NOT filtered by the
     * mutable terminal state: a declined attempt still contacted the
     * payment provider, so letting it release its slot would allow more
     * than the approved number of provider contacts per window. Monetary
     * headroom is the separate calculation
     * (outstandingAutoRechargeAmountMicroForBusinesses()), and a failed or
     * canceled attempt does release that.
     *
     * One row is one distinct logical attempt (local_idempotency_key is
     * unique per row), so a webhook replay, an idempotent administrator
     * retry of the same provider operation and any other confirmation of
     * an existing row never add a slot; only a genuinely new AutoRecharge
     * row does, and that row can only be created through the locked
     * admission in UsageBillingCheckoutManager::initiateCharge().
     */
    public function countAutoRechargeAttemptsCreatedAfter(int $businessId, \DateTimeInterface $since): int;
}
