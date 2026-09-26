<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessStatus;
use App\Events\Business\BusinessCreated;
use App\Events\Business\BusinessPrimaryLocationUpdated;
use App\Events\Business\BusinessServicesSynced;
use App\Events\Business\BusinessUpdated;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        private readonly ?WorkspaceRepository $workspaceRepository = null,
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
     * Implementation Contract 07 §4(b)/§12 — the smallest safe seam for
     * creating a Business at the moment a real Customer/Workspace already
     * exist but no session-scoped onboarding state does (Agency-initiated
     * client provisioning, AgencyClientProvisioningManager::accept()).
     *
     * WHY NOT createOrUpdateOnboardingBusiness()/applyIdentity(): that
     * method's CREATE branch calls
     * WorkspaceManager::resolveLegacyOnboardingWorkspace() — the exact
     * "reuse an existing owner's Workspace" behavior Contract 07 forbids
     * reviving — and gates on
     * EntitlementManager::assertCanCreateAnotherBusiness(), which throws
     * WorkspacePlanUnassignedException for any Workspace with no plan
     * assigned yet (mechanically confirmed:
     * decideBusinessSlotCapacity() has no "first Business is free"
     * exemption the way evaluateLocationCapacity() does for its first
     * Location). A freshly-provisioned Client Workspace has no plan by
     * design (Contract 07 §11 — no billing in this slice), so calling
     * that gate here would make every acceptance fail. This method
     * therefore goes straight to the same
     * BusinessRepository::createForCustomerInWorkspace() write
     * applyIdentity()'s CREATE branch itself ultimately calls, skipping
     * only the legacy-Workspace-resolution and entitlement-gating steps
     * that do not apply to a brand-new, not-yet-entitled Workspace —
     * not a rewrite of Business-creation policy, the same one write with
     * two preconditions that cannot fire here removed.
     *
     * Preserves BusinessCreated's exact existing dispatch shape
     * (businessId, customer.user_id), so every existing listener (B4
     * Automations, etc.) sees this Business exactly like any organically
     * created one.
     */
    public function createBusinessForNewWorkspace(Customer $customer, Workspace $workspace, array $attributes): Business
    {
        $normalizedAttributes = $attributes;

        foreach (self::URL_FIELDS as $field) {
            if (array_key_exists($field, $normalizedAttributes)) {
                $normalizedAttributes[$field] = $this->urlNormalizer->normalize($normalizedAttributes[$field]);
            }
        }

        return DB::transaction(function () use ($customer, $workspace, $normalizedAttributes) {
            $business = $this->businessRepository->createForCustomerInWorkspace($customer, $workspace, $normalizedAttributes);

            if (array_key_exists('website_url', $normalizedAttributes)) {
                $canonicalDomain = $this->urlNormalizer->canonicalDomain($normalizedAttributes['website_url']);
                $business = $this->businessRepository->updateCanonicalDomain($business, $canonicalDomain);
            }

            BusinessCreated::dispatch($business->id, $customer->user_id);

            return $business;
        });
    }

    /**
     * Contract 07 correction — the ONLY way a Client Business
     * AgencyClientProvisioningManager::accept() created moves from Draft to
     * Active. accept() deliberately never activates: it writes placeholder
     * identity (industry Other, country US, timezone UTC, currency USD) and
     * an address-less storefront primary location so invitation acceptance
     * stays one bounded transaction with no client-authored input yet. This
     * is the client owner's own explicit confirmation step for those exact
     * facts — never callable by the inviting Agency. The controller
     * enforces Workspace-owner-only authorization before this is ever
     * reached; this method independently re-verifies Business ownership
     * under the row lock regardless, the same fail-closed pattern
     * updateOwnBusinessProfile() already uses, so a stale or forged
     * Business reference can never activate a Business that is not this
     * customer's own.
     *
     * Atomic: re-verify ownership and Draft status under the Business row
     * lock, write the real identity facts, upsert the real primary
     * location, and transition Draft -> Active, all inside one
     * transaction. A Business that is no longer Draft by the time the lock
     * is acquired (already activated in another tab, a genuine race) fails
     * the whole write rather than silently re-activating or re-writing an
     * already-live Business.
     *
     * @param  array<string, mixed>  $identityAttributes
     * @param  array<string, mixed>  $locationAttributes
     *
     * @throws WorkspaceAccessDeniedException the Business does not belong to $customer
     * @throws RuntimeException the Business is not currently Draft
     */
    public function activateClientBusiness(Customer $customer, Business $business, array $identityAttributes, array $locationAttributes): Business
    {
        $this->assertOwnership($customer, $business);

        [$activated, $location] = DB::transaction(function () use ($customer, $business, $identityAttributes, $locationAttributes) {
            $locked = $this->businessRepository->findForUpdate($business->id);

            if ($locked === null || (int) $locked->customer_id !== (int) $customer->user_id) {
                throw new WorkspaceAccessDeniedException($customer->user_id, $business->id);
            }

            if ($locked->status !== BusinessStatus::Draft) {
                throw new RuntimeException(
                    "Business [{$locked->id}] is not Draft (status: {$locked->status->value}); it cannot be activated through this path."
                );
            }

            $updated = $this->businessRepository->update($locked, $identityAttributes);
            $location = app(BusinessLocationManager::class)->upsertPrimaryLocation($updated, $locationAttributes);
            $activated = $this->businessRepository->updateStatus($updated, BusinessStatus::Active);

            return [$activated, $location];
        });

        BusinessUpdated::dispatch($activated->id, array_keys($identityAttributes));
        BusinessPrimaryLocationUpdated::dispatch($activated->id, $location->id);

        return $activated;
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
     * Boundary B (Design System M2 A2 nonvisual remediation, Finding 6):
     * the customer-direct Business-profile update seam. Used only by
     * Customer\BusinessController::update() -- never by the generic
     * updateBusiness() callers (admin, onboarding, opportunity), which
     * remain entirely unaffected since this is a new, additive method.
     *
     * Closes the TOCTOU gap between the route middleware's unlocked
     * Workspace-active pre-check (Boundary A, EnsureBusinessProfileIsAccessible)
     * and the actual write: locks the Workspace first, then the Business,
     * inside one transaction -- matching WorkspaceManager::reassignBusiness()'s
     * own established Workspace-then-Business lock order (RFC-003 §16.2,
     * §18) to avoid an inverse-order deadlock against it. Re-verifies the
     * Business still belongs to the locked Workspace (BusinessWorkspaceMismatchException,
     * reused verbatim from reassignBusiness()'s identical scenario), then
     * re-runs the unmodified WorkspaceManager::userCanAccessBusiness()
     * decision -- safe to call here since both rows are already held under
     * this transaction's own locks by this point -- before delegating the
     * actual write to the existing, unmodified updateBusiness()/applyIdentity().
     *
     * $workspaceRepository is a trailing, defaulted-null constructor
     * dependency (not a required positional one) so that pre-existing test
     * doubles built as `new class(...) extends BusinessManager` with the
     * historical six-argument constructor (none of which exercise this
     * method -- they only override updateBusiness() for unrelated
     * Opportunity-flow verification tests) remain source-compatible. The
     * production, container-resolved instance always receives the real
     * bound WorkspaceRepository via ordinary constructor injection; only a
     * manually constructed instance that omits it and then unexpectedly
     * calls this method falls back to resolving the same container binding
     * here, immediately before use.
     */
    public function updateOwnBusinessProfile(Customer $customer, Business $business, array $attributes): Business
    {
        $workspaceRepository = $this->workspaceRepository ?? app(WorkspaceRepository::class);

        return DB::transaction(function () use ($customer, $business, $attributes, $workspaceRepository) {
            $expectedWorkspaceId = $business->workspace_id;

            if ($expectedWorkspaceId !== null) {
                $workspaceRepository->findForUpdate($expectedWorkspaceId);
            }

            $lockedBusiness = $this->businessRepository->findForUpdate($business->id);

            if ($lockedBusiness === null) {
                throw new WorkspaceAccessDeniedException($customer->user_id, $business->id);
            }

            if ($expectedWorkspaceId !== null && (int) $lockedBusiness->workspace_id !== (int) $expectedWorkspaceId) {
                throw new BusinessWorkspaceMismatchException(
                    $lockedBusiness->id,
                    (int) $expectedWorkspaceId,
                    (int) $lockedBusiness->workspace_id,
                );
            }

            if (! $this->workspaceManager->userCanAccessBusiness($customer->user_id, $lockedBusiness)) {
                throw new WorkspaceAccessDeniedException($customer->user_id, $lockedBusiness->id);
            }

            return $this->updateBusiness($customer, $lockedBusiness, $attributes);
        });
    }

    /**
     * Upsert the business's primary location. Customer Experience Slice 1A
     * (contract §7.3b): the write goes through the one canonical location
     * boundary, which asserts physical-location capacity before a location
     * is created and adds no new authority requirement to this path.
     */
    public function upsertPrimaryLocation(Customer $customer, Business $business, array $attributes): BusinessLocation
    {
        $this->assertOwnership($customer, $business);

        $location = app(BusinessLocationManager::class)->upsertPrimaryLocation($business, $attributes);

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
