<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessFundingAttempt;

interface BusinessFundingAttemptRepository extends BaseRepository
{
    public function findById(int $id): ?BusinessFundingAttempt;

    public function findForUpdateById(int $id): ?BusinessFundingAttempt;

    public function findByLocalIdempotencyKey(string $key): ?BusinessFundingAttempt;

    public function findByProviderReference(string $reference): ?BusinessFundingAttempt;

    /**
     * The most recent outstanding (not yet terminal) attempt for a Business
     * and purpose, used by auto-recharge's own outstanding-attempt
     * idempotency check (M3 contract §15).
     */
    public function findOutstandingForBusiness(int $businessId, string $purpose): ?BusinessFundingAttempt;

    public function create(array $attributes): BusinessFundingAttempt;

    public function update(BusinessFundingAttempt $attempt, array $attributes): BusinessFundingAttempt;
}
