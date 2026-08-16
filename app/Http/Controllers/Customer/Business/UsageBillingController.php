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
use App\Library\Entitlement\PlatformFeatureRegistry;
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
 * RFC-005 M2 contract §9 — the customer-visible Usage & Billing dashboard.
 * Resolves the target by uid via repository lookups (never implicit
 * route-model binding), abort(404) — never 403 — on any not-found or
 * scope-mismatch condition, delegates every mutation to
 * BillingProfileManager/UsageWalletManager. Never queries a repository or
 * table directly for accounting/authorization state; the presenter owns
 * every read this controller displays.
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
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, (int) Auth::id());

        $viewModel = $this->presenter->buildDashboardViewModel($business);

        return view('customer.business.usage-billing.show', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'dashboard' => $viewModel,
        ]);
    }

    public function updatePayer(UpdateBusinessPayerRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        $payerType = PayerType::from($request->validated('payer_type'));

        try {
            $this->billingProfileManager->changePayer($business, $payerType, $actorUserId, 'Changed via Usage & Billing dashboard.');
        } catch (UnauthorizedPayerAssignmentException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to set this Business\'s payer.');
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Payer updated.');
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
            return redirect()->back()->with('flash_error', 'You are not authorized to change this Business\'s billing contact.');
        } catch (InvalidBillingContactDataException) {
            return redirect()->back()->with('flash_error', 'Provide either an existing user or both a contact name and email.');
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Billing contact updated.');
    }

    public function updateSpendCap(UpdateBusinessSpendCapRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        $capMicro = $request->validated('monthly_spend_cap_micro');

        try {
            $this->walletManager->setSpendCap($business, $capMicro !== null ? (string) $capMicro : null, $actorUserId, 'Changed via Usage & Billing dashboard.');
        } catch (UnauthorizedUsageBillingManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to change this Business\'s spend cap.');
        } catch (UsageWalletNotFoundException) {
            return redirect()->back()->with('flash_error', 'Usage tracking has not been set up for this Business yet.');
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Spend cap updated.');
    }

    public function updateFeatureLimit(UpdateBusinessFeatureLimitRequest $request, string $workspaceUid, string $businessUid, string $featureKey): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        if (! PlatformFeatureRegistry::isAvailable($featureKey)) {
            abort(404);
        }

        $limitMicro = $request->validated('monthly_limit_micro');

        try {
            $this->walletManager->setFeatureLimit($business, $featureKey, $limitMicro !== null ? (string) $limitMicro : null, $actorUserId, 'Changed via Usage & Billing dashboard.');
        } catch (UnauthorizedUsageBillingManagementException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to change this Business\'s feature limits.');
        } catch (FeatureLimitExceedsPlatformSafetyLimitException) {
            return redirect()->back()->with('flash_error', 'This limit exceeds the platform safety limit for this feature.');
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Feature limit updated.');
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
