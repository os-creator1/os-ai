<?php

namespace App\Http\Controllers\Customer\Business;

use App\Exceptions\Usage\ProviderApiUnavailableException;
use App\Exceptions\Usage\ProviderAuthenticationException;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Http\Requests\Customer\Business\CreateSetupIntentRequest;
use App\Library\Usage\PaymentInstrumentManager;
use App\Models\Business;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use App\Library\Workspace\WorkspaceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * RFC-005 M3 contract §18 — payment-method setup (SetupIntent creation,
 * confirmation, detach/replace). Never exposes a secret key; the
 * publishable key/client secret are the only Stripe-related values ever
 * sent to the browser.
 */
class UsageBillingPaymentMethodController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly PaymentInstrumentManager $instrumentManager,
        private readonly BusinessPaymentInstrumentRepository $instrumentRepository,
    ) {
    }

    public function createSetupIntent(CreateSetupIntentRequest $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        try {
            $result = $this->instrumentManager->createSetupIntent($business, $actorUserId);
        } catch (UnauthorizedPayerAssignmentException) {
            return response()->json(['error' => 'You are not authorized to set up a payment method for this Business.'], 403);
        } catch (ProviderAuthenticationException|ProviderApiUnavailableException) {
            return response()->json(['error' => 'Payment provider is currently unavailable.'], 503);
        }

        return response()->json([
            'client_secret' => $result->clientSecret,
            'publishable_key' => config('services.stripe.key'),
        ]);
    }

    public function confirmSetupIntent(CreateSetupIntentRequest $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        $providerSetupIntentId = (string) $request->input('setup_intent');

        try {
            $instrument = $this->instrumentManager->confirmSetupIntentAndAttach($business, $actorUserId, $providerSetupIntentId);
        } catch (UnauthorizedPayerAssignmentException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to set up a payment method for this Business.');
        }

        if ($instrument === null) {
            return redirect()
                ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
                ->with('flash_error', 'Payment method setup was not completed.');
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Payment method added.');
    }

    public function detachInstrument(CreateSetupIntentRequest $request, string $workspaceUid, string $businessUid, int $instrument): RedirectResponse
    {
        $actorUserId = (int) Auth::id();
        $business = $this->resolveViewableBusiness($workspaceUid, $businessUid, $actorUserId);

        $instrumentModel = $this->instrumentRepository->findById($instrument);

        if ($instrumentModel === null) {
            abort(404);
        }

        try {
            $this->instrumentManager->detachInstrument($business, $actorUserId, $instrumentModel);
        } catch (UnauthorizedPayerAssignmentException) {
            return redirect()->back()->with('flash_error', 'You are not authorized to remove this Business\'s payment method.');
        }

        return redirect()
            ->route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])
            ->with('flash_success', 'Payment method removed.');
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
