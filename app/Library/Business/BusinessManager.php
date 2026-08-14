<?php

namespace App\Library\Business;

use App\Events\Business\BusinessCreated;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates writes to the Business aggregate (identity, primary location,
 * services) across the Milestone 1 repositories. Holds no persistence logic
 * of its own — every invariant (primary uniqueness, slug generation, service
 * sync semantics, tenant scoping) already lives in the repositories; this
 * class sequences those calls inside a transaction and dispatches domain
 * events once that transaction has committed.
 */
class BusinessManager
{
    private const URL_FIELDS = [
        'website_url',
        'google_business_profile_url',
        'facebook_url',
        'instagram_url',
    ];

    public function __construct(
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessLocationRepository $locationRepository,
        private readonly BusinessServiceRepository $serviceRepository,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    /**
     * Create the customer's business when $business is null, otherwise update it.
     * Re-checks ownership on every update even though a controller should have
     * already authorized the request (RFC-001 §11.1).
     */
    public function createOrUpdateOnboardingBusiness(Customer $customer, ?Business $business, array $attributes): Business
    {
        if ($business !== null) {
            $this->assertOwnership($customer, $business);
        }

        $outcome = $this->applyIdentity($customer, $business, $attributes);

        if ($outcome['created']) {
            BusinessCreated::dispatch($outcome['business']->id, $customer->user_id);
        } elseif ($outcome['changedFields'] !== []) {
            BusinessUpdated::dispatch($outcome['business']->id, $outcome['changedFields']);
        }

        return $outcome['business'];
    }

    /**
     * Update an already-existing business. Always re-checks ownership.
     */
    public function updateBusiness(Customer $customer, Business $business, array $attributes): Business
    {
        $this->assertOwnership($customer, $business);

        $outcome = $this->applyIdentity($customer, $business, $attributes);

        if ($outcome['changedFields'] !== []) {
            BusinessUpdated::dispatch($outcome['business']->id, $outcome['changedFields']);
        }

        return $outcome['business'];
    }

    /**
     * Upsert the business's single primary location. Delegates the
     * one-primary invariant entirely to BusinessLocationRepository.
     */
    public function upsertPrimaryLocation(Customer $customer, Business $business, array $attributes): BusinessLocation
    {
        $this->assertOwnership($customer, $business);

        $location = DB::transaction(fn () => $this->locationRepository->upsertPrimary($business, $attributes));

        BusinessPrimaryLocationUpdated::dispatch($business->id, $location->id);

        return $location;
    }

    /**
     * Sync the business's services. Delegates create/update/inactivate and
     * primary-service selection entirely to BusinessServiceRepository.
     */
    public function syncServices(Customer $customer, Business $business, array $services): Collection
    {
        $this->assertOwnership($customer, $business);

        $synced = DB::transaction(fn () => $this->serviceRepository->syncForBusiness($business, $services));

        BusinessServicesSynced::dispatch($business->id, $synced->pluck('id')->all());

        return $synced;
    }

    /**
     * Normalize URL attributes, persist identity (create or update), and
     * separately persist the derived canonical_domain — all in one
     * transaction, all before any event is dispatched.
     *
     * @return array{business: Business, created: bool, changedFields: array<int, string>}
     */
    private function applyIdentity(Customer $customer, ?Business $business, array $attributes): array
    {
        $normalizedAttributes = $attributes;
        $touchesWebsiteUrl = array_key_exists('website_url', $attributes);

        foreach (self::URL_FIELDS as $field) {
            if (array_key_exists($field, $normalizedAttributes)) {
                $normalizedAttributes[$field] = $this->urlNormalizer->normalize($normalizedAttributes[$field]);
            }
        }

        $created = $business === null;

        // CREATE path only (M2, RFC-004 §13.O): retried up to 3 attempts,
        // since the new explicit Workspace lock below (User -> Workspace
        // order) is the exact inverse of WorkspaceManager::transferOwnership()'s
        // existing Workspace -> User(s) order — a real cross-operation
        // deadlock is possible, resolved by bounded retry rather than
        // reordering either side's locks. The update-existing-Business
        // path is unaffected and remains at its existing single attempt.
        return DB::transaction(function () use ($customer, $business, $normalizedAttributes, $touchesWebsiteUrl, $created) {
            if ($created) {
                // The legacy onboarding path has no explicit Workspace
                // selector of its own (RFC-003 §10.6, §13.1) — this is the
                // one narrow compatibility resolver call that supplies it.
                // Any WorkspaceContextRequiredException or missing-owner
                // ModelNotFoundException from the resolver propagates
                // uncaught, rolling back this whole transaction exactly
                // like any other failure here.
                $workspace = $this->workspaceManager->resolveLegacyOnboardingWorkspace($customer->user_id);
                $lockedWorkspace = $this->workspaceManager->lockForLegacyOnboardingBusinessCreation($workspace);
                $this->entitlementManager->assertCanCreateAnotherBusiness($lockedWorkspace);
                $result = $this->businessRepository->createForCustomerInWorkspace($customer, $lockedWorkspace, $normalizedAttributes);
            } else {
                $result = $this->businessRepository->update($business, $normalizedAttributes);
            }

            $changedFields = $created ? [] : $this->changedIdentityFields($result);

            if ($touchesWebsiteUrl) {
                $canonicalDomain = $this->urlNormalizer->canonicalDomain($normalizedAttributes['website_url']);
                $result = $this->businessRepository->updateCanonicalDomain($result, $canonicalDomain);

                if (! $created) {
                    // A second save() resets getChanges() to just this write's diff, so merge
                    // it with the identity write's diff rather than losing the first one.
                    $changedFields = $this->mergeChangedFields($changedFields, $this->changedIdentityFields($result));
                }
            }

            return ['business' => $result, 'created' => $created, 'changedFields' => $changedFields];
        }, $created ? 3 : 1);
    }

    /**
     * @return array<int, string>
     */
    private function changedIdentityFields(Business $business): array
    {
        return array_values(array_diff(array_keys($business->getChanges()), ['updated_at', 'created_at']));
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, string>
     */
    private function mergeChangedFields(array $a, array $b): array
    {
        return array_values(array_unique(array_merge($a, $b)));
    }

    private function assertOwnership(Customer $customer, Business $business): void
    {
        if ((int) $business->customer_id !== (int) $customer->user_id) {
            throw new AuthorizationException('This business does not belong to the given customer.');
        }
    }
}
