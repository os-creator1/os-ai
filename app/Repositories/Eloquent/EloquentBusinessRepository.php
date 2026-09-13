<?php

namespace App\Repositories\Eloquent;

use App\Enums\Business\BusinessStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentBusinessRepository extends EloquentBaseRepository implements BusinessRepository
{
    /**
     * Hard ceiling on admin index page size, independent of whatever a request claims —
     * AdminBusinessIndexRequest already bounds this too, but this repository never trusts
     * a caller's number alone.
     */
    private const MAX_ADMIN_PER_PAGE = 100;

    public function __construct(Business $business)
    {
        parent::__construct($business);
    }

    /**
     * Shared customer request query-budget optimization (Automations V2
     * §18) — a Business is re-read by id several times over the course of
     * one Business-scoped request (tenancy check, entitlement decision,
     * menu/shell resolution); this memoizes it for the life of the current
     * request only. Every write method below invalidates this same key.
     */
    public function findById(int $id): ?Business
    {
        return $this->rememberForRequest(
            "business:find:{$id}",
            fn () => $this->query()->find($id),
        );
    }

    public function findForUpdate(int $id): ?Business
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function countForWorkspace(Workspace $workspace): int
    {
        return $this->query()->where('workspace_id', $workspace->id)->count();
    }

    /**
     * @param  int  $customerId  The tenant key stored in businesses.customer_id, i.e. users.id (Customer::$user_id).
     */
    public function findOwnedByCustomer(int $businessId, int $customerId): ?Business
    {
        return $this->query()
            ->where('id', $businessId)
            ->where('customer_id', $customerId)
            ->first();
    }

    /**
     * @param  int  $customerId  The tenant key stored in businesses.customer_id, i.e. users.id (Customer::$user_id).
     */
    public function findPrimaryByCustomer(int $customerId): ?Business
    {
        return $this->query()
            ->where('customer_id', $customerId)
            ->where('is_primary', true)
            ->first();
    }

    public function findFirstByCustomer(int $customerId): ?Business
    {
        return $this->query()
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->first();
    }

    public function primaryBusinessesForCustomer(int $customerId): Collection
    {
        return $this->query()
            ->where('customer_id', $customerId)
            ->where('is_primary', true)
            ->orderBy('id')
            ->get();
    }

    public function workspaceIdsForCustomer(int $customerId): Collection
    {
        return $this->query()
            ->where('customer_id', $customerId)
            ->whereNotNull('workspace_id')
            ->orderBy('id')
            ->pluck('workspace_id')
            ->map(fn ($workspaceId) => (int) $workspaceId)
            ->unique()
            ->values();
    }

    public function createForCustomerInWorkspace(Customer $customer, Workspace $workspace, array $attributes): Business
    {
        return DB::transaction(function () use ($customer, $workspace, $attributes) {
            $isFirst = ! $this->query()->where('customer_id', $customer->user_id)->exists();

            $attributes = Arr::except($attributes, [
                'customer_id', 'workspace_id', 'is_primary', 'canonical_domain', 'status', 'activated_at',
            ]);

            /** @var Business $business */
            $business = $this->make($attributes);
            $business->customer_id = $customer->user_id;
            $business->workspace_id = $workspace->id;
            $business->is_primary = $isFirst;
            $business->status = BusinessStatus::Draft;
            $business->save();
            $this->forgetRequestCache("workspace:businesses:{$workspace->id}");

            return $business;
        });
    }

    public function update(Business $business, array $attributes): Business
    {
        $attributes = Arr::except($attributes, [
            'customer_id', 'is_primary', 'canonical_domain', 'status', 'activated_at',
        ]);

        $business->fill($attributes);
        $business->save();
        $this->forgetRequestCache("business:find:{$business->id}");

        return $business;
    }

    public function reassignWorkspace(Business $business, Workspace $workspace): Business
    {
        $previousWorkspaceId = $business->workspace_id;
        $business->workspace_id = $workspace->id;
        $business->save();
        $this->forgetRequestCache("business:find:{$business->id}");
        $this->forgetRequestCache("workspace:businesses:{$workspace->id}");

        if ($previousWorkspaceId !== null) {
            $this->forgetRequestCache("workspace:businesses:{$previousWorkspaceId}");
        }

        return $business;
    }

    public function setPrimary(Business $business): Business
    {
        return DB::transaction(function () use ($business) {
            $this->query()
                ->where('customer_id', $business->customer_id)
                ->where('id', '!=', $business->id)
                ->update(['is_primary' => false]);

            $business->is_primary = true;
            $business->save();

            // The bulk UPDATE above touches every OTHER Business owned by
            // this customer by a foreign key, not by its own primary key,
            // so their individually cached ids are not cheaply enumerable
            // here — forget the whole namespace rather than risk leaving
            // one of them stale for the rest of this request.
            $this->forgetRequestCachePrefixed('business:find:');

            return $business;
        });
    }

    public function updateStatus(Business $business, BusinessStatus $status): Business
    {
        $business->status = $status;

        if ($status === BusinessStatus::Active && $business->activated_at === null) {
            $business->activated_at = now();
        }

        $business->save();
        $this->forgetRequestCache("business:find:{$business->id}");

        return $business;
    }

    public function updateCanonicalDomain(Business $business, ?string $canonicalDomain): Business
    {
        $business->canonical_domain = $canonicalDomain;
        $business->save();
        $this->forgetRequestCache("business:find:{$business->id}");

        return $business;
    }

    public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $filters = Arr::only($filters, ['search', 'status', 'industry']);
        $perPage = max(1, min($perPage, self::MAX_ADMIN_PER_PAGE));

        $query = $this->query()->with(['customer.user']);

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('canonical_domain', 'like', "%{$search}%");
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['industry'] ?? null)) {
            $query->where('industry', $filters['industry']);
        }

        // Deterministic — newest first, ties impossible since id is a unique
        // monotonic key (unlike name/created_at, which can collide).
        return $query->orderBy('id', 'desc')->paginate($perPage);
    }
}
