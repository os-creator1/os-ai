<?php

namespace App\Repositories\Contracts;

use App\Enums\Business\BusinessStatus;
use App\Models\Business;
use App\Models\Customer;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * All $customerId parameters refer to the tenant key stored in businesses.customer_id,
 * which is users.id (Ultimate SMS's established tenant convention), not customers.id.
 */
interface BusinessRepository extends BaseRepository
{
    public function findById(int $id): ?Business;

    public function findOwnedByCustomer(int $businessId, int $customerId): ?Business;

    public function findPrimaryByCustomer(int $customerId): ?Business;

    public function createForCustomer(Customer $customer, array $attributes): Business;

    public function update(Business $business, array $attributes): Business;

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
