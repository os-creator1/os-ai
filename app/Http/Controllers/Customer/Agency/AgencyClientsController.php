<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Http\Controllers\Controller;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageBillingPresenter;
use App\Library\ViewAs\ViewAsManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 08A — the Agency-facing "Clients" list/detail
 * screen and its View As entry point. A thin consumption layer over
 * Contracts 01, 04 and 07's already-built primitives: no independent
 * authorization logic lives here, and no write to the relationship table
 * happens here at all — that only ever happens when the RECIPIENT accepts
 * an invitation (AgencyClientProvisioningManager::accept()), never from
 * this controller.
 *
 * INVITE is deliberately not an action on this controller: the existing
 * Contract 07 route (customer.workspaces.client-invitations.store,
 * Workspace\ClientInvitationController@store) is the one and only send
 * endpoint, reused as-is by this slice's "Invite Client" form — never a
 * second "send invitation" controller (§12).
 *
 * DATA SOURCE: every list/detail read starts from
 * AgencyClientWorkspaceRelationshipRepository — never
 * WorkspaceRepository::businessesForWorkspace() as the source of "who is a
 * client," never Business-switcher or Customer-context-switcher state,
 * never shared Workspace membership. businessesForWorkspace() is used only
 * AFTER a Client Workspace is already proven to be this Agency's linked
 * client, purely to resolve that Workspace's own current Business under
 * the 1-Workspace/1-Business assumption.
 */
