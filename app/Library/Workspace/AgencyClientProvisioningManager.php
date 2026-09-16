<?php

namespace App\Library\Workspace;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Workspace\ClientInvitationStatus;
use App\Exceptions\Workspace\AgencyClientSelfLinkException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Exceptions\Workspace\ClientWorkspaceAlreadyManagedException;
use App\Exceptions\Workspace\InvalidClientInvitationClaimException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Library\Business\BusinessLocationManager;
use App\Library\Business\BusinessManager;
use App\Models\ClientWorkspaceInvitation;
use App\Models\User;
use App\Repositories\Contracts\ClientWorkspaceInvitationRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Implementation Contract 07 §4/§5/§7 — the acceptance-time atomic
 * orchestrator. Runs ONLY once a real, authenticated User/Customer already
 * exists (Flow B); creates NOTHING at invitation time (that is
 * ClientInvitationManager's job, and it creates only the invitation row).
 *
 * Every one of the five results below is created inside ONE outer
 * transaction (§7): Client Workspace, Client Business, Primary Location,
 * active Agency<->Client relationship, and the invitation's own Accepted
 * transition. If any step fails — most importantly, if
 * AgencyClientRelationshipManager::create() refuses for any reason, a race
 * included — every prior write in this transaction rolls back. No orphan
 * Client Workspace is ever left behind.
 *
 * SINGLE-USE / CONCURRENCY. The invitation row is locked
 * (findByUidForUpdate()) as the very first statement inside the
 * transaction, before any other read or write. A second concurrent
 * acceptance attempt for the same invitation blocks on that lock until the
 * first transaction commits or rolls back; it then re-reads the row under
 * its own lock and finds it no longer Pending, and refuses. Every
 * validation (Pending, unexpired, token, email match) is re-asserted here
 * under that lock — the read-only ClientInvitationManager::validateClaim()
 * a caller may have already shown a confirmation screen with is never
 * treated as the actual gate.
 *
 * NO NEW AGENCY-SIDE AUTHORITY CHECK HERE (§6): the Agency already acted,
 * at invitation time. This step asks only that the accepting User is
 * authenticated and owns the invited email. Agency eligibility is
 * re-asked fresh by AgencyClientRelationshipManager::create() itself
 * (Contract 01 §6/Contract 07 Clarification C) — this class never
 * bypasses or duplicates that check, and never catches
 * AgencyWorkspaceNotEligibleException to paper over it: it propagates,
 * rolling back the whole transaction, exactly as any other failure here
 * does.
 */
class AgencyClientProvisioningManager
{
    public function __construct(
        private readonly ClientWorkspaceInvitationRepository $invitationRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly BusinessManager $businessManager,
        private readonly BusinessLocationManager $locationManager,
        private readonly AgencyClientRelationshipManager $relationshipManager,
    ) {
    }

    /**
     * @throws InvalidClientInvitationClaimException
     * @throws WorkspaceNotFoundException
     * @throws AgencyWorkspaceNotEligibleException
     * @throws ClientWorkspaceAlreadyManagedException
     * @throws AgencyClientSelfLinkException
     */
    public function accept(User $authenticatedUser, string $invitationUid, string $plaintextToken): ClientWorkspaceInvitation
    {
        $customer = $authenticatedUser->customer;

        if ($customer === null) {
            // Structurally shouldn't happen for a real customer-facing
            // login, but this is a fail-closed boundary, not an assumption.
            throw new InvalidClientInvitationClaimException('no_customer');
        }

        return DB::transaction(function () use ($authenticatedUser, $customer, $invitationUid, $plaintextToken) {
            // Step 4 (locked, single-use, concurrency-safe) — the very
            // first statement in the transaction.
            $locked = $this->invitationRepository->findByUidForUpdate($invitationUid);

            if ($locked === null) {
                throw new InvalidClientInvitationClaimException('not_found');
            }

            // Steps 2-3, re-verified fresh under the lock — never trusting
            // an earlier read-only validateClaim() call.
            $this->assertClaimStillValid($locked, $plaintextToken, $authenticatedUser->email);

            $agencyWorkspace = $this->workspaceRepository->findById((int) $locked->agency_workspace_id);

            if ($agencyWorkspace === null) {
                throw new WorkspaceNotFoundException((int) $locked->agency_workspace_id);
            }

            // Step 7 — the real User's own ID, never a placeholder.
            $clientWorkspace = $this->workspaceManager->createWorkspace(
                (int) $authenticatedUser->id,
                $this->workspaceName($locked),
            );

            // Step 8 — the smallest safe session-independent seam (see
            // BusinessManager::createBusinessForNewWorkspace()'s own
            // docblock for why applyIdentity()'s CREATE branch is not
            // reusable here).
            $business = $this->businessManager->createBusinessForNewWorkspace($customer, $clientWorkspace, [
                'name' => $locked->intended_business_name ?? $this->workspaceName($locked),
                'industry' => BusinessIndustry::Other->value,
                'country_code' => 'US',
                'timezone' => 'UTC',
                'currency_code' => 'USD',
            ]);

            // Step 9 — unchanged, exactly as organic onboarding uses it;
            // its own capacity check already exempts a Business's first
            // Location from requiring an assigned plan.
            $this->locationManager->upsertPrimaryLocation($business, [
                'service_mode' => 'storefront',
                'country_code' => 'US',
            ]);

            // Step 10 — unchanged. established_by_user_id is the AGENCY
            // actor who sent the invitation, never the accepting client
            // (§10): two distinct real actors, both recorded accurately.
            $this->relationshipManager->create(
                (int) $locked->invited_by_user_id,
                $agencyWorkspace,
                $clientWorkspace,
            );

            // Steps 11-13, together.
            return $this->invitationRepository->markAccepted($locked, (int) $clientWorkspace->id);
        });
    }

    private function assertClaimStillValid(ClientWorkspaceInvitation $locked, string $plaintextToken, string $authenticatedEmail): void
    {
        if ($locked->status !== ClientInvitationStatus::Pending) {
            throw new InvalidClientInvitationClaimException('not_pending');
        }

        if ($locked->isExpired()) {
            throw new InvalidClientInvitationClaimException('expired');
        }

        if (! Hash::check($plaintextToken, $locked->token_hash)) {
            throw new InvalidClientInvitationClaimException('invalid_token');
        }

        if (ClientInvitationManager::normalizeEmail($authenticatedEmail) !== $locked->email) {
            throw new InvalidClientInvitationClaimException('email_mismatch');
        }
    }

    private function workspaceName(ClientWorkspaceInvitation $invitation): string
    {
        return $invitation->intended_business_name ?? 'New Workspace';
    }
}
