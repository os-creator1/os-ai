<?php

namespace App\Http\Controllers\Customer\Business;

use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Customer\Business\InitiateTopUpRequest;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\Business;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use App\Library\Workspace\WorkspaceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * RFC-005 M3 contract §18 — manual top-up initiation. Never credits the
 * wallet directly from this controller — UsageBillingCheckoutManager only
 * credits after authoritative provider confirmation.
 *
 * RFC-005 Funding Provider-Flow Correction Contract §6 — a successful
 * Checkout-backed initiation now redirects to Stripe's own hosted Checkout
 * URL instead of completing synchronously; confirmFromReturn() is the
 * dedicated, Business-isolated return-confirmation action.
 */
class UsageBillingTopUpController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly UsageBillingCheckoutManager $checkoutManager,
        private readonly BusinessFundingAttemptRepository $attemptRepository,
    ) {
    }

    public function initiate(InitiateTopUpRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        try {
            $result = $this->checkoutManager->initiateTopUp($business, $actorUserId, (int) $request->validated('amount_micro'));
        } catch (UnauthorizedPayerAssignmentException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to initiate a top-up for this Business.');
        } catch (UsageWalletNotFoundException) {
            return redirect()->back()->with('flash_error', 'Usage tracking has not been set up for this Business yet.');
        }

        if ($result->denialReason === 'no_payment_instrument') {
            return redirect()->back()->with('flash_error', 'Set up a payment method before initiating a top-up.');
        }

        if ($result->denialReason !== null) {
            return redirect()->back()->with('flash_error', 'Top-up could not be started.');
        }

        if ($result->redirectUrl !== null) {
            return redirect()->away($result->redirectUrl);
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Top-up initiated.');
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §6 — the browser
     * return from the hosted Checkout page. Never trusts the return alone
     * — confirmAttemptFromReturn() independently re-fetches and verifies
     * the Checkout Session before crediting. Business-isolated: an
     * attempt actually belonging to a different Business 404s before
     * confirmAttemptFromReturn() is ever called.
     */
    public function confirmFromReturn(string $workspaceUid, string $businessUid, int $attempt): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        $fundingAttempt = $this->attemptRepository->findById($attempt);

        if ($fundingAttempt === null || (int) $fundingAttempt->business_id !== (int) $business->id) {
            abort(404);
        }

        $result = $this->checkoutManager->confirmAttemptFromReturn($fundingAttempt);

        if ($result->denialReason !== null) {
            return redirect()
                ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
                ->with('flash_error', 'Top-up could not be confirmed.');
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Top-up confirmed.');
    }

    private function resolveViewableBusiness(string $workspaceUid, string $businessUid, int $userId): Business
    {
        $workspace = $this->workspaceRepository->findByUid($workspaceUid);

        if ($workspace === null) {
            abort(404);
        }

        $business = $this->workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
            abort(404);
        }

        return $business;
    }
}
