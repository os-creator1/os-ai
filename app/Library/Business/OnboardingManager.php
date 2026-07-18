<?php

namespace App\Library\Business;

use App\Enums\Business\OnboardingStep;
use App\Events\Business\CustomerOnboardingStarted;
use App\Events\Business\CustomerOnboardingStepCompleted;
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
