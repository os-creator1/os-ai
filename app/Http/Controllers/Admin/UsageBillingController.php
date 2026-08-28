<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Usage\BillingStatusTransitionSource;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Enums\Usage\WalletBillingStatus;
use App\Exceptions\Usage\FundingAttemptNotResumableException;
use App\Exceptions\Usage\InvalidAdminCreditAmountException;
use App\Exceptions\Usage\InvalidAdminCreditEntryTypeException;
use App\Exceptions\Usage\InvalidAdminCreditOperationIdException;
use App\Exceptions\Usage\InvalidAdminCreditReasonException;
use App\Exceptions\Usage\ManualCreditOperationConflictException;
use App\Exceptions\Usage\UnauthorizedSlotAgreementActionException;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Http\Requests\Admin\IssueManualWalletCreditRequest;
use App\Http\Requests\Admin\ResumeBusinessWalletBillingRequest;
use App\Http\Requests\Admin\RetryFundingAttemptAsAdministratorRequest;
use App\Http\Requests\Admin\SetPlatformFeatureUsageSafetyLimitRequest;
use App\Http\Requests\Admin\SuspendBusinessWalletBillingRequest;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Repositories\Contracts\BusinessFeatureUsageLimitRepository;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use App\Repositories\Contracts\BusinessUsageLimitTransitionRepository;
use App\Repositories\Contracts\BusinessUsageWalletBillingStatusTransitionRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * RFC-005 Admin Usage Billing Surface Contract §2.1 — the one unified
 * controller covering every capability this contract builds: view any
 * Business's wallet balance/ledger/limits/margin, issue an auditable
 * manual/promotional credit, suspend/resume billing_status, resume a
 * stuck funding attempt, and configure the platform feature-usage
 * safety limit. Never originates a fresh charge, never enables auto-
 * recharge, never touches the payment-instrument layer at all, never
 * queries a billing table directly — every read and every write goes
 * through the repositories/managers named in §2.1's own table. Does not
 * duplicate the existing provider-event or additional-slot-agreement
 * admin surfaces — §2.7's own navigation integration is link-only.
 */
class UsageBillingController extends AdminBaseController
{
    public function __construct(
        private readonly UsageWalletManager $walletManager,
        private readonly UsageBillingCheckoutManager $checkoutManager,
        private readonly BusinessUsageWalletRepository $walletRepository,
        private readonly BusinessFeatureUsageLimitRepository $featureLimitRepository,
        private readonly BusinessUsageLedgerEntryRepository $ledgerRepository,
        private readonly BusinessFundingAttemptRepository $fundingAttemptRepository,
        private readonly BusinessUsageWalletBillingStatusTransitionRepository $billingStatusTransitionRepository,
        private readonly BusinessUsageLimitTransitionRepository $limitTransitionRepository,
        private readonly PlatformFeatureUsageSafetyLimitRepository $safetyLimitRepository,
    ) {
    }

    public function show(Request $request, Business $business): View
    {
        $periodKey = (string) $request->query('period', now()->format('Y-m'));

        return view('admin.usage-billing.businesses.show', [
            'business' => $business,
            'wallet' => $this->walletRepository->findByBusinessId((int) $business->id),
            'featureLimits' => $this->featureLimitRepository->forBusiness((int) $business->id),
            'ledgerEntries' => $this->ledgerRepository->forBusinessPaginated(
                (int) $business->id,
                25,
                [
                    'entry_type' => $request->query('entry_type'),
                    'from' => $request->query('from'),
                    'to' => $request->query('to'),
                ],
            )->appends($request->query()),
            'marginAggregate' => $this->ledgerRepository->marginAggregateForBusiness((int) $business->id, $periodKey),
            'periodKey' => $periodKey,
            'recentFundingAttempts' => $this->fundingAttemptRepository->recentForBusiness((int) $business->id),
            'billingStatusHistory' => $this->billingStatusTransitionRepository->recentForBusiness((int) $business->id),
            'limitHistory' => $this->limitTransitionRepository->recentForBusiness((int) $business->id),
            'operationId' => (string) Str::uuid(),
            'breadcrumbs' => $this->breadcrumbs($business),
        ]);
    }

