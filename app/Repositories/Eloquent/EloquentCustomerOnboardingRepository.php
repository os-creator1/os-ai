<?php

namespace App\Repositories\Eloquent;

use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Support\Facades\DB;

class EloquentCustomerOnboardingRepository extends EloquentBaseRepository implements CustomerOnboardingRepository
{
    public function __construct(CustomerOnboarding $onboarding)
    {
        parent::__construct($onboarding);
    }

    public function findByCustomer(Customer $customer): ?CustomerOnboarding
    {
        return $this->query()->where('customer_id', $customer->user_id)->first();
    }

    public function startForCustomer(Customer $customer, bool $required): CustomerOnboarding
    {
        return DB::transaction(function () use ($customer, $required) {
            $onboarding = $this->query()->where('customer_id', $customer->user_id)->first();

            if ($onboarding) {
                return $onboarding;
            }

            /** @var CustomerOnboarding $onboarding */
            $onboarding = $this->make([]);
            $onboarding->customer_id = $customer->user_id;
            $onboarding->is_required = $required;
            $onboarding->status = OnboardingStatus::Started;
            $onboarding->current_step = OnboardingStep::Goals;
            $onboarding->started_at = now();
            $onboarding->last_activity_at = now();
            $onboarding->save();

            return $onboarding;
        });
    }

    public function attachBusiness(CustomerOnboarding $onboarding, Business $business): CustomerOnboarding
    {
        $onboarding->business_id = $business->id;
        $onboarding->last_activity_at = now();
        $onboarding->save();

        return $onboarding;
    }

    public function markStepComplete(CustomerOnboarding $onboarding, OnboardingStep $step, OnboardingStep $nextStep): CustomerOnboarding
    {
        $steps = $onboarding->completed_steps ?? [];

        if (! in_array($step->value, $steps, true)) {
            $steps[] = $step->value;
        }

        $onboarding->completed_steps = array_values($steps);
        $onboarding->current_step = $nextStep;
        $onboarding->last_activity_at = now();
        $onboarding->save();

        return $onboarding;
    }

    public function startAnalysis(CustomerOnboarding $onboarding, int $version): CustomerOnboarding
    {
        $onboarding->analysis_version = $version;
        $onboarding->status = OnboardingStatus::AnalysisPending;
        $onboarding->current_step = OnboardingStep::Analysis;
        $onboarding->analysis_started_at = now();
        $onboarding->analysis_error = null;
        $onboarding->last_activity_at = now();
        $onboarding->save();

        return $onboarding;
    }

    public function completeAnalysis(CustomerOnboarding $onboarding, int $version, array $payload): CustomerOnboarding
    {
        return DB::transaction(function () use ($onboarding, $version, $payload) {
            $current = $this->query()->whereKey($onboarding->id)->lockForUpdate()->first();

            if (! $current || $current->analysis_version !== $version) {
                return $current ?? $onboarding;
            }

            $current->analysis_payload = $payload;
            $current->analysis_error = null;
            $current->status = OnboardingStatus::ResultsReady;
            $current->current_step = OnboardingStep::Results;
            $current->analysis_completed_at = now();
            $current->last_activity_at = now();
            $current->save();

            return $current;
        });
    }

    public function failAnalysis(CustomerOnboarding $onboarding, int $version, string $safeError): CustomerOnboarding
    {
        return DB::transaction(function () use ($onboarding, $version, $safeError) {
            $current = $this->query()->whereKey($onboarding->id)->lockForUpdate()->first();

            if (! $current || $current->analysis_version !== $version) {
                return $current ?? $onboarding;
            }

            $current->status = OnboardingStatus::Failed;
            $current->current_step = OnboardingStep::Analysis;
            $current->analysis_error = $safeError;
            $current->last_activity_at = now();
            $current->save();

            return $current;
        });
    }

    public function recordFirstValueAction(CustomerOnboarding $onboarding, string $actionKey): CustomerOnboarding
    {
        $onboarding->first_value_action_key = $actionKey;
        $onboarding->first_value_action_completed_at = now();
        $onboarding->current_step = OnboardingStep::Complete;
        $onboarding->last_activity_at = now();
        $onboarding->save();

        return $onboarding;
    }

    public function complete(CustomerOnboarding $onboarding): CustomerOnboarding
    {
        $onboarding->status = OnboardingStatus::Completed;
        $onboarding->current_step = OnboardingStep::Complete;
        $onboarding->completed_at = now();
        $onboarding->last_activity_at = now();
        $onboarding->save();

        return $onboarding;
    }

    public function dismiss(CustomerOnboarding $onboarding): CustomerOnboarding
    {
        $onboarding->status = OnboardingStatus::Dismissed;
        $onboarding->dismissed_at = now();
        $onboarding->last_activity_at = now();
        $onboarding->save();

        return $onboarding;
    }
}
