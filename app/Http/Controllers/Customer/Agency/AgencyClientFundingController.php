<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Exceptions\Usage\ProviderApiUnavailableException;
use App\Exceptions\Usage\ProviderAuthenticationException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Business\CreateSetupIntentRequest;
use App\Http\Requests\Customer\Business\InitiateTopUpRequest;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Contract 09 §12 (AgencyRebill funding surface) — the managing Agency
 * owner's own route to fund a linked client's Business usage wallet,
 * charged against the Agency's own provider customer on the PLATFORM
 * Stripe account, exactly as AgencyRebill already works everywhere else
 * (BillingProfileManager, EffectivePayerResolver, UsageBillingCheckoutManager,
 * PaymentInstrumentManager — none of it reimplemented here).
 *
 * WHY THIS CONTROLLER EXISTS: the client's own Usage & Billing route
 * (UsageBillingTopUpController/UsageBillingPaymentMethodController)
 * requires userCanAccessBusiness() — real Client Workspace access the
 * managing Agency owner deliberately does not have outside an active View
 * As session, and View As itself deliberately pauses billing/funding
 * actions (Contract 08A). This is a second, narrow HTTP entry point onto
 * the SAME managers the client's own flow uses — no new wallet, payment
 * engine, or authority rule. Tenancy resolution below mirrors
 * AgencyClientsController's own
 * resolveAuthorizedAgencyWorkspace()/resolveLinkedClient()/
 * resolveSoleBusiness() exactly (the same manager/repository calls), not a
 * new authorization concept.
 *
 * RFC-005 FUNDING PROVIDER-FLOW CORRECTION CONTRACT §9/§11, LOCKED
 * ORDERING — initiateTopUp() below never calls PaymentInstrumentManager.
 * §11's "no Stripe call before the local funding-attempt row exists"
 * ordering, and §9's Option 1 ("the existing no_provider_customer denial
 * is preserved unmodified for ManualTopUp; no lazy provider-customer
 * creation is introduced"), both bind this Agency-owner surface exactly as
 * they bind the client's own UsageBillingTopUpController. A first-time
 * Agency funder with no payment_provider_customers row yet is refused
 * 'no_provider_customer' by UsageBillingCheckoutManager itself, unchanged
 * — createSetupIntent()/confirmSetupIntent() below are the contract-named
 * separate surface for establishing one ("Provider-customer establishment
 * belongs to the separate PaymentInstrumentManager::createSetupIntent()
 * flow").
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
 *
 * FUNDING SETUP (createSetupIntent()/confirmSetupIntent()) IS A
 * GENUINELY SEPARATE AUTHORITY FROM ORIGINATION.
 * PaymentInstrumentManager's own assertAuthorizedFundingPayer() is
 * owner-only but requires no standing consent (Contract 09 — configuring
 * funding must never deadlock on the very consent it exists to grant).
 * Neither setup action makes a client Business the payer of anything;
 * both resolve to the Agency's own Workspace via EffectivePayer, never
 * the client's (proven by PaymentInstrumentManager's own existing
 * ProviderCustomerOwnershipTest, not reproven here), and neither
 * interacts with, depends on, or is affected by View As.
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
            // No PaymentInstrumentManager call here — see the class
            // docblock's "LOCKED ORDERING" note. initiateTopUp() performs
            // its own canonical charge-origination authorization and its
            // own unmodified no_provider_customer denial; nothing is ever
            // created, and no provider call is ever made, before the local
            // funding-attempt row this same call creates.
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
     * The separate funding-setup surface the RFC-005 correction contract
     * names — mirrors UsageBillingPaymentMethodController::createSetupIntent()
     * exactly (same manager call, same JSON shape), scoped to the Agency
     * Workspace instead of a client Business. Never exposes a secret key;
     * the publishable key/client secret are the only Stripe-related values
     * ever sent to the browser.
     */
    public function createSetupIntent(CreateSetupIntentRequest $request, string $workspaceUid, string $clientWorkspaceUid): JsonResponse
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);
        [$clientWorkspace] = $this->resolveLinkedClient($agencyWorkspace, $clientWorkspaceUid);
        $business = $this->resolveSoleBusiness($clientWorkspace);
        $actorUserId = (int) Auth::id();

        try {
            $result = $this->paymentInstrumentManager->createSetupIntent($business, $actorUserId);
        } catch (UnauthorizedPayerAssignmentException) {
            return response()->json(['error' => 'You are not authorized to set up funding for this Business.'], 403);
        } catch (ProviderAuthenticationException|ProviderApiUnavailableException) {
            return response()->json(['error' => 'Payment provider is currently unavailable.'], 503);
        }

        return response()->json([
            'client_secret' => $result->clientSecret,
            'publishable_key' => config('services.stripe.key'),
        ]);
    }

    /**
     * Mirrors UsageBillingPaymentMethodController::confirmSetupIntent()
     * exactly — confirmSetupIntentAndAttach() never trusts the browser
     * redirect alone (authoritative provider retrieval), and is idempotent.
     */
    public function confirmSetupIntent(CreateSetupIntentRequest $request, string $workspaceUid, string $clientWorkspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);
        [$clientWorkspace] = $this->resolveLinkedClient($agencyWorkspace, $clientWorkspaceUid);
        $business = $this->resolveSoleBusiness($clientWorkspace);
        $actorUserId = (int) Auth::id();

        $providerSetupIntentId = (string) $request->input('setup_intent');

        try {
            $instrument = $this->paymentInstrumentManager->confirmSetupIntentAndAttach($business, $actorUserId, $providerSetupIntentId);
        } catch (UnauthorizedPayerAssignmentException) {
            return redirect()
                ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
                ->with('flash_error', 'You are not authorized to set up funding for this Business.');
        }

        if ($instrument === null) {
            return redirect()
                ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
                ->with('flash_error', 'Funding setup was not completed.');
        }

        return redirect()
            ->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
            ->with('flash_success', 'Funding payment method added.');
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