    public function issueManualCredit(IssueManualWalletCreditRequest $request, Business $business): RedirectResponse
    {
        $entryType = UsageLedgerEntryType::from((string) $request->validated('entry_type'));

        try {
            $this->walletManager->issueManualCredit(
                $business,
                $entryType,
                (int) $request->validated('amount_micro'),
                (int) Auth::id(),
                (string) $request->validated('reason'),
                (string) $request->validated('operation_id'),
            );
        } catch (UnauthorizedUsageBillingManagementException|InvalidAdminCreditEntryTypeException|InvalidAdminCreditAmountException|InvalidAdminCreditReasonException|InvalidAdminCreditOperationIdException|ManualCreditOperationConflictException|UsageWalletNotFoundException) {
            return redirect()->back()->with('flash_error', 'This manual credit could not be issued.');
        }

        return redirect()
            ->route('admin.businesses.usage-billing.show', $business)
            ->with('flash_success', 'Manual credit issued.');
    }

    public function suspendBilling(SuspendBusinessWalletBillingRequest $request, Business $business): RedirectResponse
    {
        try {
            $this->walletManager->setBillingStatus(
                $business,
                WalletBillingStatus::Suspended,
                BillingStatusTransitionSource::AdminAction,
                (int) Auth::id(),
                (string) $request->validated('reason'),
            );
        } catch (UnauthorizedUsageBillingManagementException|UsageWalletNotFoundException) {
            return redirect()->back()->with('flash_error', 'This wallet could not be suspended.');
        }

        return redirect()
            ->route('admin.businesses.usage-billing.show', $business)
            ->with('flash_success', 'Wallet billing suspended.');
    }

    public function resumeBilling(ResumeBusinessWalletBillingRequest $request, Business $business): RedirectResponse
    {
        try {
            $this->walletManager->setBillingStatus(
                $business,
                WalletBillingStatus::Active,
                BillingStatusTransitionSource::AdminAction,
                (int) Auth::id(),
                (string) $request->validated('reason'),
            );
        } catch (UnauthorizedUsageBillingManagementException|UsageWalletNotFoundException) {
            return redirect()->back()->with('flash_error', 'This wallet could not be resumed.');
        }

        return redirect()
            ->route('admin.businesses.usage-billing.show', $business)
            ->with('flash_success', 'Wallet billing resumed.');
    }

    public function retryFundingAttempt(RetryFundingAttemptAsAdministratorRequest $request, Business $business, int $attempt): RedirectResponse
    {
        $attemptModel = $this->fundingAttemptRepository->findById($attempt);

        if ($attemptModel === null) {
            abort(404);
        }

        // RFC-005 Admin Usage Billing Surface Contract §2.1.2 — explicit
        // controller-level cross-business guard: retryFundingAttemptAsAdministrator()
        // receives only the attempt, never the Business, so it cannot
        // validate this itself. Executed before the manager or the
        // payment-provider gateway is ever reached.
        if ((int) $attemptModel->business_id !== (int) $business->id) {
            abort(404);
        }

        try {
            $this->checkoutManager->retryFundingAttemptAsAdministrator($attemptModel, (int) Auth::id(), (string) $request->validated('reason'));
        } catch (UnauthorizedSlotAgreementActionException|FundingAttemptNotResumableException) {
            return redirect()->back()->with('flash_error', 'This funding attempt cannot be retried right now.');
        }

        return redirect()
            ->route('admin.businesses.usage-billing.show', $business)
            ->with('flash_success', 'Funding attempt retried.');
    }

    public function safetyLimits(): View
    {
        return view('admin.usage-billing.safety-limits.index', [
            'safetyLimits' => $this->safetyLimitRepository->all(),
            'history' => $this->limitTransitionRepository->recentPlatformSafetyLimitHistory(),
            'breadcrumbs' => [
                ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => 'Usage Billing Safety Limits'],
            ],
        ]);
    }

    public function setSafetyLimit(SetPlatformFeatureUsageSafetyLimitRequest $request): RedirectResponse
    {
        try {
            $this->walletManager->setSafetyLimit(
                (string) $request->validated('feature_key'),
                (string) $request->validated('max_monthly_limit_micro'),
                (int) Auth::id(),
                (string) $request->validated('reason'),
            );
        } catch (UnauthorizedUsageBillingManagementException) {
            return redirect()->back()->with('flash_error', 'This platform safety limit could not be set.');
        }

        return redirect()
            ->route('admin.usage-billing.safety-limits.index')
            ->with('flash_success', 'Platform feature-usage safety limit set.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(Business $business): array
    {
        return [
            ['link' => url(config('app.admin_path') . '/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.businesses.index'), 'name' => 'Businesses'],
            ['link' => route('admin.businesses.show', $business), 'name' => $business->name],
            ['name' => 'Usage Billing'],
        ];
    }
}
