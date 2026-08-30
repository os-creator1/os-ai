<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessFundingAttempt;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use Illuminate\Support\Collection;

class EloquentBusinessFundingAttemptRepository extends EloquentBaseRepository implements BusinessFundingAttemptRepository
{
    private const OUTSTANDING_STATES = ['created', 'provider_pending', 'requires_action', 'processing'];

    public function __construct(BusinessFundingAttempt $attempt)
    {
        parent::__construct($attempt);
    }

    public function findById(int $id): ?BusinessFundingAttempt
    {
        return $this->query()->find($id);
    }

    public function findForUpdateById(int $id): ?BusinessFundingAttempt
    {
        return $this->query()->where('id', $id)->lockForUpdate()->first();
    }

    public function findByLocalIdempotencyKey(string $key): ?BusinessFundingAttempt
    {
        return $this->query()->where('local_idempotency_key', $key)->first();
    }

    public function findByProviderReference(string $reference): ?BusinessFundingAttempt
    {
        return $this->query()->where('provider_session_or_intent_reference', $reference)->first();
    }

    public function findByProviderPaymentIntentReference(string $reference): ?BusinessFundingAttempt
    {
        return $this->query()->where('provider_payment_intent_reference', $reference)->first();
    }

    public function findByProviderChargeReference(string $reference): ?BusinessFundingAttempt
    {
        return $this->query()->where('provider_charge_reference', $reference)->first();
    }

    public function findOutstandingForBusiness(int $businessId, string $purpose): ?BusinessFundingAttempt
    {
        // M3 contract §15/§16 — a locking read, not a plain consistent
        // read: this method is the authoritative duplicate-attempt guard
        // UsageBillingCheckoutManager::initiateCharge() calls from inside
        // its own wallet-row-locked transaction (M3 contract §15's
        // "outstanding attempt idempotency"). A plain SELECT's snapshot
        // semantics under REPEATABLE READ must never be relied on here —
        // FOR UPDATE guarantees the latest committed row is always seen,
        // removing any ambiguity for two genuinely concurrent callers.
        return $this->query()
            ->where('business_id', $businessId)
            ->where('purpose', $purpose)
            ->whereIn('state', self::OUTSTANDING_STATES)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    public function create(array $attributes): BusinessFundingAttempt
    {
        /** @var BusinessFundingAttempt $attempt */
        $attempt = $this->make($attributes);
        $attempt->save();

        return $attempt;
    }

    public function update(BusinessFundingAttempt $attempt, array $attributes): BusinessFundingAttempt
    {
        $attempt->fill($attributes);
        $attempt->save();

        return $attempt;
    }

    public function recentForBusiness(int $businessId, int $limit = 20): Collection
    {
        return $this->query()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
