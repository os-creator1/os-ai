<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\Usage\SlotRenewalChargeNotResumableException;
use App\Exceptions\Usage\UnauthorizedSlotAgreementActionException;
use App\Http\Requests\Admin\AllocateSlotAgreementAsAdministratorRequest;
use App\Http\Requests\Admin\CancelSlotAgreementAsAdministratorRequest;
use App\Http\Requests\Admin\RetrySlotRenewalAsAdministratorRequest;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\AdditionalBusinessSlotAgreement;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * M4 contract §17 (§3 item 14) — narrowly scoped to M4's own three admin
 * capabilities: resume/retry, manual allocation, mandatory-reason
 * cancellation. Identical thin-orchestration discipline as the customer
 * surface — every action calls exactly one UsageBillingCheckoutManager
 * method, which itself independently re-verifies platform-administrator
 * identity (never relying solely on this group's own gate/middleware).
 */
class AdditionalBusinessSlotAgreementController extends AdminBaseController
{
    public function __construct(
        private readonly AdditionalBusinessSlotAgreementRepository $agreementRepository,
        private readonly AdditionalBusinessSlotRenewalChargeRepository $renewalChargeRepository,
        private readonly UsageBillingCheckoutManager $checkoutManager,
    ) {
    }

    public function index(): View
    {
        return view('admin.additional-business-slot-agreements.index', [
            'agreements' => $this->agreementRepository->query()->orderByDesc('id')->paginate(25),
        ]);
    }

    public function show(int $agreement): View
    {
        return view('admin.additional-business-slot-agreements.show', [
            'agreement' => $this->resolveAgreement($agreement),
        ]);
    }

    public function retryRenewal(RetrySlotRenewalAsAdministratorRequest $request, int $agreement, int $charge): RedirectResponse
    {
        $this->resolveAgreement($agreement);
        $chargeModel = $this->renewalChargeRepository->findById($charge);

        if ($chargeModel === null || (int) $chargeModel->agreement_id !== $agreement) {
            abort(404);
        }

        try {
            $this->checkoutManager->retrySlotRenewalAsAdministrator($chargeModel, (int) Auth::id(), (string) $request->validated('reason'));
        } catch (SlotRenewalChargeNotResumableException|UnauthorizedSlotAgreementActionException) {
            return redirect()->back()->with('flash_error', 'This renewal charge cannot be retried right now.');
        }

        return redirect()
            ->route('admin.additional-business-slot-agreements.show', $agreement)
            ->with('flash_success', 'Renewal retried.');
    }

    public function allocate(AllocateSlotAgreementAsAdministratorRequest $request, int $agreement): RedirectResponse
    {
        $agreementModel = $this->resolveAgreement($agreement);

        try {
            $this->checkoutManager->allocateSlotAgreementAsAdministrator($agreementModel, (int) Auth::id(), (string) $request->validated('reason'));
        } catch (Throwable) {
            return redirect()->back()->with('flash_error', 'This allocation could not be completed.');
        }

        return redirect()
            ->route('admin.additional-business-slot-agreements.show', $agreement)
            ->with('flash_success', 'Additional slots allocated.');
    }

    public function cancel(CancelSlotAgreementAsAdministratorRequest $request, int $agreement): RedirectResponse
    {
        $agreementModel = $this->resolveAgreement($agreement);

        $this->checkoutManager->requestSlotAgreementCancellation($agreementModel, (int) Auth::id(), (string) $request->validated('reason'));

        return redirect()
            ->route('admin.additional-business-slot-agreements.show', $agreement)
            ->with('flash_success', 'Cancellation requested.');
    }

    private function resolveAgreement(int $agreementId): AdditionalBusinessSlotAgreement
    {
        $agreement = $this->agreementRepository->findById($agreementId);

        if ($agreement === null) {
            abort(404);
        }

        return $agreement;
    }
}
