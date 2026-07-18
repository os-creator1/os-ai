<?php

namespace App\Library\Business;

use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Events\Business\CustomerOnboardingCompleted;
use App\Events\Business\CustomerOnboardingStarted;
use App\Events\Business\CustomerOnboardingStepCompleted;
use App\Events\Business\InitialBusinessAnalysisRequested;
use App\Jobs\Business\BuildInitialBusinessSnapshot;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates the onboarding state machine on top of the Milestone 1
 * CustomerOnboardingRepository (pure data access, no step-order awareness)
 * and Milestone 2 BusinessManager (business identity orchestration). Holds
 * the step-order rules the repository deliberately does not enforce.
 */
class OnboardingManager
{
    /**
     * Fixed step order (RFC-001 §22). Index position is used for all
     * "ahead of / behind" comparisons.
     */
    private const STEP_ORDER = [
        OnboardingStep::Goals,
        OnboardingStep::Business,
        OnboardingStep::Location,
        OnboardingStep::Services,
        OnboardingStep::Assets,
        OnboardingStep::Analysis,
        OnboardingStep::Results,
        OnboardingStep::Complete,
    ];

    /**
     * Steps that may be completed with no submitted data (RFC-001 §23: "Only
     * assets can be skipped").
     */
    private const SKIPPABLE_STEPS = [
        OnboardingStep::Assets,
    ];

    public function __construct(
        private readonly CustomerOnboardingRepository $onboardingRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly BusinessManager $businessManager,
    ) {
    }

    /**
     * Start onboarding for a customer, or return their existing row
     * unchanged (startForCustomer() is already idempotent — RFC-001 §10.4).
     * Dispatches CustomerOnboardingStarted only on genuine first creation.
     */
    public function start(Customer $customer, bool $required = false): CustomerOnboarding
    {
        $onboarding = $this->onboardingRepository->startForCustomer($customer, $required);

        if ($onboarding->wasRecentlyCreated) {
            CustomerOnboardingStarted::dispatch($onboarding->id, $customer->user_id);
        }

        return $onboarding;
    }

    /**
     * Resolve the step that should actually be shown. A null $requested
     * returns the onboarding's current bookmark. An explicit $requested step
     * behind or at the current step is honored (revisiting); one ahead of it
     * is clamped back — this is what prevents step skipping.
     */
    public function resolveStep(CustomerOnboarding $onboarding, ?OnboardingStep $requested = null): OnboardingStep
    {
        if ($requested === null) {
            return $onboarding->current_step;
        }

        return $this->stepIndex($requested) <= $this->stepIndex($onboarding->current_step)
            ? $requested
            : $onboarding->current_step;
    }

    /**
     * Mark $completed done and advance to $next. Idempotent: re-completing
     * an already-completed step re-saves its data but never regresses
     * current_step, and no event fires unless something genuinely changed.
     *
     * @throws InvalidArgumentException if $completed is beyond the current
     *                                   step, or $next skips more than one step ahead of $completed.
     */
    public function completeStep(CustomerOnboarding $onboarding, OnboardingStep $completed, OnboardingStep $next): CustomerOnboarding
    {
        $this->assertValidStepCompletion($onboarding, $completed, $next);

        $alreadyCompleted = in_array($completed->value, $onboarding->completed_steps ?? [], true);

        $effectiveNext = $this->stepIndex($next) > $this->stepIndex($onboarding->current_step)
            ? $next
            : $onboarding->current_step;
        $advancesCurrentStep = $effectiveNext !== $onboarding->current_step;

        $updated = $this->onboardingRepository->markStepComplete($onboarding, $completed, $effectiveNext);

        if (! $alreadyCompleted || $advancesCurrentStep) {
            CustomerOnboardingStepCompleted::dispatch($updated->id, $completed->value, $effectiveNext->value);
        }

        return $updated;
    }

    /**
     * Complete a step with no submitted data. Only permitted for steps the
     * RFC explicitly allows skipping (currently: assets only).
     *
     * @throws InvalidArgumentException if $step is not skippable.
     */
    public function skipStep(CustomerOnboarding $onboarding, OnboardingStep $step): CustomerOnboarding
    {
        if (! in_array($step, self::SKIPPABLE_STEPS, true)) {
            throw new InvalidArgumentException("Step [{$step->value}] cannot be skipped.");
        }

        return $this->completeStep($onboarding, $step, $this->nextStep($step));
    }

