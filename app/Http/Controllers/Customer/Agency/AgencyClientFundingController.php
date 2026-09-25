<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Business\InitiateTopUpRequest;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Contract 09 §12 (AgencyRebill funding surface) — the managing Agency
 * owner's own route to fund a linked client's Business usage wallet,
 * charged against the Agency's own provider customer on the PLATFORM
 * Stripe account, exactly as AgencyRebill already works everywhere else
 * (BillingProfileManager, EffectivePayerResolver, UsageBillingCheckoutManager
 * — none of it reimplemented here).
 *
 * WHY THIS CONTROLLER EXISTS: the client's own Usage & Billing route
 * (UsageBillingTopUpController) requires userCanAccessBusiness() — real
 * Client Workspace access the managing Agency owner deliberately does not
 * have outside an active View As session, and View As itself deliberately
 * pauses billing/funding actions (Contract 08A). This is a second, narrow
 * HTTP entry point onto the SAME UsageBillingCheckoutManager::initiateTopUp()
 * used by the client's own flow — no new wallet, payment engine, or
 * authority rule. Tenancy resolution below mirrors AgencyClientsController's
 * own resolveAuthorizedAgencyWorkspace()/resolveLinkedClient()/
 * resolveSoleBusiness() exactly (the same manager/repository calls), not a
 * new authorization concept.
 *
 * ORIGINATION AUTHORITY IS BillingProfileManager's, NOT THIS CONTROLLER'S.
 * initiateTopUp() calls assertAuthorizedChargePayer(), which for an
 * AgencyRebill payer requires the actor to own the managing Agency
 * Workspace AND requires active standing consent
 * (actorMayOriginateChargeFor()) — an Agency owner who revoked consent, or
 * any non-owner Agency member, is refused there, not here. A Business
 * whose payer is not (or no longer) AgencyRebill is refused the identical
 * way. THE CLIENT CAN NEVER REACH THIS ROUTE: it lives under the Agency's
 * own Workspace prefix and resolveAuthorizedAgencyWorkspace() asserts
 * Agency authority over $workspaceUid, never client Workspace membership.
 */
