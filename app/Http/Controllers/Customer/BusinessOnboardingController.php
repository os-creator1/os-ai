<?php

namespace App\Http\Controllers\Customer;

use App\Enums\Business\OnboardingStep;
use App\Http\Requests\Business\SyncBusinessServicesRequest;
use App\Http\Requests\Business\UpdateBusinessAssetsRequest;
use App\Http\Requests\Business\UpdateOnboardingGoalsRequest;
use App\Http\Requests\Business\UpsertBusinessIdentityRequest;
use App\Http\Requests\Business\UpsertBusinessLocationRequest;
use App\Library\Business\OnboardingManager;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Thin wizard controller. Every step transition and invariant is enforced by
 * OnboardingManager/BusinessManager (Milestones 2–3) — this class only
 * resolves the tenant, validates input via dedicated Form Requests, and maps
 * outcomes to redirects/views.
 */
class BusinessOnboardingController extends CustomerBaseController
{
    public function __construct(private readonly OnboardingManager $onboarding)
    {
    }

    public function show(Request $request, ?string $step = null): View|RedirectResponse
    {
        $onboarding = $this->currentOnboarding();
        $requested = $step !== null ? OnboardingStep::tryFrom($step) : null;

        if ($step !== null && $requested === null) {
            return redirect()->route('customer.onboarding.show');
        }

        $resolved = $this->onboarding->resolveStep($onboarding, $requested);

        // A requested step beyond what's allowed gets clamped by resolveStep();
        // redirecting to the clamped step (rather than silently rendering it)
        // is what stops route manipulation from skipping required steps.
        if ($requested !== null && $requested !== $resolved) {
            return redirect()->route('customer.onboarding.show', ['step' => $resolved->value]);
        }

        return view('customer.onboarding.show', [
            'onboarding' => $onboarding,
            'step' => $resolved,
        ]);
    }

    public function storeGoals(UpdateOnboardingGoalsRequest $request): RedirectResponse
    {
        return $this->saveStep(
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveGoalsStep(
                $onboarding,
                $customer,
                $request->validated('primary_goals')
            )
        );
    }

    public function storeBusiness(UpsertBusinessIdentityRequest $request): RedirectResponse
    {
        return $this->saveStep(
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveBusinessStep(
                $onboarding,
                $customer,
                $request->validated()
            )
        );
    }

    public function storeLocation(UpsertBusinessLocationRequest $request): RedirectResponse
    {
        return $this->saveStep(
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveLocationStep(
                $onboarding,
                $customer,
                $request->validated()
            )
        );
    }

    public function storeServices(SyncBusinessServicesRequest $request): RedirectResponse
    {
        return $this->saveStep(
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveServicesStep(
                $onboarding,
                $customer,
                $request->validated('services')
            )
        );
    }

    public function storeAssets(UpdateBusinessAssetsRequest $request): RedirectResponse
    {
        return $this->saveStep(
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveAssetsStep(
                $onboarding,
                $customer,
                $request->validated()
            )
        );
    }

    /**
     * The only step the RFC permits skipping without data (RFC-001 §23).
     * OnboardingManager::skipStep() itself rejects any other step.
     */
    public function skipAssets(): RedirectResponse
    {
        return $this->saveStep(
            fn (CustomerOnboarding $onboarding) => $this->onboarding->skipStep($onboarding, OnboardingStep::Assets)
        );
    }

    /**
     * Run a step-save action and redirect to wherever onboarding ends up.
     * A step-order or data-normalization failure (InvalidArgumentException)
     * redirects back to the current step with the submitted input preserved,
     * instead of a raw 500 — ownership failures (AuthorizationException)
     * are left to propagate as a 403 via the framework's exception handler.
     */
    private function saveStep(Closure $action): RedirectResponse
    {
        $onboarding = $this->currentOnboarding();
        $customer = $this->customer();

        try {
            $onboarding = $action($onboarding, $customer);
        } catch (InvalidArgumentException) {
            return redirect()
                ->route('customer.onboarding.show', ['step' => $this->onboarding->resolveStep($onboarding)->value])
                ->withInput()
                ->withErrors(['onboarding' => 'We could not save that step. Please check your entries and try again.']);
        }

        return redirect()->route('customer.onboarding.show', ['step' => $onboarding->current_step->value]);
    }

    private function customer(): Customer
    {
        return Auth::user()->customer;
    }

    /**
     * Lazily starts a voluntary (non-required) onboarding row the first time
     * a customer visits, per RFC-001 §29 — never forces existing customers,
     * and startForCustomer() is idempotent so this never duplicates a row.
     */
    private function currentOnboarding(): CustomerOnboarding
    {
        return $this->onboarding->start($this->customer());
    }
}
