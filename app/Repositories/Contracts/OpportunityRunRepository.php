<?php

namespace App\Repositories\Contracts;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\OpportunityRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OpportunityRunRepository extends BaseRepository
{
    public function findForUpdate(int $id): ?OpportunityRun;

    public function findRunningForUpdate(int $businessId, OpportunityWorkerKey $workerKey): ?OpportunityRun;

    /**
     * Admin-only, intentionally cross-tenant runs listing for one Business
     * (RFC-002 §44 admin diagnostic inspection — Milestone 5). No status
     * restriction — failed and abandoned runs are returned identically to
     * succeeded/running ones, since diagnostic inspection is exactly why
     * this exists. Deterministic newest-first ordering (started_at desc,
     * id desc as a tie-breaker). Never mutated by the caller.
     */
    public function paginateForBusiness(int $businessId, int $perPage): LengthAwarePaginator;

    /**
     * Admin-only, intentionally cross-tenant single-run read (RFC-002 §44
     * admin detail — Milestone 5) — no business_id ownership restriction,
     * no status restriction (a failed/abandoned run is retrievable exactly
     * like any other), and no lock. Eager-loads business.customer.user so
     * the admin detail page can render owning-business/customer identity.
     * Never mutated by the caller.
     */
    public function findForAdmin(int $runId): ?OpportunityRun;

    public function create(array $attributes): OpportunityRun;

    public function update(OpportunityRun $run, array $attributes): OpportunityRun;
}