class AgencyClientFundingController extends Controller
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
        private readonly AgencyClientRelationshipManager $relationshipManager,
        private readonly UsageBillingCheckoutManager $checkoutManager,
        private readonly UsageWalletManager $walletManager,
        private readonly PaymentInstrumentManager $paymentInstrumentManager,
        private readonly BillingProfileManager $billingProfileManager,
        private readonly BusinessFundingAttemptRepository $attemptRepository,
    ) {
    }

    public function initiateTopUp(InitiateTopUpRequest $request, string $workspaceUid, string $clientWorkspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);
        [$clientWorkspace] = $this->resolveLinkedClient($agencyWorkspace, $clientWorkspaceUid);
        $business = $this->resolveSoleBusiness($clientWorkspace);
        $actorUserId = (int) Auth::id();
        $amountMicro = (int) $request->validated('amount_micro');

        // Customer Experience Slice 5 (contract §12.2 E-18; T-WALLET-1) —
        // the same $5.00 manual-top-up floor the client's own flow enforces,
        // reused unchanged.
        if ($this->walletManager->manualTopUpDenialReason($amountMicro) !== null) {
            return redirect()
                ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
                ->with('flash_error', __('locale.usage_billing.validation.top_up_minimum'));
        }

        try {
            // Ordering correction — resolveProviderCustomer() below
            // deliberately uses the BROADER assertAuthorizedFundingPayer()
            // rule (owner-only, no standing consent required — funding
            // configuration must never deadlock on a consent it exists to
            // grant). Calling it FIRST, before this canonical
            // charge-origination check, would let a crafted top-up POST
            // create a real Stripe provider customer for an Agency whose
            // standing consent was already revoked, before the request
            // later failed. assertAuthorizedChargePayer() is the SAME
            // canonical preflight initiateTopUp() below performs itself
            // (and re-performs, locked, under the wallet lock) — calling it
            // here first is not a second authority rule, only an earlier
            // call to the one that already exists, so nothing created
            // below can ever outlive a request an unrevoked-consent check
            // would have refused anyway.
            $this->billingProfileManager->assertAuthorizedChargePayer($business, $actorUserId);

            // Contract 09 §12 — a first-time Agency funder has no Agency
            // Workspace payment_provider_customers row yet (Lane C's
            // Connect account is a separate, unrelated Stripe object).
            // resolveProviderCustomer() is the existing, idempotent
            // mechanism for establishing one — the same one the client's
            // own Usage & Billing page uses for itself. It resolves to the
            // AGENCY's own Workspace via EffectivePayer (never the
            // client's), and makes its one outbound provider call strictly
            // outside any DB transaction/lock, exactly like every other
            // provider call in this flow. It does not require a previously
            // saved card — the hosted Checkout Session below collects
            // payment details directly.
            $this->paymentInstrumentManager->resolveProviderCustomer($business, $actorUserId);

            $result = $this->checkoutManager->initiateTopUp($business, $actorUserId, $amountMicro, [
                'success_route' => 'customer.workspaces.clients.funding.top-up.confirm',
                'success_params' => ['workspaceUid' => $agencyWorkspace->uid, 'clientWorkspaceUid' => $clientWorkspace->uid],
                'cancel_route' => 'customer.workspaces.clients.show',
                'cancel_params' => [$agencyWorkspace->uid, $clientWorkspace->uid],
            ]);
        } catch (UnauthorizedPayerAssignmentException) {
            abort(404);
        } catch (UsageWalletNotFoundException) {
            return redirect()
                ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
                ->with('flash_error', __('locale.usage_billing.messages.wallet_not_set_up'));
        }

        if ($result->denialReason !== null) {
            return redirect()
                ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
                ->with('flash_error', __('locale.usage_billing.messages.top_up_not_started'));
        }

        if ($result->redirectUrl !== null) {
            return redirect()->away($result->redirectUrl);
        }

        return redirect()
            ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
            ->with('flash_success', __('locale.usage_billing.messages.top_up_started'));
    }

    /**
     * The hosted-Checkout return handler this controller exists to provide
     * — never trusts the redirect alone; confirmAttemptFromReturn()
     * independently re-fetches and verifies the Checkout Session before
     * crediting, identically to the client's own confirmFromReturn().
     * Attempt isolation is enforced against THIS Business, resolved the
     * same tenancy-safe way as initiateTopUp() above, not against the
     * attempt's own unauthenticated claim.
     *
     * Deliberately gated by resolveAuthorizedAgencyWorkspace()'s ordinary
     * Agency authority (owner or active Admin/Staff) — the SAME gate
     * show()/viewAs() already use elsewhere on this surface — not
     * owner-only. Completing an already-started Checkout return moves no
     * new money beyond what the initiating owner already authorized and
     * Stripe already collected; only origination (initiateTopUp() above,
     * via BillingProfileManager::assertAuthorizedChargePayer()) is
     * owner-gated.
     */
    public function confirmFromReturn(string $workspaceUid, string $clientWorkspaceUid, int $attempt): RedirectResponse
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);
        [$clientWorkspace] = $this->resolveLinkedClient($agencyWorkspace, $clientWorkspaceUid);
        $business = $this->resolveSoleBusiness($clientWorkspace);

        $fundingAttempt = $this->attemptRepository->findById($attempt);

        if ($fundingAttempt === null || (int) $fundingAttempt->business_id !== (int) $business->id) {
            abort(404);
        }

        $result = $this->checkoutManager->confirmAttemptFromReturn($fundingAttempt);

        if ($result->denialReason !== null) {
            return redirect()
                ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
                ->with('flash_error', __('locale.usage_billing.messages.top_up_not_confirmed'));
        }

        return redirect()
            ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
            ->with('flash_success', __('locale.usage_billing.messages.top_up_confirmed'));
    }

    /**
     * Identical to AgencyClientsController's own private helper of the
     * same name — the same canonical authority/eligibility checks, never
     * reimplemented, deliberately duplicated here rather than shared by
     * inheritance so each thin controller resolves its own tenancy chain,
     * matching this codebase's existing convention.
     */
    private function resolveAuthorizedAgencyWorkspace(string $workspaceUid): Workspace
    {
        $agencyWorkspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($agencyWorkspace === null) {
            abort(404);
        }

        $actorId = (int) Auth::id();

        if (! $this->relationshipManager->actorHasAgencyAuthority($actorId, $agencyWorkspace)) {
            abort(404);
        }

        try {
            $this->relationshipManager->assertAgencyWorkspaceHasManagementEligibility($agencyWorkspace);
        } catch (AgencyWorkspaceNotEligibleException) {
            abort(404);
        }

        return $agencyWorkspace;
    }

    /**
     * @return array{0: Workspace, 1: AgencyClientWorkspaceRelationship}
     */
    private function resolveLinkedClient(Workspace $agencyWorkspace, string $clientWorkspaceUid): array
    {
        $clientWorkspace = $this->workspaceRepository->findByUid($clientWorkspaceUid);

        if ($clientWorkspace === null) {
            abort(404);
        }

        $relationship = $this->relationshipRepository->findActiveForClientWorkspace((int) $clientWorkspace->id);

        if ($relationship === null || (int) $relationship->agency_workspace_id !== (int) $agencyWorkspace->id) {
            abort(404);
        }

        return [$clientWorkspace, $relationship];
    }

    private function resolveSoleBusiness(Workspace $clientWorkspace): Business
    {
        $businesses = $this->workspaceRepository->businessesForWorkspace($clientWorkspace);

        if ($businesses->count() !== 1) {
            abort(404);
        }

        return $businesses->first();
    }
}
