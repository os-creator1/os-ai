<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\FeatureLimitExceedsPlatformSafetyLimitException;
use App\Exceptions\Usage\InvalidBillingContactDataException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Customer\Business\UpdateBusinessBillingContactRequest;
use App\Http\Requests\Customer\Business\UpdateBusinessFeatureLimitRequest;
use App\Http\Requests\Customer\Business\UpdateBusinessPayerRequest;
use App\Http\Requests\Customer\Business\UpdateBusinessSpendCapRequest;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageBillingPresenter;
use App\Library\Usage\UsageWalletManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * RFC-005 M2 contract §9 — the customer-visible Usage & Billing dashboard,
 * redesigned by Customer Experience Slice 5 (contract §12, §17; brief §5).
 * Resolves the target by uid via repository lookups (never implicit
 * route-model binding), abort(404) — never 403 — on any not-found or
 * scope-mismatch condition, delegates every mutation to
 * BillingProfileManager/UsageWalletManager, and never queries a table
 * directly for accounting/authorization state: the presenter owns the
 * accounting read model and the two managers own every presentation
 * fact this controller adds (billing responsibility, Workspace controls,
 * the curated capability catalogue).
 */
class UsageBillingController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly UsageBillingPresenter $presenter,
        private readonly UsageWalletManager $walletManager,
        private readonly BillingProfileManager $billingProfileManager,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        $viewModel = $this->presenter->buildDashboardViewModel($business);
        $responsibility = $this->billingProfileManager->billingResponsibilityFor($business, $actorUserId);

        $business->loadMissing('workspace');
        $workspaceControls = $responsibility['actor_manages_responsibility'] || $responsibility['actor_is_workspace_owner']
            ? $this->walletManager->workspaceControls($business->workspace, $viewModel->wallet['spend_period_key'] ?? null)
            : null;

        $capabilities = [];

        foreach ($this->walletManager->customerCapabilityCatalog() as $featureKey) {
            $capabilities[$featureKey] = [
                'label' => $this->walletManager->capabilityLabel($featureKey),
                'help' => $this->walletManager->capabilityHelp($featureKey),
            ];
        }

        $wallet = $this->walletManager;

        return view('customer.business.usage-billing.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'dashboard' => $viewModel,
            'responsibility' => $responsibility,
            'workspaceControls' => $workspaceControls,
            'capabilities' => $capabilities,
            'capabilityLabel' => fn (?string $featureKey): string => $wallet->capabilityLabel($featureKey),
            'minimumTopUpMicro' => (string) UsageWalletManager::MINIMUM_MANUAL_TOP_UP_MICRO,
            'autoRechargePresetsMicro' => array_map('strval', UsageWalletManager::AUTO_RECHARGE_PRESETS_MICRO),
            'paidActivityPaused' => $viewModel->wallet !== null && $this->paidActivityPausedFor($business),
        ]);
    }

    /**
     * Customer Experience Slice 5 (contract §12.4, §18 S-7; T-PAYER-2/3) —
     * billing responsibility is changed by the Agency owner/admin only,
     * and submitting the current payer is a true no-op: nothing is
     * written, no event fires, and the message says so. The selector
     * itself no longer lives on this page (contract §12.4: Client Accounts
     * → [Business] → Billing responsibility); the endpoint remains the
     * authorized backend for that control.
     */
    public function updatePayer(UpdateBusinessPayerRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        $payerType = PayerType::from($request->validated('payer_type'));

        try {
            $outcome = $this->billingProfileManager->assignPayer($business, $payerType, $actorUserId, 'Changed via Usage & Billing.');
        } catch (UnauthorizedPayerAssignmentException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.payer_not_authorized'));
        }

        $payerLabel = $this->payerLabel($outcome['to']);

        if (! $outcome['changed']) {
            return redirect()
                ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
                ->with('flash_info', __('locale.usage_billing.messages.payer_unchanged', ['payer' => $payerLabel]));
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', __('locale.usage_billing.messages.payer_changed', ['payer' => $payerLabel]));
    }

    public function updateBillingContact(UpdateBusinessBillingContactRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        try {
            $this->billingProfileManager->updateBillingContact(
                $business,
                $request->validated('contact_user_id') !== null ? (int) $request->validated('contact_user_id') : null,
                $request->validated('contact_name'),
                $request->validated('contact_email'),
                (bool) $request->boolean('notification_opt_in', true),
                $actorUserId,
            );
        } catch (UnauthorizedUsageBillingManagementException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.not_authorized_billing_contact'));
        } catch (InvalidBillingContactDataException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.billing_contact_invalid'));
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', __('locale.usage_billing.messages.billing_contact_updated'));
    }

    /**
     * Customer Experience Slice 5 — the spending-controls form: the
     * Business monthly limit, the Business emergency stop ("Pause paid
     * activity"), and — for the Workspace owner / Agency-wide Admin only —
     * the Agency-wide monthly limit, Agency-wide automatic top-up ceiling
     * and Agency-wide stop. Every branch is authorized inside the manager;
     * a Business user posting the Workspace branch is refused there.
     */
    public function updateSpendCap(UpdateBusinessSpendCapRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);
        $control = $request->control();

        try {
            if ($control === UpdateBusinessSpendCapRequest::CONTROL_BUSINESS_PAUSE) {
                $paused = $request->boolean('paused');

                if ($paused && ! $request->boolean('confirm_pause')) {
                    return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.pause_needs_confirmation'));
                }

                if ($paused) {
                    $this->walletManager->pausePaidActivity($business, $actorUserId, 'Paused via Usage & Billing.');
                    $message = __('locale.usage_billing.messages.paid_activity_paused');
                } else {
                    $this->walletManager->resumePaidActivity($business, $actorUserId, 'Resumed via Usage & Billing.');
                    $message = __('locale.usage_billing.messages.paid_activity_resumed');
                }
            } elseif ($control === UpdateBusinessSpendCapRequest::CONTROL_WORKSPACE) {
                $business->loadMissing('workspace');
                $workspace = $business->workspace;

                $spendCap = $request->validated('workspace_monthly_spend_cap_micro');
                $rechargeCap = $request->validated('workspace_monthly_recharge_cap_micro');

                $this->walletManager->setWorkspaceAggregateSpendCap($workspace, $spendCap !== null ? (string) $spendCap : null, $actorUserId, 'Changed via Usage & Billing.');
                $this->walletManager->setWorkspaceAggregateRechargeCap($workspace, $rechargeCap !== null ? (string) $rechargeCap : null, $actorUserId, 'Changed via Usage & Billing.');

                if ($request->has('workspace_paused')) {
                    if ($request->boolean('workspace_paused')) {
                        $this->walletManager->pauseWorkspacePaidActivity($workspace, $actorUserId, 'Paused via Usage & Billing.');
                    } else {
                        $this->walletManager->resumeWorkspacePaidActivity($workspace, $actorUserId, 'Resumed via Usage & Billing.');
                    }
                }

                $message = __('locale.usage_billing.messages.workspace_controls_updated');
            } else {
                $capMicro = $request->validated('monthly_spend_cap_micro');
                $this->walletManager->setSpendCap($business, $capMicro !== null ? (string) $capMicro : null, $actorUserId, 'Changed via Usage & Billing.');
                $message = __('locale.usage_billing.messages.spend_cap_updated');
            }
        } catch (UnauthorizedUsageBillingManagementException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.not_authorized_spending_controls'));
        } catch (UsageWalletNotFoundException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.wallet_not_set_up'));
        } catch (\InvalidArgumentException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.invalid_amount'));
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', $message);
    }

    /**
     * Customer Experience Slice 5 (E-14; brief §11) — the capability is a
     * route parameter chosen from the curated catalogue; anything else —
     * an unknown key, a Planned feature, a Workspace-scoped feature — is
     * 404, indistinguishable from a missing page, never an oracle.
     */
    public function updateFeatureLimit(UpdateBusinessFeatureLimitRequest $request, string $workspaceUid, string $businessUid, string $featureKey): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        if (! $this->walletManager->isCustomerLimitableCapability($featureKey)) {
            abort(404);
        }

        $limitMicro = $request->validated('monthly_limit_micro');

        try {
            $this->walletManager->setFeatureLimit($business, $featureKey, $limitMicro !== null ? (string) $limitMicro : null, $actorUserId, 'Changed via Usage & Billing.');
        } catch (UnauthorizedUsageBillingManagementException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.not_authorized_spending_controls'));
        } catch (FeatureLimitExceedsPlatformSafetyLimitException) {
            return redirect()->back()->with('flash_error', __('locale.usage_billing.messages.feature_limit_above_platform'));
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', __('locale.usage_billing.messages.feature_limit_updated', ['capability' => $this->walletManager->capabilityLabel($featureKey)]));
    }

    private function payerLabel(PayerType $payerType): string
    {
        return $payerType === PayerType::Workspace
            ? __('locale.usage_billing.responsibility.agency_short')
            : __('locale.usage_billing.responsibility.business_short');
    }

    private function paidActivityPausedFor(Business $business): bool
    {
        $wallet = app(\App\Repositories\Contracts\BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);

        return $wallet !== null && $wallet->paid_activity_paused_at !== null;
    }

    /**
     * Resolves the target Business scoped to the named Workspace, uid
     * addressability only (mirroring WorkspaceController::resolveWorkspaceBusiness()
     * exactly), then applies WorkspaceManager::userCanAccessBusiness() as
     * the sole view-authorization decision — owner, direct Business
     * owner/customer, or any active member (Admin or Staff) whose scope
     * covers this Business. An unknown Workspace/Business uid, or a
     * Business this actor cannot view, both fail closed identically with
     * 404 (M2 contract §7) — this route can never be used to probe for
     * existence.
     */
    private function resolveViewableBusiness(string $workspaceUid, string $businessUid, int $userId): Business
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)
            ->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
            abort(404);
        }

        return $business;
    }
}
