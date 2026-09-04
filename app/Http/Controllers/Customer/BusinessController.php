<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceAccessDeniedException;
use App\Http\Requests\Business\UpdateBusinessRequest;
use App\Library\Business\BusinessManager;
use App\Models\Customer;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class BusinessController extends CustomerBaseController
{
    public function __construct(
        private readonly BusinessManager $businessManager,
        private readonly BusinessRepository $businessRepository,
    ) {
    }

    /**
     * V1 resolves the primary business; if none exists, redirect to onboarding (RFC-001 §19).
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $business = $this->businessRepository->findPrimaryByCustomer($this->customer()->user_id);

        if ($business === null) {
            return redirect()->route('customer.onboarding.show');
        }

        return view('customer.business.edit', compact('business'));
    }

    public function update(UpdateBusinessRequest $request): RedirectResponse
    {
        $customer = $this->customer();
        $business = $this->businessRepository->findPrimaryByCustomer($customer->user_id);

        if ($business === null) {
            return redirect()->route('customer.onboarding.show');
        }

        try {
            $this->businessManager->updateOwnBusinessProfile($customer, $business, $request->validated());
        } catch (WorkspaceAccessDeniedException|BusinessWorkspaceMismatchException) {
            abort(404);
        } catch (InvalidArgumentException) {
            return redirect()
                ->route('customer.business.edit')
                ->withInput()
                ->withErrors(['business' => 'We could not save your changes. Please check your entries and try again.']);
        }

        return redirect()->route('customer.business.edit')->with([
            'status' => 'success',
            'message' => 'Business updated successfully.',
        ]);
    }

    private function customer(): Customer
    {
        return Auth::user()->customer;
    }
}
