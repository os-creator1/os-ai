<?php

namespace App\Http\Controllers\Customer;

use App\Enums\Business\OnboardingStep;
use App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\InactiveWorkspacePlanException;
use App\Exceptions\Entitlement\SuspendedWorkspacePlanException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Http\Requests\Business\OnboardingBusinessRequest;
use App\Http\Requests\Business\OnboardingLocationRequest;
use App\Http\Requests\Business\OnboardingServicesRequest;
use App\Http\Requests\Business\OnboardingWebsiteRequest;
use App\Library\Business\OnboardingManager;
use App\Library\Navigation\CustomerContextPreference;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\WorkspaceRepository;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Thin wizard controller for the five customer-facing steps
 * (Business → Location → Services → Website → Finish). Every step
 * transition and invariant is enforced by OnboardingManager/BusinessManager —
 * this class only resolves the tenant, validates input via dedicated Form
 * Requests, and maps outcomes to redirects/views.
 *
 * The routes of the removed steps (goals, assets, analysis, results, and the
 * services "skip") still exist so an old bookmark or a stale form lands
 * somewhere sensible, but they write nothing: retired() sends the customer to
 * the step they are really on.
 */
class BusinessOnboardingController extends CustomerBaseController
{
    /**
     * The only string ever surfaced to a customer for an expected RFC-004
     * capacity denial -- never $e->getMessage(), never a Workspace ID, and
     * deliberately not worded to imply the denial will resolve on retry.
     */
    private const CAPACITY_DENIAL_MESSAGE =
        "We can't create your business with the current account setup. Please contact support for help.";

    public function __construct(
        private readonly OnboardingManager $onboarding,
    ) {
    }

    public function show(Request $request, ?string $step = null): View|RedirectResponse
    {
        $onboarding = $this->currentOnboarding();
        $requested = $step !== null ? OnboardingStep::tryFrom($step) : null;

        if ($step !== null && $requested === null) {
            return redirect()->route('customer.onboarding.show');
        }

        $resolved = $this->onboarding->resolveStep($onboarding, $requested);

        // A requested step beyond what's allowed gets clamped by resolveStep(), and a
        // removed legacy step is mapped onto its current equivalent; redirecting to the
        // resolved step (rather than silently rendering it) is what stops route
        // manipulation from skipping required steps.
        if ($requested !== null && $requested !== $resolved) {
            return redirect()->route('customer.onboarding.show', ['step' => $resolved->value]);
        }

        return view('customer.onboarding.show', [
            'onboarding' => $onboarding,
            'step' => $resolved,
            'steps' => OnboardingStep::current(),
            // A step is a link only when the customer has already reached it.
            'reachableSteps' => array_map(
                fn (OnboardingStep $candidate) => $candidate->value,
                array_filter(OnboardingStep::current(), fn (OnboardingStep $candidate) => $this->onboarding->resolveStep($onboarding, $candidate) === $candidate)
            ),
            'review' => $resolved === OnboardingStep::Finish ? $this->onboarding->review($onboarding) : null,
            'websiteChoice' => $resolved === OnboardingStep::Website ? $this->onboarding->websiteChoice($onboarding) : null,
            'managedWebsite' => $resolved === OnboardingStep::Website && $onboarding->business_id !== null
                && \App\Models\Website::query()->where('business_id', $onboarding->business_id)->exists(),
        ]);
    }

    public function storeBusiness(OnboardingBusinessRequest $request): RedirectResponse
    {
        return $this->saveStep(
            OnboardingStep::Business,
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveBusinessStep(
                $onboarding,
                $customer,
                $request->validated()
            )
        );
    }

    public function storeLocation(OnboardingLocationRequest $request): RedirectResponse
    {
        return $this->saveStep(
            OnboardingStep::Location,
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveLocationStep(
                $onboarding,
                $customer,
                $request->validated()
            )
        );
    }

    public function storeServices(OnboardingServicesRequest $request): RedirectResponse
    {
        return $this->saveStep(
            OnboardingStep::Services,
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveServicesStep(
                $onboarding,
                $customer,
                $request->validated('services') ?? []
            )
        );
    }

