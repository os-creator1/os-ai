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
}
