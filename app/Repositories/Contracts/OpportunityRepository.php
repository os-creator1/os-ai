<?php

namespace App\Repositories\Contracts;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\Business;
use App\Models\Opportunity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface OpportunityRepository extends BaseRepository
{
    public function findByFingerprintForUpdate(string $fingerprint): ?Opportunity;

    public function findOwnedForUpdate(int $id, int $businessId): ?Opportunity;

    /**
     * Unlocked equivalent of findOwnedForUpdate() for a safe read-only
     * fetch (e.g. a customer "show" GET) — same id/business_id ownership
     * predicate, no lockForUpdate(), no transaction.
     */
    public function findOwned(int $id, int $businessId): ?Opportunity;

    /**
     * The same-business/same-worker staleness-sweep candidate set (RFC-002
     * §22): every Opportunity still freshness=current whose
     * last_confirmed_run_id is NULL or differs from the given run id.
     *
     * @return Collection<int, Opportunity>
     */
    public function currentMissingFromRunForUpdate(int $businessId, OpportunityWorkerKey $workerKey, int $excludeRunId): Collection;

    /**
     * @return Collection<int, Opportunity>
     */
    public function expiredSnoozesBatch(int $limit): Collection;

    public function paginateForCustomer(Business $business, array $filters): LengthAwarePaginator;

    /**
     * The bounded top-N actionable Opportunities for a customer's dashboard
     * panel (RFC-002 §43) — always freshness=current and status in
     * (open, awaiting_approval, in_progress), ordered identically to
     * paginateForCustomer()'s own §35 ordering. An internal bounded read:
     * the only production caller supplies a fixed private constant, so
     * $limit is never validated here.
     *
     * @return Collection<int, Opportunity>
     */
    public function topForCustomer(Business $business, int $limit): Collection;

    public function paginateForAdmin(array $filters): LengthAwarePaginator;

    public function create(array $attributes): Opportunity;

    public function update(Opportunity $opportunity, array $attributes): Opportunity;
}
