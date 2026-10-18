<?php

namespace App\Library\Workspace;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessStatus;
use App\Exceptions\Workspace\InactiveWorkspaceMutationException;
use App\Exceptions\Workspace\UnauthorizedWorkspaceManagementException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Business\BusinessLocationManager;
use App\Library\Business\BusinessManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Facades\DB;

/**
 * The Agency's OWN operating context (V1 Agency topology: an Agency Workspace
 * holds exactly one Business — the agency's own — while every managed client
 * lives in a separate Client Workspace linked by an AgencyClientWorkspaceRelationship).
 *
 * That Business is what CRM, Calendar, Automations, SEO and Ads are scoped to,
 * so the agency's leads, appointments, workflows and provider connections can
 * never be a client's. An Agency skips ordinary Business onboarding by design,
 * which can leave this Business missing or still Draft (Draft is not selectable),
 * so none of those modules open. This is the one lightweight, explicit way to
 * bring it to Active WITHOUT the onboarding wizard:
 *
 *  - OWNER only (not an Admin): it provisions the account's operating identity;
 *  - the Workspace must be an eligible Agency (Agency tier AND a usable plan —
 *    the same gate every Agency surface asks), so entitlement is the Agency's
 *    own plan, never a client's subscription;
 *  - it can never act on a Workspace an Agency manages as a client;
 *  - it creates no relationship, so the workspace is never a client row and is
 *    invisible to Clients, SaaS plans and Agency revenue.
 *
 * Idempotent: an Active Business is returned unchanged.
 */
final class AgencyOperationsManager
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly AgencyClientWorkspaceRelationshipRepository $clientRelationships,
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly BusinessManager $businessManager,
        private readonly BusinessLocationManager $locationManager,
    ) {
    }

    /**
     * @throws WorkspaceNotFoundException
     * @throws InactiveWorkspaceMutationException
     * @throws UnauthorizedWorkspaceManagementException the actor is not the Workspace owner, or it is a managed client
     * @throws \App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException not an Agency with a usable plan
     */
    public function enable(int $actorUserId, Workspace $agencyWorkspace): Business
    {
        return DB::transaction(function () use ($actorUserId, $agencyWorkspace): Business {
            // Workspace first, then Business: the established lock order.
            $workspace = $this->workspaces->findForUpdate((int) $agencyWorkspace->id)
                ?? throw new WorkspaceNotFoundException((int) $agencyWorkspace->id);

            if (! $workspace->is_active) {
                throw new InactiveWorkspaceMutationException((int) $workspace->id);
            }

            if ((int) $workspace->owner_user_id !== $actorUserId) {
                throw new UnauthorizedWorkspaceManagementException($actorUserId, (int) $workspace->id);
            }

            $this->relationships->assertAgencyWorkspaceHasManagementEligibility($workspace);

            if ($this->clientRelationships->findActiveForClientWorkspaceForUpdate((int) $workspace->id) !== null) {
                throw new UnauthorizedWorkspaceManagementException($actorUserId, (int) $workspace->id);
            }

            $customer = Customer::query()->where('user_id', $workspace->owner_user_id)->firstOrFail();
            $business = Business::query()->where('workspace_id', $workspace->id)->lockForUpdate()->first();

            if ($business === null) {
                // Placeholder identity, exactly as client provisioning writes it;
                // the owner edits the real facts later in Business settings.
                $business = $this->businessManager->createBusinessForNewWorkspace($customer, $workspace, [
                    'name' => $workspace->name,
                    'industry' => BusinessIndustry::Other->value,
                    'country_code' => 'US',
                    'timezone' => 'UTC',
                    'currency_code' => 'USD',
                ]);
            }

            // Only ever fills a missing location; a real one is never overwritten.
            if ($business->primaryLocation()->doesntExist()) {
                $this->locationManager->upsertPrimaryLocation($business, [
                    'service_mode' => 'storefront',
                    'country_code' => 'US',
                ]);
            }

            if ($business->status === BusinessStatus::Draft) {
                $this->businessManager->activateForConfirmedSignup($business);
            }

            return $business->fresh();
        });
    }
}
