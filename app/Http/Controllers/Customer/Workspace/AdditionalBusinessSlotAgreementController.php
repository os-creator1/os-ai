<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Exceptions\Usage\SlotRenewalChargeNotResumableException;
use App\Exceptions\Usage\UnauthorizedSlotAgreementActionException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Customer\Workspace\CancelSlotAgreementRequest;
use App\Http\Requests\Customer\Workspace\InitiateSlotAgreementCheckoutRequest;
use App\Http\Requests\Customer\Workspace\RequestSlotAgreementIncreaseRequest;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\AdditionalBusinessSlotAgreement;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * M4 contract §17 — Workspace-scoped customer surface. Every action is a
 * thin orchestration layer: authenticate, load the row, call exactly one
 * UsageBillingCheckoutManager method, render/redirect the result — no
 * action creates or updates an M4 table row itself (§16).
 */
class AdditionalBusinessSlotAgreementController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly AdditionalBusinessSlotAgreementRepository $agreementRepository,
        private readonly AdditionalBusinessSlotRenewalChargeRepository $renewalChargeRepository,
        private readonly UsageBillingCheckoutManager $checkoutManager,
    ) {
    }

    public function show(string $workspaceUid): View
    {
        $workspace = $this->resolveOwnedWorkspace($workspaceUid, (int) Auth::id());

        $agreement = $this->agreementRepository->query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id')
            ->first();

        return view('customer.workspace.additional-business-slots.show', [
            'workspace' => $workspace,
            'agreement' => $agreement,
        ]);
    }

    /**
     * M4 contract §17 — the single named checkout action; quotes then
     * immediately initiates the Checkout Session in the same request, the
     * only two steps §17's own action list authorizes.
     */
    public function checkout(InitiateSlotAgreementCheckoutRequest $request, string $workspaceUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveOwnedWorkspace($workspaceUid, $actorUserId);

        try {
            $quote = $this->checkoutManager->quoteAdditionalSlotAgreement($workspace, (int) $request->validated('target_allocation_count'), $actorUserId);
            $agreement = $this->agreementRepository->findById($quote->agreementId);
            $result = $this->checkoutManager->initiateSlotAgreementCheckout($agreement, $actorUserId);
        } catch (UnauthorizedSlotAgreementActionException) {
            return redirect()->back()->with('flash_error', 'This additional-slot checkout could not be started.');
        }

        if ($result->redirectUrl === null) {
            return redirect()->back()->with('flash_error', 'Checkout could not be started.');
        }

        return redirect()->away($result->redirectUrl);
    }

    public function confirmFromReturn(string $workspaceUid, int $agreement): RedirectResponse
    {
        $workspace = $this->resolveOwnedWorkspace($workspaceUid, (int) Auth::id());
        $agreementModel = $this->resolveWorkspaceAgreement($workspace, $agreement);

        $this->checkoutManager->confirmSlotAgreementFromReturn($agreementModel);

        return redirect()
            ->route('customer.workspaces.additional-business-slots.show', ['workspaceUid' => $workspaceUid])
            ->with('flash_success', 'Additional-slot purchase confirmed.');
    }

    public function requestIncrease(RequestSlotAgreementIncreaseRequest $request, string $workspaceUid, int $agreement): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveOwnedWorkspace($workspaceUid, $actorUserId);
        $agreementModel = $this->resolveWorkspaceAgreement($workspace, $agreement);

        try {
            $this->checkoutManager->requestSlotAgreementIncrease(
                $agreementModel,
                (int) $request->validated('target_allocation_count'),
                (string) $request->validated('change_operation_id'),
                $actorUserId,
            );
        } catch (UnauthorizedSlotAgreementActionException) {
            return redirect()->back()->with('flash_error', 'This increase request could not be processed.');
        }

        return redirect()
            ->route('customer.workspaces.additional-business-slots.show', ['workspaceUid' => $workspaceUid])
            ->with('flash_success', 'Additional-slot increase requested.');
    }

    public function retryRenewal(string $workspaceUid, int $agreement, int $charge): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveOwnedWorkspace($workspaceUid, $actorUserId);
        $this->resolveWorkspaceAgreement($workspace, $agreement);

        $chargeModel = $this->renewalChargeRepository->findById($charge);

        if ($chargeModel === null || (int) $chargeModel->agreement_id !== $agreement) {
            abort(404);
        }

        try {
            $this->checkoutManager->retrySlotRenewalAsOwner($chargeModel, $actorUserId);
        } catch (SlotRenewalChargeNotResumableException) {
            return redirect()->back()->with('flash_error', 'This renewal charge cannot be retried right now.');
        }

        return redirect()
            ->route('customer.workspaces.additional-business-slots.show', ['workspaceUid' => $workspaceUid])
            ->with('flash_success', 'Renewal retried.');
    }

    public function requestCancellation(CancelSlotAgreementRequest $request, string $workspaceUid, int $agreement): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $workspace = $this->resolveOwnedWorkspace($workspaceUid, $actorUserId);
        $agreementModel = $this->resolveWorkspaceAgreement($workspace, $agreement);

        $this->checkoutManager->requestSlotAgreementCancellation($agreementModel, $actorUserId);

        return redirect()
            ->route('customer.workspaces.additional-business-slots.show', ['workspaceUid' => $workspaceUid])
            ->with('flash_success', 'Cancellation requested.');
    }

    private function resolveOwnedWorkspace(string $workspaceUid, int $actorUserId): Workspace
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null || (int) $workspace->owner_user_id !== $actorUserId) {
            abort(404);
        }

        return $workspace;
    }

    private function resolveWorkspaceAgreement(Workspace $workspace, int $agreementId): AdditionalBusinessSlotAgreement
    {
        $agreement = $this->agreementRepository->findById($agreementId);

        if ($agreement === null || (int) $agreement->workspace_id !== $workspace->id) {
            abort(404);
        }

        return $agreement;
    }
}
