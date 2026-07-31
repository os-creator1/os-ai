<?php

namespace App\Repositories\Contracts;

use App\Enums\Business\BusinessStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * All $customerId parameters refer to the tenant key stored in businesses.customer_id,
 * which is users.id (Ultimate SMS's established tenant convention), not customers.id.
 */
interface BusinessRepository extends BaseRepository
{
    public function findById(int $id): ?Business;

    /**
     * Row-locking variant of findById(), for callers that must hold the row
     * lock for the duration of a transaction (RFC-002 Opportunity Engine
     * run/read-modify-write flows).
     */
    public function findForUpdate(int $id): ?Business;

    public function findOwnedByCustomer(int $businessId, int $customerId): ?Business;

    public function findPrimaryByCustomer(int $customerId): ?Business;

    /**
     * The earliest Business ever created for this customer_id, by id — no
     * Workspace, primary, status or authorization filter. Used only as a
     * deterministic naming fallback (RFC-003 §10.5-equivalent tier 3), not
     * a tenancy or ownership lookup.
     */
    public function findFirstByCustomer(int $customerId): ?Business;

    /**
     * Every Business flagged primary for this customer_id, ascending by id.
     * findPrimaryByCustomer() assumes at most one; nothing in the schema
     * enforces that (no unique constraint on (customer_id, is_primary)), so
     * callers that must handle a broken multi-primary state use this
     * instead of silently taking the first row.
     */
    public function primaryBusinessesForCustomer(int $customerId): Collection;

    /**
     * Distinct, non-null workspace_id values across this customer_id's
     * Businesses, in first-seen order (businesses.id ascending). No
     * Workspace model is loaded and no ownership/activity/membership
     * filtering is applied — this is a raw candidate-ID read only.
     *
     * @return Collection<int, int>
     */
    public function workspaceIdsForCustomer(int $customerId): Collection;

    /**
     * Persists explicit customer_id and workspace_id context only — no
     * inference, no ownership/membership check between $customer and
     * $workspace (they are independent per RFC-003 §11.2: a Business's
     * direct owner is not required to be the Workspace owner), no
     * Workspace creation, and no is_active check on $workspace. All of
     * that is Milestone 2 orchestration; this method's only job is to
     * write the two explicit foreign keys it was handed.
     */
    public function createForCustomerInWorkspace(Customer $customer, Workspace $workspace, array $attributes): Business;

    public function update(Business $business, array $attributes): Business;

    /**
     * Reassigns a Business to a different Workspace by updating only
     * workspace_id (RFC-003 §16.2, Milestone 2 domain contract) — never
     * customer_id — mirroring updateCanonicalDomain()'s narrow single-field
     * mutation convention rather than the guarded generic update() path.
     * Accepts no arbitrary attribute array. Dispatches no event and writes
     * no workspace_transitions row; that is
     * WorkspaceManager::reassignBusiness()'s responsibility.
     */
    public function reassignWorkspace(Business $business, Workspace $workspace): Business;

    public function setPrimary(Business $business): Business;

    public function updateStatus(Business $business, BusinessStatus $status): Business;

    /**
     * canonical_domain is derived (never accepted from requests — RFC-001 §8.1), so it is
     * written through this narrow method rather than the guarded generic update() path.
     */
    public function updateCanonicalDomain(Business $business, ?string $canonicalDomain): Business;

    /**
     * Admin-only cross-tenant listing (RFC-001 §19 admin index; Milestone 6). Every
     * other method on this contract is intentionally tenant-scoped — this is the one
     * deliberate exception, for authorized backend administrators only. Never bind
     * this to a customer-facing route or controller.
     *
     * @param  array{search?: ?string, status?: ?string, industry?: ?string}  $filters  Only
     *   'search', 'status', and 'industry' are read; any other key is ignored.
     */
    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator;
}
