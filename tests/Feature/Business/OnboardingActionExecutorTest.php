<?php

namespace Tests\Feature\Business;

use App\Enums\Business\OnboardingStep;
use App\Library\Business\OnboardingActionExecutor;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class OnboardingActionExecutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_an_unknown_action_key_is_rejected_before_any_fingerprint_is_consulted(): void
    {
        [$onboarding, $customer] = $this->onboardingWithBusiness();
        $executor = app(OnboardingActionExecutor::class);

        $this->expectException(InvalidArgumentException::class);

        $executor->execute($onboarding, $customer, 'business:1:missing_phone', 'delete_everything');
    }

    public function test_tenant_isolation_is_enforced(): void
    {
        [$onboarding] = $this->onboardingWithBusiness();
        $stranger = $this->createCustomer();
        $executor = app(OnboardingActionExecutor::class);

        $this->expectException(AuthorizationException::class);

        $executor->execute($onboarding, $stranger, 'business:1:missing_phone', 'add_phone', '+15551234567');
    }

    public function test_a_valid_fingerprint_and_matching_action_are_accepted(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $fingerprint = $this->fingerprint($business, 'missing_phone');
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($fingerprint, 'add_phone')]);
        $executor = app(OnboardingActionExecutor::class);

        $result = $executor->execute($onboarding, $customer, $fingerprint, 'add_phone', '+15551234567');

        $this->assertTrue($result['completed']);
        $this->assertSame('add_phone', $result['onboarding']->first_value_action_key);
    }

    public function test_an_unknown_fingerprint_is_rejected(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($this->fingerprint($business, 'missing_phone'), 'add_phone')]);
        $executor = app(OnboardingActionExecutor::class);

        $this->expectException(InvalidArgumentException::class);

        $executor->execute($onboarding, $customer, 'business:999999:missing_phone', 'add_phone', '+15551234567');
    }

    public function test_a_stale_fingerprint_from_a_superseded_analysis_is_rejected(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $staleFingerprint = $this->fingerprint($business, 'missing_phone');
        // The persisted payload has since been replaced by a newer analysis
        // run whose findings no longer include this fingerprint at all.
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($this->fingerprint($business, 'missing_email'), 'add_email')]);
        $executor = app(OnboardingActionExecutor::class);

        $this->expectException(InvalidArgumentException::class);

        $executor->execute($onboarding, $customer, $staleFingerprint, 'add_phone', '+15551234567');
    }

    public function test_a_fingerprint_and_action_key_mismatch_is_rejected(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $fingerprint = $this->fingerprint($business, 'missing_phone');
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($fingerprint, 'add_phone')]);
        $executor = app(OnboardingActionExecutor::class);

        $this->expectException(InvalidArgumentException::class);

        // The fingerprint is real and current, but its actual finding is
        // 'add_phone' — a request cannot pair it with a different action.
        $executor->execute($onboarding, $customer, $fingerprint, 'add_email', 'someone@example.test');
    }

    public function test_a_redirect_type_action_never_completes_from_a_click_alone(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $fingerprint = $this->fingerprint($business, 'missing_primary_location');
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($fingerprint, 'add_location')]);
        $executor = app(OnboardingActionExecutor::class);

        $result = $executor->execute($onboarding, $customer, $fingerprint, 'add_location');

        $this->assertFalse($result['completed']);
        $this->assertSame(OnboardingStep::Location, $result['step']);
        $onboarding->refresh();
        $this->assertNull($onboarding->first_value_action_key);
    }

    public function test_the_exact_fingerprint_remains_present_when_no_value_is_supplied(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $fingerprint = $this->fingerprint($business, 'missing_phone');
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($fingerprint, 'add_phone')]);
        $executor = app(OnboardingActionExecutor::class);

        // No value supplied — a click alone must not write data or count as completion.
        $result = $executor->execute($onboarding, $customer, $fingerprint, 'add_phone');

        $this->assertFalse($result['completed']);
        $onboarding->refresh();
        $this->assertNull($onboarding->first_value_action_key);
    }

    public function test_the_exact_fingerprint_disappears_and_completion_is_recorded_after_a_real_correction(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $fingerprint = $this->fingerprint($business, 'missing_phone');
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($fingerprint, 'add_phone')]);
        $executor = app(OnboardingActionExecutor::class);

        $result = $executor->execute($onboarding, $customer, $fingerprint, 'add_phone', '+15551234567');

        $this->assertTrue($result['completed']);
        $this->assertSame(OnboardingStep::Complete, $result['step']);
        $this->assertSame('add_phone', $result['onboarding']->first_value_action_key);

        $freshBusiness = app(BusinessRepository::class)->findOwnedByCustomer($business->id, $customer->user_id);
        $this->assertSame('+15551234567', $freshBusiness->phone);
    }

    public function test_repeated_successful_submission_is_idempotent(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $fingerprint = $this->fingerprint($business, 'missing_phone');
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($fingerprint, 'add_phone')]);
        $executor = app(OnboardingActionExecutor::class);

        $first = $executor->execute($onboarding, $customer, $fingerprint, 'add_phone', '+15551234567');
        $second = $executor->execute($first['onboarding'], $customer, $fingerprint, 'add_phone', '+15551234567');

        $this->assertTrue($first['completed']);
        $this->assertTrue($second['completed']);
        $this->assertSame('add_phone', $second['onboarding']->first_value_action_key);
        $this->assertSame(1, CustomerOnboarding::where('customer_id', $customer->user_id)->count());
    }

    public function test_request_manipulation_cannot_select_an_arbitrary_business_field(): void
    {
        [$onboarding, $customer, $business] = $this->onboardingWithBusiness();
        $fingerprint = $this->fingerprint($business, 'missing_phone');
        $onboarding = $this->seedAnalysisPayload($onboarding, [$this->finding($fingerprint, 'add_phone')]);
        $executor = app(OnboardingActionExecutor::class);

        // 'add_phone' only ever writes the 'phone' column (INLINE_FIELDS), no
        // matter what the request claims the fingerprint/finding is about.
        $executor->execute($onboarding, $customer, $fingerprint, 'add_phone', '+15551234567');

        $freshBusiness = app(BusinessRepository::class)->findOwnedByCustomer($business->id, $customer->user_id);
        $this->assertSame($customer->user_id, $freshBusiness->customer_id);
        $this->assertSame('+15551234567', $freshBusiness->phone);
        $this->assertNull($freshBusiness->email);
    }

    private function fingerprint(Business $business, string $suffix): string
    {
        return "business:{$business->id}:{$suffix}";
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $fingerprint, string $actionKey): array
    {
        return [
            'fingerprint' => $fingerprint,
            'title' => 'Test finding',
            'reason' => 'Test reason.',
            'impact' => 'medium',
            'effort' => 'low',
            'confidence' => 1.0,
            'worker_key' => 'business_advisor',
            'can_ai_prepare' => false,
            'action_key' => $actionKey,
            'action_step' => 'business',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function seedAnalysisPayload(CustomerOnboarding $onboarding, array $findings): CustomerOnboarding
    {
        $onboarding->analysis_payload = ['version' => 1, 'findings' => $findings];
        $onboarding->save();

        return $onboarding->fresh();
    }

    /**
     * @return array{0: CustomerOnboarding, 1: Customer, 2: Business}
     */
    private function onboardingWithBusiness(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startForCustomer($customer, true);
        $onboarding = $onboardingRepository->attachBusiness($onboarding, $business);
        $onboarding->current_step = OnboardingStep::Location;
        $onboarding->completed_steps = ['goals', 'business'];
        $onboarding->save();

        return [$onboarding, $customer, $business];
    }
}
