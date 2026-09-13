<?php

namespace Tests\Feature\Coo\Insight;

use App\Enums\Coo\CooInsightTrigger;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Jobs\Coo\GenerateCooInsight;
use App\Library\Entitlement\EntitlementManager;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * AI-3 — §8.2 E-4 "Explain this change": single-shot, one per subject per
 * 24 h, queued on the interactive lane, never while viewing as a client, and
 * never for a Business the CustomerContext did not resolve (T-SEC-1/2).
 */
class ExplainThisChangeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCooInsightFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
        Queue::fake();
    }

    public function test_asking_queues_one_interactive_explanation_of_the_window_on_screen(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->explain($workspace, $business, ['range' => 'last_30_days'])
            ->assertRedirect(route('user.home', ['range' => 'last_30_days']))
            ->assertSessionHas('message', "We're preparing an explanation of this change. It will appear under What we notice when it's ready.");

        Queue::assertPushed(GenerateCooInsight::class, fn (GenerateCooInsight $job): bool => $job->businessId === (int) $business->id
            && $job->trigger === CooInsightTrigger::ExplainThisChange->value
            && $job->range === ['range' => 'last_30_days']
            && $job->actorUserId === (int) $customer->user_id);

        $this->assertSame(\App\Library\Ai\Enums\AiLane::Interactive, CooInsightTrigger::ExplainThisChange->lane(), 'Charged to the interactive lane.');
        $this->assertSame(0, $this->fakeAi->callCount(), 'The request itself never calls AI.');
    }

    public function test_one_explanation_per_business_per_24_hours(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->explain($workspace, $business);
        $this->explain($workspace, $business, ['range' => 'last_7_days'])
            ->assertSessionHas('message', 'An explanation was already requested for this business in the last 24 hours.');

        Queue::assertPushedTimes(GenerateCooInsight::class, 1);

        // Carbon and CarbonImmutable share one test clock: travel moves it once.
        $this->travel(23)->hours();
        $this->explain($workspace, $business);
        Queue::assertPushedTimes(GenerateCooInsight::class, 1);

        $this->travel(2)->hours();
        $this->explain($workspace, $business);
        Queue::assertPushedTimes(GenerateCooInsight::class, 2);
    }

    public function test_nothing_is_queued_when_ai_is_off_or_the_plan_lacks_ai_coo_basic(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        config(['services.openai.active' => false]);
        $this->explain($workspace, $business)->assertSessionHas('message', "AI explanations aren't available for this business.");

        config(['services.openai.active' => true]);
        app(EntitlementManager::class)->createOrChangeOverride($workspace, PlatformFeature::AiCooBasic, WorkspaceEntitlementOverrideState::Deny, $this->platformAdminId(), 'AI-3 fixture.');
        $this->explain($workspace, $business)->assertSessionHas('message', "AI explanations aren't available for this business.");

        Queue::assertNothingPushed();
    }

    public function test_a_foreign_or_unresolved_business_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Venue', 'Mine Account');
        [, $foreign, $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Foreign Venue', 'Foreign Account');
        $this->authenticateAs($customer);

        $this->explain($foreignWorkspace, $foreign)->assertNotFound();
        $this->post(route('customer.workspaces.businesses.performance.explain', [$workspace->uid, $foreign->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.businesses.performance.explain', [(string) Str::uuid(), $business->uid]))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_an_actor_who_may_not_see_results_cannot_ask(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['view_reports'])));

        $this->explain($workspace, $business)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_an_unusable_range_is_refused_without_queuing(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->explain($workspace, $business, ['range' => 'forever'])->assertSessionHas('status', 'error');
        Queue::assertNothingPushed();
    }

    public function test_viewing_as_a_client_can_never_spend_that_clients_ai(): void
    {
        $this->assertContains('customer.workspaces.businesses.performance.explain', ViewAsProhibitedActions::EXACT);

        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Own Client', 'Northwind Agency');
        $viewed = $this->addBusiness($agency, $workspace, 'Viewed Client');
        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        $response = $this->explain($workspace, $viewed);

        $this->assertNotSame(200, $response->status());
        Queue::assertNothingPushed();
    }

    /** @param array<string, string> $input */
    private function explain(Workspace $workspace, Business $business, array $input = ['range' => 'this_month']): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('customer.workspaces.businesses.performance.explain', [$workspace->uid, $business->uid]), $input);
    }
}
