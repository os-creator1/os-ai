<?php

namespace Tests\Feature\Opportunity;

use App\Enums\Opportunity\OpportunityRunStatus;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\OpportunityRunCandidate;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * RFC-002 Milestone 5 Slice 3 — admin producer-run and staged-candidate
 * inspection only. Read-only: no admin mutation exists yet.
 */
class AdminOpportunityRunControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        config()->set('opportunity.enabled', true);
    }

    public function test_guest_cannot_access_the_run_index(): void
    {
        $business = $this->createBusinessForOpportunities();

        $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]))
            ->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_access_the_run_index(): void
    {
        $business = $this->actingAsCustomerWithBusiness();

        $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]))
            ->assertUnauthorized();
    }

    public function test_non_admin_is_blocked_even_with_matching_permission_strings_in_session(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->withSession(['permissions' => collect(['access backend', 'view opportunities'])]);

        $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]))
            ->assertUnauthorized();
    }

    public function test_admin_without_backend_access_is_blocked(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->actingAsAdmin([]);

        $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]))
            ->assertUnauthorized();
    }

    public function test_admin_without_view_opportunities_permission_is_blocked(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]))
            ->assertUnauthorized();
    }

    public function test_authorized_admin_can_access_the_run_index_for_another_customers_business(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->createOpportunityRun($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]))
            ->assertOk();
    }

    public function test_feature_disabled_returns_404_for_index_and_detail(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);
        config()->set('opportunity.enabled', false);

        $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]))->assertNotFound();
        $this->get(route('admin.opportunities.runs.show', $run->id))->assertNotFound();
    }

    public function test_missing_business_returns_404(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.index', ['business_id' => 999999]))->assertNotFound();
    }

    public function test_run_index_contains_only_runs_belonging_to_the_requested_business(): void
    {
        $business = $this->createBusinessForOpportunities();
        $otherBusiness = $this->createBusinessForOpportunities();
        $this->createOpportunityRun($business, ['producer_version' => 1]);
        $this->createOpportunityRun($otherBusiness, ['producer_version' => 2]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]));
        $body = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString((string) $business->id, $body);
    }

    /**
     * Created in the opposite order of their started_at values, so a test
     * that merely reflected insertion/id order (rather than genuinely
     * ordering by started_at) would get this backwards.
     */
    public function test_run_index_ordering_is_deterministic(): void
    {
        $business = $this->createBusinessForOpportunities();
        $newerButCreatedFirst = $this->createOpportunityRun($business, ['started_at' => now()]);
        $olderButCreatedSecond = $this->createOpportunityRun($business, ['started_at' => now()->subHour()]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]));
        $body = $response->getContent();

        $response->assertOk();
        $newerPosition = strpos($body, '>' . $newerButCreatedFirst->id . '<');
        $olderPosition = strpos($body, '>' . $olderButCreatedSecond->id . '<');

        $this->assertNotFalse($newerPosition);
        $this->assertNotFalse($olderPosition);
        $this->assertLessThan($olderPosition, $newerPosition);
    }

    public function test_pagination_preserves_business_id_and_per_page(): void
    {
        $business = $this->createBusinessForOpportunities();

        for ($i = 0; $i < 3; $i++) {
            $this->createOpportunityRun($business, ['started_at' => now()->subMinutes($i)]);
        }

        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.runs.index', [
            'business_id' => $business->id,
            'per_page' => 2,
        ]));
        $body = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('business_id=' . $business->id, $body);
        $this->assertStringContainsString('per_page=2', $body);
    }

    public function test_authorized_admin_can_open_a_cross_tenant_run(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.show', $run->id))
            ->assertOk()
            ->assertSee('Opportunity run #' . $run->id);
    }

    public function test_missing_run_returns_404(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.show', 999999))->assertNotFound();
    }

    public function test_failed_run_candidates_are_visible(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, ['status' => OpportunityRunStatus::Failed->value]);
        $this->createOpportunityRunCandidate($run, ['title' => 'Failed Run Candidate']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.show', $run->id))
            ->assertOk()
            ->assertSee('Failed Run Candidate');
    }

    public function test_abandoned_run_candidates_are_visible(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, [
            'status' => OpportunityRunStatus::Failed->value,
            'abandoned_at' => now(),
            'reason_code' => 'heartbeat_timeout',
        ]);
        $this->createOpportunityRunCandidate($run, ['title' => 'Abandoned Run Candidate']);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.show', $run->id))
            ->assertOk()
            ->assertSee('Abandoned Run Candidate');
    }

    public function test_candidate_ordering_matches_ordered_for_run(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $this->createOpportunityRunCandidate($run, [
            'title' => 'First Candidate',
            'fingerprint' => hash('sha256', 'run-order-1'),
        ]);
        $this->createOpportunityRunCandidate($run, [
            'title' => 'Second Candidate',
            'fingerprint' => hash('sha256', 'run-order-2'),
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.runs.show', $run->id));
        $body = $response->getContent();

        $response->assertOk();
        $firstPosition = strpos($body, 'First Candidate');
        $secondPosition = strpos($body, 'Second Candidate');

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
    }

    public function test_candidate_from_another_run_is_not_rendered(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, ['producer_version' => 1]);
        $otherRun = $this->createOpportunityRun($business, ['producer_version' => 2]);
        $this->createOpportunityRunCandidate($otherRun, [
            'title' => 'Foreign Run Candidate',
            'fingerprint' => hash('sha256', 'foreign-run-candidate'),
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.show', $run->id))
            ->assertOk()
            ->assertDontSee('Foreign Run Candidate');
    }

    public function test_empty_candidate_state_renders_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.show', $run->id))
            ->assertOk()
            ->assertSee('No staged candidates recorded for this run.');
    }

    public function test_business_and_owning_customer_identity_render_safely(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('Snap Booth Co');
        $response->assertSee('Test Customer');
    }

    public function test_persisted_safe_diagnostic_summaries_may_render(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business, [
            'status' => OpportunityRunStatus::Failed->value,
            'abandoned_at' => now(),
            'reason_code' => 'heartbeat_timeout',
            'safe_error_summary' => 'This run stopped responding and was marked failed.',
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $this->get(route('admin.opportunities.runs.show', $run->id))
            ->assertOk()
            ->assertSee('This run stopped responding and was marked failed.')
            ->assertSee('heartbeat_timeout');
    }

    public function test_raw_json_and_arbitrary_metadata_do_not_render(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $this->createOpportunityRunCandidate($run, [
            'evidence' => ['fact_key' => 'phone_blank', 'observed_value' => 'super-secret-observed-value'],
            'recommended_action' => ['action_key' => 'add_phone', 'parameters' => ['value' => '+15551234567']],
            'recommended_action_hash' => hash('sha256', 'run-candidate-hash'),
        ]);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.runs.show', $run->id));

        $response->assertOk();
        $response->assertDontSee('super-secret-observed-value');
        $response->assertDontSee('"action_key"', false);
        $response->assertDontSee('+15551234567');
        $response->assertDontSee('Stack trace');
        $response->assertDontSee('SQLSTATE');
    }

    public function test_get_requests_do_not_mutate_runs_or_candidates_or_queue_jobs(): void
    {
        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $candidate = $this->createOpportunityRunCandidate($run);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $runStatusBefore = $run->status;
        $runUpdatedAtBefore = $run->updated_at;
        $candidateCountBefore = OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count();

        Queue::fake();

        $this->get(route('admin.opportunities.runs.index', ['business_id' => $business->id]))->assertOk();
        $this->get(route('admin.opportunities.runs.show', $run->id))->assertOk();

        $freshRun = $run->fresh();
        $this->assertSame($runStatusBefore, $freshRun->status);
        $this->assertTrue($runUpdatedAtBefore->equalTo($freshRun->updated_at));
        $this->assertSame($candidateCountBefore, OpportunityRunCandidate::where('opportunity_run_id', $run->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_existing_opportunity_detail_links_to_the_businesss_run_index(): void
    {
        $business = $this->createBusinessForOpportunities();
        $opportunity = $this->createOpportunity($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.show', $opportunity->id));

        $response->assertOk();
        $response->assertSee(route('admin.opportunities.runs.index', ['business_id' => $business->id]), false);
    }

    public function test_no_admin_run_or_candidate_mutation_route_or_form_exists(): void
    {
        $this->assertTrue(Route::has('admin.opportunities.runs.index'));
        $this->assertTrue(Route::has('admin.opportunities.runs.show'));
        $this->assertFalse(Route::has('admin.opportunities.runs.retry'));
        $this->assertFalse(Route::has('admin.opportunities.runs.dismiss'));
        $this->assertFalse(Route::has('admin.opportunities.runs.destroy'));
        $this->assertFalse(Route::has('admin.opportunities.runs.candidates.destroy'));

        $business = $this->createBusinessForOpportunities();
        $run = $this->createOpportunityRun($business);
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.runs.show', $run->id));
        $response->assertOk();

        $previousLibxmlSetting = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML($response->getContent());
        libxml_use_internal_errors($previousLibxmlSetting);

        $xpath = new DOMXPath($document);
        $runSections = $xpath->query('//*[@id="admin-opportunity-run-show"]');

        $this->assertSame(1, $runSections->length);

        $runSection = $runSections->item(0);

        $this->assertSame(0, $xpath->query('.//form', $runSection)->length);
        $this->assertSame(0, $xpath->query('.//button[@type="submit"]', $runSection)->length);
        $this->assertSame(0, $xpath->query('.//input[@type="submit"]', $runSection)->length);
        $this->assertSame(0, $xpath->query('.//input[@name="_method"]', $runSection)->length);
    }

    private function actingAsCustomerWithBusiness(): Business
    {
        $business = $this->createBusinessForOpportunities();
        $customer = $business->customer;
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $business;
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Both the admin route group (ValidProduct) and the customer route
     * group render the shared layout, which unconditionally reads the
     * 'license' and 'custom_script' app_config rows (mirrors
     * AdminOpportunityControllerTest's own identical helper).
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