    /**
     * Save the business-identity step: create or update the customer's
     * business through BusinessManager, attach it to the onboarding row if
     * not already attached, and advance the step — all in one transaction,
     * so a BusinessManager failure rolls back both the business write and
     * the onboarding advance, and dispatches neither's events.
     *
     * @throws AuthorizationException if the onboarding row, or the business
     *                                 it already references, does not belong to $customer.
     */
    public function saveBusinessStep(CustomerOnboarding $onboarding, Customer $customer, array $attributes): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return DB::transaction(function () use ($onboarding, $customer, $attributes) {
            $existingBusiness = null;

            if ($onboarding->business_id !== null) {
                $existingBusiness = $this->businessRepository->findOwnedByCustomer($onboarding->business_id, $customer->user_id);

                if ($existingBusiness === null) {
                    throw new AuthorizationException('The onboarding record references a business that does not belong to this customer.');
                }
            }

            $business = $this->businessManager->createOrUpdateOnboardingBusiness($customer, $existingBusiness, $attributes);

            if ($existingBusiness === null) {
                $onboarding = $this->onboardingRepository->attachBusiness($onboarding, $business);
            }

            return $this->completeStep($onboarding, OnboardingStep::Business, OnboardingStep::Location);
        });
    }

    /**
     * Save the goals step (primary_goals) and advance to Business — the one
     * onboarding step with no BusinessManager involvement, since goals live
     * on the onboarding row itself, not the Business aggregate.
     */
    public function saveGoalsStep(CustomerOnboarding $onboarding, Customer $customer, array $goals): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return DB::transaction(function () use ($onboarding, $goals) {
            $onboarding = $this->onboardingRepository->updatePrimaryGoals($onboarding, $goals);

            return $this->completeStep($onboarding, OnboardingStep::Goals, OnboardingStep::Business);
        });
    }

    /**
     * Save the location step through BusinessManager and advance to
     * Services, atomically. Requires the business step to already be done.
     */
    public function saveLocationStep(CustomerOnboarding $onboarding, Customer $customer, array $attributes): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return DB::transaction(function () use ($onboarding, $customer, $attributes) {
            $business = $this->resolveOwnedBusiness($onboarding, $customer);

            $this->businessManager->upsertPrimaryLocation($customer, $business, $attributes);

            return $this->completeStep($onboarding, OnboardingStep::Location, OnboardingStep::Services);
        });
    }

    /**
     * Save the services step through BusinessManager and advance to Assets,
     * atomically. Requires the business step to already be done.
     */
    public function saveServicesStep(CustomerOnboarding $onboarding, Customer $customer, array $services): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return DB::transaction(function () use ($onboarding, $customer, $services) {
            $business = $this->resolveOwnedBusiness($onboarding, $customer);

            $this->businessManager->syncServices($customer, $business, $services);

            return $this->completeStep($onboarding, OnboardingStep::Services, OnboardingStep::Assets);
        });
    }

    /**
     * Save the assets step (public profile URLs, via BusinessManager's
     * identity update) and advance to Analysis, atomically. Requires the
     * business step to already be done.
     */
    public function saveAssetsStep(CustomerOnboarding $onboarding, Customer $customer, array $attributes): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return DB::transaction(function () use ($onboarding, $customer, $attributes) {
            $business = $this->resolveOwnedBusiness($onboarding, $customer);

            $this->businessManager->updateBusiness($customer, $business, $attributes);

            return $this->completeStep($onboarding, OnboardingStep::Assets, OnboardingStep::Analysis);
        });
    }

    /**
     * Request a new analysis run. Increments analysis_version so any
     * already-queued job for an older version safely no-ops when it runs
     * (BuildInitialBusinessSnapshot re-checks the version itself).
     *
     * @throws InvalidArgumentException if the prior onboarding steps (business
     *                                   through assets) aren't done yet.
     */
    public function requestAnalysis(CustomerOnboarding $onboarding, Customer $customer): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        if ($this->stepIndex($onboarding->current_step) < $this->stepIndex(OnboardingStep::Analysis)) {
            throw new InvalidArgumentException('Complete the earlier onboarding steps before requesting analysis.');
        }

        return DB::transaction(function () use ($onboarding) {
            $version = $onboarding->analysis_version + 1;
            $updated = $this->onboardingRepository->startAnalysis($onboarding, $version);

            InitialBusinessAnalysisRequested::dispatch($updated->id, $version);
            BuildInitialBusinessSnapshot::dispatch($updated->id, $version);

            return $updated;
        });
    }

    /**
     * Thin persistence step — OnboardingActionExecutor is responsible for
     * deciding *whether* an action should be recorded (RFC-001 §14); this
     * method just performs the recording once that decision is made.
     */
    public function recordFirstValueAction(CustomerOnboarding $onboarding, Customer $customer, string $actionKey): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        return $this->onboardingRepository->recordFirstValueAction($onboarding, $actionKey);
    }

    /**
     * Idempotent: a repeat call on an already-completed onboarding returns it
     * unchanged and dispatches nothing, so resubmission can never double-fire
     * CustomerOnboardingCompleted.
     *
     * @throws InvalidArgumentException if any completion prerequisite (RFC-001 §32) isn't met.
     */
    public function complete(CustomerOnboarding $onboarding, Customer $customer): CustomerOnboarding
    {
        $this->assertOwnership($customer, $onboarding);

        if ($onboarding->status === OnboardingStatus::Completed) {
            return $onboarding;
        }

        $this->assertCompletionPrerequisites($onboarding, $customer);

        return DB::transaction(function () use ($onboarding, $customer) {
            $updated = $this->onboardingRepository->complete($onboarding);

            CustomerOnboardingCompleted::dispatch($updated->id, $customer->user_id);

            return $updated;
        });
    }

    /**
     * Exact-cardinality checks against ownership-scoped queries on the
     * attached Business — a has-one relation (primaryLocation/primaryService)
     * can only prove "at least one exists", never "exactly one", since
     * nothing stops inconsistent data (e.g. a repository bug, or a direct
     * write outside BusinessManager) from leaving two rows flagged primary.
     *
     * @throws InvalidArgumentException if a prerequisite is missing.
     * @throws AuthorizationException if the attached business doesn't belong to $customer.
     */
    private function assertCompletionPrerequisites(CustomerOnboarding $onboarding, Customer $customer): void
    {
        $business = $this->resolveOwnedBusiness($onboarding, $customer);

        if ($business->locations()->where('is_primary', true)->count() !== 1) {
            throw new InvalidArgumentException('Exactly one primary location is required before onboarding can be completed.');
        }

        if ($business->services()->where('status', BusinessServiceStatus::Active)->count() < 1) {
            throw new InvalidArgumentException('At least one active service is required before onboarding can be completed.');
        }

        if ($business->services()->where('status', BusinessServiceStatus::Active)->where('is_primary', true)->count() !== 1) {
            throw new InvalidArgumentException('Exactly one active primary service is required before onboarding can be completed.');
        }

        if ($onboarding->analysis_payload === null) {
            throw new InvalidArgumentException('The initial analysis must complete before onboarding can be completed.');
        }

        $findings = $onboarding->analysis_payload['findings'] ?? [];

        if ($findings !== [] && $onboarding->first_value_action_key === null) {
            throw new InvalidArgumentException('Complete a first-value action before finishing onboarding.');
        }
    }

    /**
     * @throws InvalidArgumentException if the business step hasn't been completed yet.
     * @throws AuthorizationException if the attached business doesn't belong to $customer.
     */
    private function resolveOwnedBusiness(CustomerOnboarding $onboarding, Customer $customer): Business
    {
        if ($onboarding->business_id === null) {
            throw new InvalidArgumentException('The business step must be completed before this step.');
        }

        $business = $this->businessRepository->findOwnedByCustomer($onboarding->business_id, $customer->user_id);

        if ($business === null) {
            throw new AuthorizationException('The onboarding record references a business that does not belong to this customer.');
        }

        return $business;
    }

    private function assertValidStepCompletion(CustomerOnboarding $onboarding, OnboardingStep $completed, OnboardingStep $next): void
    {
        if ($this->stepIndex($completed) > $this->stepIndex($onboarding->current_step)) {
            throw new InvalidArgumentException("Cannot complete step [{$completed->value}] before its prerequisites.");
        }

        if ($this->stepIndex($next) > $this->stepIndex($completed) + 1) {
            throw new InvalidArgumentException("Cannot advance to step [{$next->value}] from [{$completed->value}].");
        }
    }

    private function nextStep(OnboardingStep $step): OnboardingStep
    {
        $index = min($this->stepIndex($step) + 1, count(self::STEP_ORDER) - 1);

        return self::STEP_ORDER[$index];
    }

    private function stepIndex(OnboardingStep $step): int
    {
        return array_search($step, self::STEP_ORDER, true);
    }

    private function assertOwnership(Customer $customer, CustomerOnboarding $onboarding): void
    {
        if ((int) $onboarding->customer_id !== (int) $customer->user_id) {
            throw new AuthorizationException('This onboarding record does not belong to the given customer.');
        }
    }
}
