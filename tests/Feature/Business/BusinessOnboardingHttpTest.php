<?php

namespace Tests\Feature\Business;

use App\Enums\Business\OnboardingStatus;
use App\Enums\Business\OnboardingStep;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Business\BuildInitialBusinessSnapshot;
use App\Library\Business\InitialBusinessSnapshotBuilder;
use App\Library\Business\OnboardingManager;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerOnboarding;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\BusinessServiceRepository;
use App\Repositories\Contracts\CustomerOnboardingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class BusinessOnboardingHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    /**
     * Design System M2 A1 onboarding behavior remediation: the onboarding
     * route group is now gated by the business.onboarding.enabled master
     * switch (app/Http/Middleware/EnsureBusinessOnboardingIsEnabled.php).
     * Every existing test in this file exercises the enabled ("master
     * switch on") path and must do so explicitly rather than accidentally
     * relying on the previously-ungated routes -- disabled-state tests
     * override this baseline explicitly within their own method.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['business.onboarding.enabled' => true]);
    }

    public function test_authenticated_customer_can_access_onboarding(): void
    {
        $this->actingAsHttpCustomer();

        $this->get(route('customer.onboarding.show'))->assertOk();
    }

    public function test_guest_cannot_access_onboarding(): void
    {
        // This app's Handler::render() explicitly renders AuthenticationException as a
        // 401 view whenever config('app.env') !== 'local' (true under phpunit's
        // APP_ENV=testing), overriding the auth middleware's own login-redirect
        // fallback. That's existing, intentional behavior for every customer.php
        // route, not specific to onboarding — so this proves guests are blocked
        // without asserting a redirect the app doesn't actually perform here.
        $this->get(route('customer.onboarding.show'))->assertUnauthorized();
    }

    public function test_each_customer_only_sees_their_own_onboarding(): void
    {
        $customerA = $this->actingAsHttpCustomer();
        $this->get(route('customer.onboarding.show'))->assertOk();
        $onboardingA = CustomerOnboarding::where('customer_id', $customerA->user_id)->first();

        $customerB = $this->actingAsHttpCustomer();
        $this->get(route('customer.onboarding.show'))->assertOk();
        $onboardingB = CustomerOnboarding::where('customer_id', $customerB->user_id)->first();

        $this->assertNotNull($onboardingA);
        $this->assertNotNull($onboardingB);
        $this->assertNotSame($onboardingA->id, $onboardingB->id);
    }

    public function test_resume_shows_the_persisted_current_step(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $onboarding = app(OnboardingManager::class)->start($customer);

        foreach (['goals', 'business', 'location', 'services', 'assets'] as $stepValue) {
            $onboarding->current_step = OnboardingStep::from($stepValue);
            $onboarding->save();

            $this->get(route('customer.onboarding.show'))->assertOk();
        }
    }

    public function test_navigating_ahead_of_the_current_step_redirects_back(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);

        $this->get(route('customer.onboarding.show', ['step' => 'services']))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'goals']));
    }

    public function test_revisiting_a_completed_step_is_allowed(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $onboarding = app(OnboardingManager::class)->start($customer);
        $onboarding->current_step = OnboardingStep::Location;
        $onboarding->completed_steps = ['goals', 'business'];
        $onboarding->save();

        $this->get(route('customer.onboarding.show', ['step' => 'goals']))->assertOk();
    }

    public function test_submitting_goals_advances_to_business_step(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);

        $this->post(route('customer.onboarding.goals.store'), [
            'primary_goals' => ['lead_generation'],
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'business']));

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->first();
        $this->assertSame(OnboardingStep::Business, $onboarding->current_step);
        $this->assertSame(['lead_generation'], $onboarding->primary_goals);
    }

    public function test_invalid_goals_submission_does_not_advance_progress(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);

        $this->post(route('customer.onboarding.goals.store'), [
            'primary_goals' => [],
        ])->assertSessionHasErrors('primary_goals');

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->first();
        $this->assertSame(OnboardingStep::Goals, $onboarding->current_step);
        $this->assertSame([], $onboarding->completed_steps ?? []);
    }

    public function test_repeated_goals_submission_is_idempotent(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);

        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->first();
        $this->assertSame(['goals'], $onboarding->completed_steps);
        $this->assertSame(OnboardingStep::Business, $onboarding->current_step);
    }

    public function test_submitting_the_business_step_twice_does_not_duplicate_records(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => 'Renamed Booth Co']));

        $this->assertSame(1, Business::where('customer_id', $customer->user_id)->count());
        $this->assertSame(1, CustomerOnboarding::where('customer_id', $customer->user_id)->count());
        $this->assertSame('Renamed Booth Co', Business::where('customer_id', $customer->user_id)->first()->name);
    }

    public function test_submitting_location_persists_the_primary_location_and_advances_to_services(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());

        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes())
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'services']));

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame(1, $business->locations()->count());
        $this->assertSame('Austin', $business->locations()->first()->city);
        $this->assertTrue($business->locations()->first()->is_primary);

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->first();
        $this->assertSame(OnboardingStep::Services, $onboarding->current_step);
    }

    public function test_submitting_services_syncs_services_and_advances_to_assets(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes());

        $this->post(route('customer.onboarding.services.store'), [
            'services' => [
                ['name' => 'Digital Photo Booth', 'is_primary' => '1'],
                ['name' => 'Analog Photo Booth', 'is_primary' => '0'],
            ],
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'assets']));

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame(2, $business->services()->count());
        $this->assertSame(1, $business->services()->where('is_primary', true)->count());

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->first();
        $this->assertSame(OnboardingStep::Assets, $onboarding->current_step);
    }

    public function test_submitting_assets_persists_urls_and_advances_to_analysis(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes());
        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Digital Photo Booth', 'is_primary' => '1']],
        ]);

        $this->post(route('customer.onboarding.assets.store'), [
            'facebook_url' => 'facebook.com/mybooth',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'analysis']));

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame('https://facebook.com/mybooth', $business->facebook_url);

        $onboarding = CustomerOnboarding::where('customer_id', $customer->user_id)->first();
        $this->assertSame(OnboardingStep::Analysis, $onboarding->current_step);
    }

    /**
     * Milestone 5 replaced the Milestone 4 static "isn't available yet"
     * placeholder with the real status-branching analysis screen
     * (resources/views/customer/onboarding/steps/analysis.blade.php). A
     * freshly-resumed onboarding at this step has no analysis run yet
     * (status stays whatever start() set it to, not analysis_pending/
     * results_ready/failed), so the view's default branch — the
     * "ready to run" state — is what should render here.
     */
    public function test_resume_at_analysis_step_renders_the_analysis_start_screen(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $onboarding = app(OnboardingManager::class)->start($customer);
        $onboarding->current_step = OnboardingStep::Analysis;
        $onboarding->completed_steps = ['goals', 'business', 'location', 'services', 'assets'];
        $onboarding->save();

        $response = $this->get(route('customer.onboarding.show'))->assertOk();

        // The status region the polling script targets.
        $response->assertSee('id="analysis-status"', false);
        // The customer-facing readiness copy for the "not started yet" state.
        $response->assertSee("Your business profile is saved. Ready to see how complete it is?");
        $response->assertSee('Run analysis');
        $response->assertSee('action="' . route('customer.onboarding.analysis.request') . '"', false);

        // No other state's content — in particular, no fabricated analysis
        // result — is shown before an analysis has actually run.
        $response->assertDontSee("We're analyzing your business profile", false);
        $response->assertDontSee('Your analysis is ready');
        $response->assertDontSee('View results');
        $response->assertDontSee('Profile completeness');
        $response->assertDontSee('Retry analysis');
    }

    public function test_repeating_location_and_services_submissions_does_not_duplicate_records(): void
    {
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);
        $this->post(route('customer.onboarding.goals.store'), ['primary_goals' => ['lead_generation']]);
        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes());

        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes());
        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes(['city' => 'Dallas']));

        // First submission has no id (new service). The rendered services form
        // carries the created id in a hidden field for re-submission, so a
        // realistic "repeat" includes it — omitting it would legitimately
        // create a second service and retire the first, which is correct sync
        // behavior (Milestone 1), not a duplicate-prevention failure.
        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['name' => 'Digital Photo Booth', 'is_primary' => '1']],
        ]);
        $serviceId = Business::where('customer_id', $customer->user_id)->first()->services()->first()->id;
        $this->post(route('customer.onboarding.services.store'), [
            'services' => [['id' => $serviceId, 'name' => 'Digital Photo Booth', 'is_primary' => '1']],
        ]);

        $business = Business::where('customer_id', $customer->user_id)->first();
        $this->assertSame(1, $business->locations()->count());
        $this->assertSame('Dallas', $business->locations()->first()->city);
        $this->assertSame(1, $business->services()->count());
        $this->assertSame(1, $business->services()->where('is_primary', true)->count());
        $this->assertSame(1, CustomerOnboarding::where('customer_id', $customer->user_id)->count());
    }

    public function test_assets_step_can_be_skipped_via_http(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $onboarding = app(OnboardingManager::class)->start($customer);
        $onboarding->current_step = OnboardingStep::Assets;
        $onboarding->completed_steps = ['goals', 'business', 'location', 'services'];
        $onboarding->save();

        $this->post(route('customer.onboarding.assets.skip'))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'analysis']));
    }

    public function test_no_skip_route_exists_for_other_steps(): void
    {
        $this->actingAsHttpCustomer();

        $this->post('/onboarding/goals/skip')->assertNotFound();
    }

    /**
     * Scenario A: config enabled + required incomplete onboarding -> dashboard redirects.
     */
    public function test_dashboard_redirects_when_onboarding_enabled_and_required_incomplete(): void
    {
        config(['business.onboarding.enabled' => true]);

        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer, true);

        $this->get(route('user.home'))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'goals']));
    }

    /**
     * Scenario B: config disabled + the SAME required, incomplete, unfinished-step
     * onboarding row -> dashboard is not redirected. The row is untouched (not deleted,
     * not completed) — only the automatic redirect is suppressed.
     */
    public function test_dashboard_is_not_redirected_when_onboarding_config_is_disabled(): void
    {
        config(['business.onboarding.enabled' => false]);

        $customer = $this->actingAsHttpCustomer();
        $onboarding = app(OnboardingManager::class)->start($customer, true);

        $this->get(route('user.home'))->assertOk();

        $onboarding->refresh();
        $this->assertTrue($onboarding->is_required);
        $this->assertSame(OnboardingStep::Goals, $onboarding->current_step);
        $this->assertNotSame(OnboardingStatus::Completed, $onboarding->status);
    }

    /**
     * A missing config/business.php `enabled` key must safely behave as
     * disabled, same as scenario B. config/business.php ships the key with
     * a `false` default today (verified directly), so this test genuinely
     * removes the runtime nested key rather than merely setting it to
     * `false` -- otherwise, once setUp() establishes an enabled=true
     * baseline (above), this would silently degrade into a duplicate of
     * the explicit-false test and stop proving what its name claims.
     */
    public function test_dashboard_is_not_redirected_when_onboarding_config_key_is_missing(): void
    {
        $onboardingConfig = config('business.onboarding');
        unset($onboardingConfig['enabled']);
        config(['business.onboarding' => $onboardingConfig]);
        $this->assertFalse(config('business.onboarding.enabled', false));

        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer, true);

        $this->get(route('user.home'))->assertOk();
    }

    /**
     * Scenario C: completed onboarding stays free of redirect loops while config is enabled.
     */
    public function test_dashboard_is_not_redirected_when_onboarding_is_completed_and_config_enabled(): void
    {
        config(['business.onboarding.enabled' => true]);

        $customer = $this->actingAsHttpCustomer();
        $onboarding = app(OnboardingManager::class)->start($customer, true);
        app(CustomerOnboardingRepository::class)->complete($onboarding);

        $this->get(route('user.home'))->assertOk();
    }

    /**
     * Design System M2 A1 onboarding behavior remediation, Blocker 1: the
     * documented RFC-001 master-switch intent (RFC-001-BUSINESS-CORE-DEPLOYMENT.md
     * §1 -- "the entire onboarding wizard ... behave[s] as if the feature
     * does not exist" when disabled) is now enforced by
     * app/Http/Middleware/EnsureBusinessOnboardingIsEnabled.php on the
     * whole onboarding route group. This corrects the previously-stale
     * assertion here (assertOk()), which encoded the pre-remediation drift.
     */
    public function test_direct_onboarding_routes_return_not_found_when_config_is_disabled(): void
    {
        config(['business.onboarding.enabled' => false]);

        $this->actingAsHttpCustomer();

        $this->get(route('customer.onboarding.show'))->assertNotFound();
    }

    /**
     * Proves the master switch gates every one of the 11 onboarding routes,
     * including the six FormRequest-backed actions (storeGoals,
     * storeBusiness, storeLocation, storeServices, storeAssets,
     * completeAction) -- each POSTed here with an empty/malformed body, so
     * a 404 (not a validation-error redirect) proves the route middleware
     * intercepts the request before Laravel ever resolves/validates the
     * FormRequest (the exact defect the original controller-body-guard
     * design could not have prevented). No onboarding row is lazily
     * created, no Business/Location/Service is mutated, and no analysis
     * job is dispatched by any denied request.
     */
    public function test_every_onboarding_route_is_denied_when_the_master_switch_is_disabled(): void
    {
        Bus::fake();
        config(['business.onboarding.enabled' => false]);
        $this->actingAsHttpCustomer();

        $this->get(route('customer.onboarding.show'))->assertNotFound();
        $this->post(route('customer.onboarding.goals.store'), [])->assertNotFound();
        $this->post(route('customer.onboarding.business.store'), [])->assertNotFound();
        $this->post(route('customer.onboarding.location.store'), [])->assertNotFound();
        $this->post(route('customer.onboarding.services.store'), [])->assertNotFound();
        $this->post(route('customer.onboarding.assets.store'), [])->assertNotFound();
        $this->post(route('customer.onboarding.assets.skip'))->assertNotFound();
        $this->post(route('customer.onboarding.analysis.request'))->assertNotFound();
        $this->post(route('customer.onboarding.action.complete'), [])->assertNotFound();
        $this->post(route('customer.onboarding.complete'))->assertNotFound();

        $this->assertSame(0, CustomerOnboarding::count());
        $this->assertSame(0, Business::count());
        Bus::assertNotDispatched(BuildInitialBusinessSnapshot::class);
    }

    /**
     * The analysis-status endpoint is the only onboarding route whose own
     * client (the polling script) sets Accept: application/json -- locked
     * to a real HTTP 404 with an exact JSON envelope, not the repository's
     * separate wantsJson()-returns-200 Handler.php convention, since the
     * middleware returns this response directly and never reaches
     * Handler::render() for this gate.
     */
    public function test_analysis_status_returns_not_found_with_exact_json_body_when_disabled(): void
    {
        config(['business.onboarding.enabled' => false]);
        $this->actingAsHttpCustomer();

        $this->getJson(route('customer.onboarding.analysis.status'))
            ->assertNotFound()
            ->assertExactJson(['status' => 'error', 'message' => 'Not Found']);
    }

    /**
     * Disabling is an availability gate, not a data-mutation event: an
     * existing onboarding row's full state must be byte-identical before
     * and after a denied ordinary HTTP request while the master switch is
     * off.
     */
    public function test_existing_onboarding_row_is_unchanged_by_a_denied_request_while_disabled(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $onboarding = app(OnboardingManager::class)->start($customer);
        $onboarding->current_step = OnboardingStep::Location;
        $onboarding->completed_steps = ['goals', 'business'];
        $onboarding->save();
        $before = $onboarding->refresh()->toArray();

        config(['business.onboarding.enabled' => false]);

        $this->get(route('customer.onboarding.show'))->assertNotFound();
        $this->post(route('customer.onboarding.location.store'), $this->locationAttributes())->assertNotFound();

        $onboarding->refresh();
        $this->assertSame($before, $onboarding->toArray());
    }

    public function test_guest_cannot_request_analysis(): void
    {
        $this->post(route('customer.onboarding.analysis.request'))->assertUnauthorized();
    }

    public function test_authenticated_owner_can_request_analysis(): void
    {
        Bus::fake();
        [$onboarding, $customer] = $this->httpOnboardingAtAnalysisStep();

        $this->post(route('customer.onboarding.analysis.request'))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'analysis']));

        $onboarding->refresh();
        $this->assertSame(1, $onboarding->analysis_version);
        $this->assertSame(OnboardingStatus::AnalysisPending, $onboarding->status);
        Bus::assertDispatched(BuildInitialBusinessSnapshot::class, fn ($job) => $job->onboardingId === $onboarding->id && $job->expectedVersion === 1);
    }

    public function test_requesting_analysis_before_prerequisites_are_met_redirects_with_an_error(): void
    {
        Bus::fake();
        $customer = $this->actingAsHttpCustomer();
        app(OnboardingManager::class)->start($customer);

        $this->post(route('customer.onboarding.analysis.request'))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'goals']))
            ->assertSessionHasErrors('onboarding');

        Bus::assertNotDispatched(BuildInitialBusinessSnapshot::class);
    }

    public function test_repeated_analysis_requests_increment_the_version(): void
    {
        Bus::fake();
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();

        $this->post(route('customer.onboarding.analysis.request'));
        $this->post(route('customer.onboarding.analysis.request'));

        $onboarding->refresh();
        $this->assertSame(2, $onboarding->analysis_version);
    }

    public function test_analysis_status_endpoint_reports_pending_state_safely(): void
    {
        Bus::fake();
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();
        $this->post(route('customer.onboarding.analysis.request'));

        $response = $this->get(route('customer.onboarding.analysis.status'))->assertOk();

        $response->assertJson([
            'status' => 'analysis_pending',
            'analysis_version' => 1,
            'current_step' => 'analysis',
            'completed' => false,
            'redirect_url' => null,
            'error' => null,
        ]);
        $this->assertSame(
            ['status', 'analysis_version', 'current_step', 'completed', 'redirect_url', 'error'],
            array_keys($response->json())
        );
    }

    public function test_analysis_status_endpoint_reports_ready_state_with_a_redirect_url(): void
    {
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding, 0, [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile_completeness_percent' => 80,
            'facts' => [], 'findings' => [],
        ]);

        $response = $this->get(route('customer.onboarding.analysis.status'))->assertOk();

        $response->assertJson([
            'status' => 'results_ready',
            'completed' => true,
            'redirect_url' => route('customer.onboarding.show', ['step' => 'results']),
        ]);
    }

    public function test_analysis_status_endpoint_reports_failed_state_with_only_a_safe_error(): void
    {
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();
        app(CustomerOnboardingRepository::class)->failAnalysis($onboarding, 0, 'We could not finish the analysis. Please retry.');

        $response = $this->get(route('customer.onboarding.analysis.status'))->assertOk();

        $response->assertJson([
            'status' => 'failed',
            'completed' => false,
            'error' => 'We could not finish the analysis. Please retry.',
        ]);
    }

    public function test_results_page_renders_the_stored_analysis_payload(): void
    {
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding, 0, [
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'profile_completeness_percent' => 72,
            'facts' => [],
            'findings' => [[
                'fingerprint' => 'business:1:missing_phone',
                'title' => 'Add your business phone number',
                'reason' => 'Customers need a reliable way to contact the business.',
                'impact' => 'medium',
                'effort' => 'low',
                'confidence' => 1.0,
                'worker_key' => 'business_advisor',
                'can_ai_prepare' => false,
                'action_key' => 'add_phone',
                'action_step' => 'business',
            ]],
        ]);

        $this->get(route('customer.onboarding.show', ['step' => 'results']))
            ->assertOk()
            ->assertSee('72%', false)
            ->assertSee('Add your business phone number');
    }

    public function test_direct_navigation_to_results_before_analysis_is_ready_is_prevented(): void
    {
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();

        $this->get(route('customer.onboarding.show', ['step' => 'results']))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'analysis']));
    }

    public function test_completing_an_inline_action_updates_business_data_and_advances_to_complete(): void
    {
        [$onboarding, $customer, $business] = $this->httpOnboardingAtAnalysisStep(['phone' => null]);
        $fingerprint = $this->seedRealAnalysisPayloadAndGetFingerprint($onboarding, $business, 'add_phone');

        $this->post(route('customer.onboarding.action.complete'), [
            'fingerprint' => $fingerprint,
            'action_key' => 'add_phone',
            'value' => '+15551234567',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'complete']));

        $onboarding->refresh();
        $this->assertSame('add_phone', $onboarding->first_value_action_key);
        $freshBusiness = app(BusinessRepository::class)->findOwnedByCustomer($business->id, $customer->user_id);
        $this->assertSame('+15551234567', $freshBusiness->phone);
    }

    public function test_completing_an_action_with_an_unknown_key_is_rejected_by_validation(): void
    {
        $this->httpOnboardingAtAnalysisStep();

        $this->post(route('customer.onboarding.action.complete'), [
            'fingerprint' => 'business:1:missing_phone',
            'action_key' => 'delete_everything',
        ])->assertSessionHasErrors('action_key');
    }

    public function test_completing_an_action_with_a_fingerprint_not_in_the_persisted_payload_is_rejected(): void
    {
        [$onboarding, $customer, $business] = $this->httpOnboardingAtAnalysisStep(['phone' => null]);
        $this->seedRealAnalysisPayloadAndGetFingerprint($onboarding, $business, 'add_phone');

        $this->post(route('customer.onboarding.action.complete'), [
            'fingerprint' => 'business:999999999:missing_phone',
            'action_key' => 'add_phone',
            'value' => '+15551234567',
        ])->assertRedirect(route('customer.onboarding.show', ['step' => 'results']))
            ->assertSessionHasErrors('action');

        $onboarding->refresh();
        $this->assertNull($onboarding->first_value_action_key);
        $freshBusiness = app(BusinessRepository::class)->findOwnedByCustomer($business->id, $customer->user_id);
        $this->assertNull($freshBusiness->phone);
    }

    public function test_complete_endpoint_redirects_to_the_dashboard_on_success(): void
    {
        [$onboarding] = $this->httpOnboardingAtAnalysisStep();
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding, 0, [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile_completeness_percent' => 100,
            'facts' => [], 'findings' => [],
        ]);

        $this->post(route('customer.onboarding.complete'))
            ->assertRedirect(route('user.home'));

        $onboarding->refresh();
        $this->assertSame(OnboardingStatus::Completed, $onboarding->status);
    }

    public function test_complete_endpoint_redirects_back_with_an_error_when_prerequisites_are_missing(): void
    {
        $this->httpOnboardingAtAnalysisStep();

        $this->post(route('customer.onboarding.complete'))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'results']))
            ->assertSessionHasErrors('onboarding');
    }

    public function test_completed_onboarding_does_not_loop_back_from_the_dashboard(): void
    {
        config(['business.onboarding.enabled' => true]);

        [$onboarding, $customer] = $this->httpOnboardingAtAnalysisStep();
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding, 0, [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'profile_completeness_percent' => 100,
            'facts' => [], 'findings' => [],
        ]);
        $this->post(route('customer.onboarding.complete'));

        $this->get(route('user.home'))->assertOk();
    }

    // =========================================================================
    // Design System M2 A1 onboarding behavior remediation, Blocker 2: an
    // expected RFC-004 Business-capacity denial during the onboarding
    // Business step must redirect back with a fixed, onboarding-safe
    // message -- never the generic framework 500 the uncaught exception
    // previously produced. All five denial families are covered
    // individually, matching the exact union catch in
    // BusinessOnboardingController::saveStep().
    // =========================================================================

    public function test_capacity_denial_workspace_plan_unassigned_redirects_with_the_safe_message(): void
    {
        [$customer, $workspace, $onboarding] = $this->customerAtCapacityDeniedBusinessStep('workspace_plan_unassigned');

        $this->assertCapacityDenialResponse($workspace, $onboarding);
    }

    public function test_capacity_denial_inactive_workspace_plan_redirects_with_the_safe_message(): void
    {
        [$customer, $workspace, $onboarding] = $this->customerAtCapacityDeniedBusinessStep('plan_inactive');

        $this->assertCapacityDenialResponse($workspace, $onboarding);
    }

    public function test_capacity_denial_suspended_workspace_plan_redirects_with_the_safe_message(): void
    {
        [$customer, $workspace, $onboarding] = $this->customerAtCapacityDeniedBusinessStep('plan_suspended');

        $this->assertCapacityDenialResponse($workspace, $onboarding);
    }

    public function test_capacity_denial_business_slot_allocation_required_redirects_with_the_safe_message(): void
    {
        [$customer, $workspace, $onboarding] = $this->customerAtCapacityDeniedBusinessStep('business_slot_allocation_required');

        $this->assertCapacityDenialResponse($workspace, $onboarding);
    }

    public function test_capacity_denial_business_slot_limit_exceeded_redirects_with_the_safe_message(): void
    {
        [$customer, $workspace, $onboarding] = $this->customerAtCapacityDeniedBusinessStep('business_slot_limit_exceeded');

        $this->assertCapacityDenialResponse($workspace, $onboarding);
    }

    /**
     * The update-existing-Business branch never calls
     * EntitlementManager::assertCanCreateAnotherBusiness() at all (the
     * capacity gate applies only to Business creation) -- an existing
     * regression confirmation, unaffected by the new catch clause.
     */
    public function test_capacity_gate_does_not_apply_to_updating_an_existing_business(): void
    {
        [$customer, $workspace, $onboarding] = $this->customerAtCapacityDeniedBusinessStep('business_slot_limit_exceeded');
        $existingBusiness = Business::where('workspace_id', $workspace->id)->first();
        $onboarding->business_id = $existingBusiness->id;
        $onboarding->save();

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes(['name' => 'Renamed Existing Business']))
            ->assertRedirect(route('customer.onboarding.show', ['step' => 'location']));

        $existingBusiness->refresh();
        $this->assertSame('Renamed Existing Business', $existingBusiness->name);
    }

    /**
     * A genuine ownership failure (AuthorizationException, thrown when the
     * onboarding's own attached business_id no longer belongs to the
     * authenticated customer) must never be caught by the new capacity
     * union type and converted into the friendly capacity message --
     * AuthorizationException does not extend RuntimeException and is
     * structurally incapable of matching it, so this app's existing
     * AuthorizationException handling (HTTP 401 via Handler::render(),
     * identical to AuthenticationException in this app) is unaffected.
     */
    public function test_a_cross_customer_business_attachment_is_not_converted_into_a_capacity_message(): void
    {
        $customer = $this->actingAsHttpCustomer();
        $foreignCustomer = $this->createCustomer();
        $foreignBusiness = $this->createBusinessWithWorkspace($foreignCustomer, $this->businessAttributes());

        $onboarding = app(OnboardingManager::class)->start($customer);
        $onboarding->current_step = OnboardingStep::Business;
        $onboarding->business_id = $foreignBusiness->id;
        $onboarding->save();

        $this->post(route('customer.onboarding.business.store'), $this->businessAttributes())
            ->assertUnauthorized();
    }

    /**
     * A logged-in customer with a complete business (primary location + one
     * active primary service) whose onboarding is bookmarked at the Analysis
     * step — the shared starting point for every Milestone 5 HTTP test.
     *
     * @param  array<string, mixed>  $businessOverrides
     * @return array{0: CustomerOnboarding, 1: Customer, 2: Business}
     */
    private function httpOnboardingAtAnalysisStep(array $businessOverrides = []): array
    {
        $customer = $this->actingAsHttpCustomer();
        $business = $this->createBusinessWithWorkspace($customer, array_merge(
            $this->businessAttributes(),
            $businessOverrides
        ));

        app(BusinessLocationRepository::class)->upsertPrimary($business, $this->locationAttributes());
        app(BusinessServiceRepository::class)->syncForBusiness($business, [
            ['name' => 'Digital Photo Booth', 'is_primary' => true],
        ]);

        $onboardingRepository = app(CustomerOnboardingRepository::class);
        $onboarding = $onboardingRepository->startForCustomer($customer, true);
        $onboarding = $onboardingRepository->attachBusiness($onboarding, $business);
        $onboarding->current_step = OnboardingStep::Analysis;
        $onboarding->completed_steps = ['goals', 'business', 'location', 'services', 'assets'];
        $onboarding->save();

        return [$onboarding, $customer, $business];
    }

    /**
     * Runs the real snapshot builder against $business, persists the result
     * as the onboarding's analysis_payload (ResultsReady), and returns the
     * fingerprint of the finding whose action_key is $actionKey — so tests
     * submit a fingerprint the executor will actually recognize as current.
     */
    private function seedRealAnalysisPayloadAndGetFingerprint(CustomerOnboarding $onboarding, Business $business, string $actionKey): string
    {
        $snapshot = app(InitialBusinessSnapshotBuilder::class)->build($business, $onboarding->primary_goals ?? []);
        app(CustomerOnboardingRepository::class)->completeAnalysis($onboarding, 0, $snapshot);

        $finding = collect($snapshot['findings'])->firstWhere('action_key', $actionKey);
        $this->assertNotNull($finding, "Expected a seeded finding for action_key [{$actionKey}].");

        return $finding['fingerprint'];
    }

    /**
     * Prepares an HTTP-authenticated customer whose sole owned Workspace
     * already denies further Business creation for the given
     * EntitlementManager denial reason, with onboarding bookmarked at the
     * Business step -- mirroring the exact fixture pattern
     * BusinessManagerTest::test_legacy_onboarding_against_an_existing_at_capacity_workspace_denies()
     * already uses to exercise this same underlying decision engine, so
     * POSTing customer.onboarding.business.store exercises
     * BusinessManager::applyIdentity()'s CREATE branch against exactly
     * that denial via the real HTTP surface.
     *
     * @return array{0: Customer, 1: Workspace, 2: CustomerOnboarding}
     */
    private function customerAtCapacityDeniedBusinessStep(string $denialReason): array
    {
        $customer = $this->actingAsHttpCustomer();
        $workspace = Workspace::create([
            'name' => 'Existing',
            'owner_user_id' => $customer->user_id,
            'is_active' => true,
        ]);
        $admin = User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
        $entitlementManager = app(EntitlementManager::class);
        $businessRepository = app(BusinessRepository::class);

        switch ($denialReason) {
            case 'plan_inactive':
                $entitlementManager->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 0);
                $entitlementManager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Inactive, $admin->id, 'Fixture.');
                break;
            case 'plan_suspended':
                $entitlementManager->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 0);
                $entitlementManager->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $admin->id, 'Fixture.');
                break;
            case 'business_slot_allocation_required':
                $entitlementManager->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 0);
                for ($i = 0; $i < 3; $i++) {
                    $businessRepository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => "Existing {$i}"]));
                }
                break;
            case 'business_slot_limit_exceeded':
                $entitlementManager->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);
                for ($i = 0; $i < 5; $i++) {
                    $businessRepository->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => "Existing {$i}"]));
                }
                break;
            case 'workspace_plan_unassigned':
            default:
                // No plan assignment at all -- decideBusinessSlotCapacity()'s
                // own null-assignment branch.
                break;
        }

        $onboarding = app(OnboardingManager::class)->start($customer);
        $onboarding->current_step = OnboardingStep::Business;
        $onboarding->save();

        return [$customer, $workspace, $onboarding];
    }

    /**
     * Asserts the exact, locked capacity-denial outcome (Design System M2
     * A1 onboarding behavior remediation, Blocker 2): no generic 500, the
     * fixed safe message, no Business persisted, no step advancement, no
     * completion mutation, and no raw exception text or Workspace ID
     * leakage into the response.
     */
    private function assertCapacityDenialResponse(Workspace $workspace, CustomerOnboarding $onboarding): void
    {
        $businessCountBefore = Business::where('workspace_id', $workspace->id)->count();

        $response = $this->post(
            route('customer.onboarding.business.store'),
            $this->businessAttributes(['name' => 'Denied Attempt'])
        );

        $response->assertRedirect(route('customer.onboarding.show', ['step' => 'business']));
        $response->assertSessionHasErrors([
            'onboarding' => "We can't create your business with the current account setup. Please contact support for help.",
        ]);

        $onboarding->refresh();
        $this->assertSame(OnboardingStep::Business, $onboarding->current_step);
        $this->assertNotSame(OnboardingStatus::Completed, $onboarding->status);
        $this->assertNull($onboarding->business_id);
        $this->assertSame($businessCountBefore, Business::where('workspace_id', $workspace->id)->count());

        $sessionErrorText = implode(' ', session('errors')->get('onboarding'));
        $this->assertStringNotContainsString((string) $workspace->id, $sessionErrorText);
        foreach ([
            'WorkspacePlanUnassignedException',
            'InactiveWorkspacePlanException',
            'SuspendedWorkspacePlanException',
            'BusinessSlotAllocationRequiredException',
            'BusinessSlotLimitExceededException',
        ] as $exceptionClassName) {
            $this->assertStringNotContainsString($exceptionClassName, $sessionErrorText);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function locationAttributes(array $overrides = []): array
    {
        return array_merge([
            'service_mode' => 'storefront',
            'address_line_1' => '1 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'country_code' => 'US',
            'public_address' => '1',
        ], $overrides);
    }

    /**
     * Logs in as a fresh customer with the fixtures a real HTTP render of this
     * app's shared layout requires: a non-empty 'license' app_config row
     * (RedirectIfNotValid), a 'customer_permissions' app_config row plus the
     * customer's own permissions populated from it (the can:access_backend
     * gate, defined dynamically per config/customer-permissions.php key in
     * AuthServiceProvider), a 'custom_script' app_config row (read
     * unconditionally by resources/views/panels/footer.blade.php, included by
     * every page through layouts/verticalLayoutMaster.blade.php), and a
     * verified email (the 'verified' middleware on the user.home route).
     * None of these are Business Core concerns — they're pre-existing app
     * preconditions this is the first milestone to exercise via real HTTP
     * requests that actually render the shared layout.
     */
    private function actingAsHttpCustomer(): Customer
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = $this->createCustomer();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $customer;
    }

    /**
     * Seeds only the app_config rows this HTTP render path actually reads.
     * 'customer_permissions' is pulled from AppConfig::defaultSettings() (the
     * same source database/seeders/AppConfigSeeder uses) rather than
     * fabricated, since Customer::customerPermissions() below depends on its
     * exact shape. 'license' and 'custom_script' are simple, realistic
     * placeholder values — a non-empty string and an empty string
     * respectively — matching what a real install's own defaults look like.
     */
    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