class AgencyClientsController extends Controller
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly AgencyClientWorkspaceRelationshipRepository $relationshipRepository,
        private readonly AgencyClientRelationshipManager $relationshipManager,
        private readonly ViewAsManager $viewAsManager,
        private readonly CustomerAccountAccessResolver $accountAccessResolver,
        private readonly BillingProfileManager $billingProfileManager,
        private readonly BusinessUsageWalletRepository $walletRepository,
        private readonly UsageBillingPresenter $usageBillingPresenter,
    ) {
    }

    public function index(Request $request, string $workspaceUid): View
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);

        $search = trim((string) $request->query('search', ''));

        $clients = $this->relationshipRepository
            ->activeForAgencyWorkspace((int) $agencyWorkspace->id)
            ->map(fn (AgencyClientWorkspaceRelationship $relationship) => $this->presentClientRow($relationship))
            ->filter()
            ->when($search !== '', fn (Collection $rows) => $rows->filter(
                fn (array $row) => str_contains(mb_strtolower($row['workspace_name']), mb_strtolower($search))
                    || ($row['business_name'] !== null && str_contains(mb_strtolower($row['business_name']), mb_strtolower($search)))
            ))
            ->values();

        return view('customer.agency.clients.index', [
            'agencyWorkspace' => $agencyWorkspace,
            'clients' => $clients,
            'search' => $search,
        ]);
    }

    public function show(string $workspaceUid, string $clientWorkspaceUid): View
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);
        [$clientWorkspace, $relationship] = $this->resolveLinkedClient($agencyWorkspace, $clientWorkspaceUid);

        $businesses = $this->workspaceRepository->businessesForWorkspace($clientWorkspace);
        $business = $businesses->count() === 1 ? $businesses->first() : null;

        return view('customer.agency.clients.show', [
            'agencyWorkspace' => $agencyWorkspace,
            'clientWorkspace' => $clientWorkspace,
            'relationship' => $relationship,
            'business' => $business,
            'businessCount' => $businesses->count(),
            'locationCount' => $business !== null ? $business->locations()->count() : 0,
            'primaryLocation' => $business?->primaryLocation,
            'accessDecision' => $this->accountAccessResolver->resolve($clientWorkspace),
            // Lane C §C8 — this client's Agency SaaS subscription, and what the
            // Agency may offer them. Read-only here: OFFERING is a POST to the
            // Agency's own SaaS surface, and CHARGING belongs to the client.
            'agencySubscription' => app(\App\Library\AgencyBilling\AgencyClientSubscriptionManager::class)
                ->findForClientWorkspace($clientWorkspace),
            'sellablePlans' => app(\App\Library\AgencyBilling\AgencySaasPlanManager::class)
                ->sellablePlans($agencyWorkspace),
            'isAgencyOwner' => (int) $agencyWorkspace->owner_user_id === (int) Auth::id(),
            // Contract 09 §6.2 — the Agency owner's own AgencyRebill consent
            // state for this client's Business, read the same way the
            // client's own Usage & Billing page reads it (never a raw
            // payer_type column).
            'billingResponsibility' => $business !== null
                ? $this->billingProfileManager->billingResponsibilityFor($business, (int) Auth::id())
                : null,
            // Contract 09 §12 correction — the actual wallet currency for
            // the funding-amount field's label, read the same way the
            // client's own Usage & Billing dashboard reads it
            // (UsageBillingPresenter::buildDashboardViewModel()'s own
            // wallet['currency_code']) — never a hardcoded 'USD'.
            'walletCurrencyCode' => $business !== null
                ? ($this->walletRepository->findByBusinessId((int) $business->id)?->currency?->code ?? '')
                : '',
            // RFC-005 Funding Provider-Flow Correction Contract §9/§11 —
            // whether the Agency's own funding setup (provider customer +
            // saved payment method) already exists, read the exact same
            // way the client's own Usage & Billing dashboard resolves
            // "whose payment method would this Business's payer actually
            // charge" (UsageBillingPresenter::buildDashboardViewModel()'s
            // own resolvePaymentMethod(), which for an AgencyRebill payer
            // already resolves to the managing Agency Workspace's default
            // instrument, never the client's — Contract 09). The top-up
            // form is gated on this being non-null; the setup form is
            // shown instead when it is null.
            'agencyPaymentMethod' => $business !== null
                ? $this->usageBillingPresenter->buildDashboardViewModel($business)->paymentMethod
                : null,
        ]);
    }

    /**
     * Contract 09 §6.2/§7 — the Agency owner grants (or re-affirms) standing
     * consent for the Agency to fund this client's Business. Deliberately
     * NOT the existing Client/legacy payer selector
     * (UpdateBusinessPayerRequest only ever accepts business/workspace,
     * Enums\Usage\PayerType's own docblock) — AgencyRebill has its own
     * authority rule (BillingProfileManager::assertBillingResponsibilityAuthority(),
     * the managing Agency Workspace owner only) and this action asserts
     * nothing itself: every check, the relationship re-validation and the
     * audit row all live in BillingProfileManager::assignPayer(), unchanged.
     */
    public function assignAgencyRebill(Request $request, string $workspaceUid, string $clientWorkspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);
        [$clientWorkspace] = $this->resolveLinkedClient($agencyWorkspace, $clientWorkspaceUid);
        $business = $this->resolveSoleBusiness($clientWorkspace);

        $request->validate(['confirm' => ['required', 'accepted']]);

        try {
            $this->billingProfileManager->assignPayer(
                $business,
                PayerType::AgencyRebill,
                (int) Auth::id(),
                'Agency owner consent via Client accounts → AgencyRebill.',
            );
        } catch (UnauthorizedPayerAssignmentException) {
            abort(404);
        }

        return redirect()->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
            ->with(['status' => 'success', 'message' => 'This Business is now funded by your Agency.']);
    }

    /**
     * Contract 09 §6.2 — the Agency owner withdraws standing consent. The
     * payer stays agency_rebill (BillingProfileManager::
     * revokeAgencyRebillConsent() never falls back to another payer); every
     * NEW Agency-funded charge is refused from this commit on.
     */
    public function revokeAgencyRebill(Request $request, string $workspaceUid, string $clientWorkspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);
        [$clientWorkspace] = $this->resolveLinkedClient($agencyWorkspace, $clientWorkspaceUid);
        $business = $this->resolveSoleBusiness($clientWorkspace);

        try {
            $this->billingProfileManager->revokeAgencyRebillConsent(
                $business,
                (int) Auth::id(),
                'Agency owner revoked consent via Client accounts → AgencyRebill.',
            );
        } catch (UnauthorizedPayerAssignmentException) {
            abort(404);
        }

        return redirect()->route('customer.workspaces.clients.show', [$agencyWorkspace->uid, $clientWorkspace->uid])
            ->with(['status' => 'success', 'message' => 'Your Agency\'s standing consent to fund this Business has been withdrawn.']);
    }

    /**
     * The same "exactly one Business" resolution show() already performs,
     * as a 404 rather than a nullable — every AgencyRebill action requires
     * a real target.
     */
    private function resolveSoleBusiness(Workspace $clientWorkspace): Business
    {
        $businesses = $this->workspaceRepository->businessesForWorkspace($clientWorkspace);

        if ($businesses->count() !== 1) {
            abort(404);
        }

        return $businesses->first();
    }

    /**
     * The View As entry point. The Client identifier is resolved through
     * this Agency's OWN active relationship set BEFORE startAgencyView()
     * is ever called (§ Routes) — a caller can never make this call
     * startAgencyView() for a Client Workspace that does not belong to the
     * routed Agency Workspace's active relationships, whatever uid they
     * submit.
     */
    public function viewAs(string $workspaceUid, string $clientWorkspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->resolveAuthorizedAgencyWorkspace($workspaceUid);
        [$clientWorkspace] = $this->resolveLinkedClient($agencyWorkspace, $clientWorkspaceUid);

        /** @var User $actor */
        $actor = Auth::user();

        $this->viewAsManager->startAgencyView($actor, $agencyWorkspace->uid, $clientWorkspace->uid);

        return redirect()->route('user.home')->with([
            'status' => 'info',
            'message' => 'You are now viewing this client\'s account. Your own account is unchanged; use Exit client view when you are done.',
        ]);
    }

    /**
     * Every action's shared entry gate: a real Agency Workspace, the
     * current actor holding Contract 01's own canonical Agency authority
     * over it (owner, or active Admin/Staff of this exact Workspace), and
     * that Workspace currently holding Agency management eligibility
     * (Agency tier + a usable account — Locked/Inactive/Suspended/non-
     * Agency refused, Grace usable). Both checks are the manager's own
     * canonical methods, never reimplemented here.
     *
     * A tenancy-safe 404 for both failure reasons, deliberately — the same
     * "does this exist for you at all" non-disclosure ViewAsManager::
     * startAgencyView() itself already uses for the identical authority/
     * eligibility family, rather than inventing a new 403 here.
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
     * Proves $clientWorkspaceUid is a real Workspace this EXACT
     * $agencyWorkspace currently, actively manages — the sole use of
     * Contract 01's relationship repository as the tenancy authority for
     * "this Agency manages this Client." A terminated relationship, a
     * relationship belonging to a different Agency, or an unrelated
     * Workspace with no relationship at all are refused identically.
     *
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

    /**
     * One list row. Never null for a structurally sound Active relationship
     * (clientWorkspace() is a real FK with no delete path), but guarded
     * defensively rather than assumed. A Client Workspace whose Business
     * count is not exactly one under the current 1-Workspace/1-Business
     * assumption is surfaced as a data-integrity flag on the row, never
     * silently resolved to an arbitrary Business (Contract 10 owns repair).
     *
     * @return array<string, mixed>|null
     */
    private function presentClientRow(AgencyClientWorkspaceRelationship $relationship): ?array
    {
        $clientWorkspace = $relationship->clientWorkspace;

        if ($clientWorkspace === null) {
            return null;
        }

        $businesses = $this->workspaceRepository->businessesForWorkspace($clientWorkspace);

        return [
            'workspace_uid' => $clientWorkspace->uid,
            'workspace_name' => $clientWorkspace->name,
            'business_name' => $businesses->count() === 1 ? $businesses->first()->name : null,
            'business_count' => $businesses->count(),
            'established_at' => $relationship->established_at,
        ];
    }
}