    public function storeWebsite(OnboardingWebsiteRequest $request): RedirectResponse
    {
        return $this->saveStep(
            OnboardingStep::Website,
            fn (CustomerOnboarding $onboarding, Customer $customer) => $this->onboarding->saveWebsiteStep(
                $onboarding,
                $customer,
                $request->validated('website_choice'),
                $request->validated('website_url')
            )
        );
    }

    /**
     * A removed step's old URL or a stale form POST. Writes nothing and never
     * skips a prerequisite: it only sends the customer to the step they are on.
     */
    public function retired(): RedirectResponse
    {
        return redirect()->route('customer.onboarding.show', [
            'step' => $this->onboarding->resolveStep($this->currentOnboarding())->value,
        ]);
    }

    public function complete(): RedirectResponse
    {
        $onboarding = $this->currentOnboarding();
        $customer = $this->customer();

        try {
            $completed = $this->onboarding->complete($onboarding, $customer);
        } catch (InvalidArgumentException) {
            // Send the customer to the first step whose saved information is missing.
            return redirect()
                ->route('customer.onboarding.show', ['step' => $this->onboarding->resolveStep($onboarding->refresh())->value])
                ->withErrors(['onboarding' => 'Some required information is missing. Please finish the highlighted step first.']);
        } catch (WorkspacePlanUnassignedException | InactiveWorkspacePlanException | SuspendedWorkspacePlanException) {
            // No usable paid plan: the completion rolled back and the
            // Business stays Draft.
            return redirect()
                ->route('customer.onboarding.show', ['step' => 'finish'])
                ->withErrors(['onboarding' => self::CAPACITY_DENIAL_MESSAGE]);
        }

        $this->rememberBusinessContext($completed);

        return redirect()->route('user.home');
    }

    /**
     * Run a step-save action and redirect to the step after the one just saved.
     * A step-order or data-normalization failure (InvalidArgumentException)
     * redirects back to the current step with the submitted input preserved,
     * instead of a raw 500 — ownership failures (AuthorizationException)
     * are left to propagate as a 403 via the framework's exception handler.
     */
    private function saveStep(OnboardingStep $saved, Closure $action): RedirectResponse
    {
        $onboarding = $this->currentOnboarding();
        $customer = $this->customer();

        try {
            $onboarding = $action($onboarding, $customer);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('customer.onboarding.show', ['step' => $this->onboarding->resolveStep($onboarding, $saved)->value])
                ->withInput()
                ->withErrors(['onboarding' => $this->stepFailureMessage($saved)]);
        } catch (WorkspacePlanUnassignedException
               | InactiveWorkspacePlanException
               | SuspendedWorkspacePlanException
               | BusinessSlotAllocationRequiredException
               | BusinessSlotLimitExceededException) {
            return redirect()
                ->route('customer.onboarding.show', ['step' => $this->onboarding->resolveStep($onboarding, $saved)->value])
                ->withInput()
                ->withErrors(['onboarding' => self::CAPACITY_DENIAL_MESSAGE]);
        }

        // Going back to edit an earlier step continues to the one after it; the
        // furthest step reached stays reachable from the progress bar.
        return redirect()->route('customer.onboarding.show', ['step' => $this->onboarding->nextStep($saved)->value]);
    }

    private function stepFailureMessage(OnboardingStep $step): string
    {
        return match ($step) {
            OnboardingStep::Services => 'We could not save your services. Add at least one service and choose one primary service.',
            OnboardingStep::Website => 'We could not save your website choice. Please check it and try again.',
            default => 'We could not save that step. Please check your entries and try again.',
        };
    }

    /**
     * The activated Business becomes the remembered Business context, so Home
     * opens on it even for an account with several Businesses.
     */
    private function rememberBusinessContext(CustomerOnboarding $onboarding): void
    {
        $business = $onboarding->business()->first();
        $workspace = $business?->workspace_id === null
            ? null
            : app(WorkspaceRepository::class)->findById((int) $business->workspace_id);

        if ($business !== null && $workspace !== null) {
            app(CustomerContextPreference::class)->remember($workspace->uid, $business->uid);
        }
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
