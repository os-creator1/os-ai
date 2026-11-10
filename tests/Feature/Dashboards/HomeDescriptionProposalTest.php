<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Opportunity\OpportunityStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Ai\AiGateway;
use App\Library\Ai\AiRequest;
use App\Library\Ai\AiResult;
use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Opportunity\DescriptionProposal;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\Business;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Home: reach-before-profile priority, the inline description proposal, metered explicit AI, and the trusted one-click
 * approval (configure → request approval → confirm → queued job → verified). No live AI: the gateway is mocked.
 */
class HomeDescriptionProposalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    private const VALUE = 'We rent photo booths for weddings, birthdays and corporate events across Orange, with on-site attendants.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['business.onboarding.enabled' => true, 'opportunity.enabled' => true]);
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: int} a launched-onboarding Business with NO enquiry path, and its description Opportunity id */
    private function onboarded(string $name = 'Orange Glam Booths'): array
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, $name, $name . ' Account');
        app(BusinessLocationRepository::class)->upsertPrimary($business, ['name' => 'Main', 'service_mode' => 'service_area', 'city' => 'Orange', 'region' => 'CA', 'country_code' => 'US']);
        $this->primaryService($business);
        $this->adoptSeoFirstPolicy($business);
        // The customer holds the advisor capability, exactly as the existing Opportunity HTTP tests arrange it.
        $customer->permissions = \App\Models\Customer::customerPermissions();
        $customer->save();
        $action = ['schema_version' => 1, 'action_key' => 'add_description', 'parameters' => [], 'approval_required' => true, 'completion_policy' => 'system_verified'];
        $id = $this->recommendation($business, [
            'type' => 'missing_description', 'title' => 'Add a description',
            'recommended_action' => json_encode($action), 'recommended_action_hash' => (new OpportunityActionHash())->compute($action), 'action_schema_version' => 1,
        ]);
        $this->authenticateAs($customer);

        return [$customer, $business, $id];
    }

    private function primary($customer): ?array
    {
        return $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_COMMAND)['primary'];
    }

    private function liveForm(Business $business): void
    {
        $locationId = (int) DB::table('business_locations')->where('business_id', $business->id)->value('id');
        $formId = DB::table('forms')->insertGetId(['uid' => (string) Str::uuid(), 'business_id' => $business->id, 'name' => 'Enquiry', 'lifecycle_state' => 'active', 'current_version' => 1, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('form_versions')->insert(['form_id' => $formId, 'version' => 1, 'content_hash' => str_repeat('a', 64), 'submit_label' => 'Send', 'success_message' => 'Thanks', 'fields' => '[]', 'created_at' => now()]);
        DB::table('form_deployments')->insert(['uid' => (string) Str::uuid(), 'form_id' => $formId, 'business_location_id' => $locationId, 'source' => 'direct_link', 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function noAi(): void
    {
        $this->mock(AiGateway::class, fn (MockInterface $m) => $m->shouldNotReceive('complete'));
    }

    private function uid(int $id): string
    {
        return (string) DB::table('opportunities')->where('id', $id)->value('uid');
    }

    private function url(string $name, Business $business, array $extra = []): string
    {
        return route('customer.workspaces.businesses.home.' . $name, array_merge([$business->workspace->uid, $business->uid], $extra));
    }

    public function test_no_enquiry_path_prioritises_setup_over_the_missing_description(): void
    {
        [$customer] = $this->onboarded();
        $this->noAi();

        $primary = $this->primary($customer);

        $this->assertSame('reach_setup', $primary['key']);
        $this->assertNotNull($primary['action']['url']);
        $this->assertArrayNotHasKey('description', $primary);
    }

    public function test_urgent_moves_still_win(): void
    {
        [$customer, $business] = $this->onboarded();
        $this->conversationWith($business, [['incoming', $this->minutesAgo(30)]]);

        $this->assertSame('conversations_awaiting_reply', $this->primary($customer)['key']);
    }

    public function test_a_usable_enquiry_path_keeps_the_description_and_shows_a_proposal_from_saved_facts_without_ai(): void
    {
        [$customer, $business, $id] = $this->onboarded();
        $this->liveForm($business);
        $this->noAi();

        $primary = $this->primary($customer);

        $this->assertSame('opportunity', $primary['key']);
        $this->assertStringContainsString('Orange Glam Booths provides Photo Booth Rental in Orange, CA.', $primary['description']['text']);
        $this->assertTrue(DescriptionProposal::valid($primary['description']['text']));
        $this->assertSame($this->uid($id), $primary['description']['uid']);
        $this->assertStringNotContainsString('$', $primary['description']['text']);
    }

    public function test_improve_with_ai_is_one_metered_gateway_call_and_never_saves(): void
    {
        [, $business] = $this->onboarded();
        $this->liveForm($business);
        $this->mock(AiGateway::class, function (MockInterface $m) {
            $m->shouldReceive('complete')->once()->withArgs(fn (AiRequest $r) => $r->lane->value === 'interactive' && $r->category->value === 'ask_assistant')
                ->andReturn(AiResult::success(self::VALUE, $this->ledgerEntry()));
        });

        $this->postJson($this->url('description.improve', $business), ['text' => 'Short', 'request_id' => 'r1'])
            ->assertOk()->assertJson(['ok' => true, 'text' => self::VALUE]);
        $this->assertNull($business->fresh()->description);
    }

    public function test_improve_refusal_is_reported_and_budget_is_not_bypassed(): void
    {
        [, $business] = $this->onboarded();
        $this->mock(AiGateway::class, fn (MockInterface $m) => $m->shouldReceive('complete')->once()->andReturn(AiResult::refused(AiRefusalReason::BudgetExhausted)));

        $this->postJson($this->url('description.improve', $business), ['text' => '', 'request_id' => 'r2'])
            ->assertStatus(422)->assertJson(['ok' => false]);
    }

    public function test_another_tenants_business_and_opportunity_are_not_reachable(): void
    {
        [, $mine, $id] = $this->onboarded('Mine Booths');
        [, $other, $otherId] = $this->onboarded('Other Booths');
        $this->authenticateAs(Business::find($mine->id)->customer);
        $this->noAi();

        $this->postJson($this->url('description.improve', $other), ['text' => '', 'request_id' => 'x'])->assertNotFound();
        $this->postJson($this->url('description.approve', $mine, [$this->uid($otherId)]), ['value' => self::VALUE, 'base' => hash('sha256', '')])->assertNotFound();
    }

    public function test_approve_uses_the_trusted_workflow_and_is_verified_before_completion(): void
    {
        [$customer, $business, $id] = $this->onboarded();
        $this->liveForm($business);
        Queue::fake();
        $url = $this->url('description.approve', $business, [$this->uid($id)]);
        $body = ['value' => self::VALUE, 'base' => hash('sha256', '')];

        $this->postJson($url, $body)->assertOk()->assertJson(['state' => 'pending']);
        $this->assertSame(OpportunityStatus::InProgress, \App\Models\Opportunity::find($id)->status);
        $this->assertNull($business->fresh()->description, 'Nothing is written until the queued, verified execution runs.');

        // A double submit never creates a second execution.
        $this->postJson($url, $body);
        Queue::assertPushed(ExecuteOpportunityAction::class, 1);

        Queue::pushed(ExecuteOpportunityAction::class)->first()->handle(app(OpportunityManager::class), app(OpportunityActionExecutor::class));

        $this->assertSame(self::VALUE, $business->fresh()->description);
        $this->assertSame(OpportunityStatus::Completed, \App\Models\Opportunity::find($id)->status);
        $this->assertNotSame('opportunity', $this->primary($customer)['key'] ?? null, 'The completed description is no longer recommended.');
    }

    public function test_invalid_and_stale_proposals_cannot_write(): void
    {
        [, $business, $id] = $this->onboarded();
        Queue::fake();
        $url = $this->url('description.approve', $business, [$this->uid($id)]);

        $this->postJson($url, ['value' => 'too short', 'base' => hash('sha256', '')])->assertStatus(422);

        DB::table('businesses')->where('id', $business->id)->update(['description' => 'A newer description saved elsewhere.']);
        $this->postJson($url, ['value' => self::VALUE, 'base' => hash('sha256', '')])->assertStatus(409)->assertJson(['state' => 'stale']);

        $this->assertSame('A newer description saved elsewhere.', $business->fresh()->description);
        Queue::assertNothingPushed();
    }

    public function test_a_double_submit_while_pending_never_confirms_or_enqueues_again(): void
    {
        [, $business, $id] = $this->onboarded();
        $this->liveForm($business);
        Queue::fake();
        $url = $this->url('description.approve', $business, [$this->uid($id)]);
        $body = ['value' => self::VALUE, 'base' => hash('sha256', '')];

        $this->postJson($url, $body)->assertOk()->assertJson(['state' => 'pending']);
        $executions = DB::table('opportunity_action_executions')->count();
        $this->postJson($url, $body)->assertOk()->assertJson(['state' => 'pending']);
        $transitions = DB::table('opportunity_transitions')->count();
        // A different value with a valid but stale 64-character base reaches the InProgress branch and still only reports pending.
        $this->postJson($url, ['value' => 'A different value that is also comfortably longer than fifty characters.', 'base' => hash('sha256', 'stale')])
            ->assertOk()->assertJson(['state' => 'pending']);

        Queue::assertPushed(ExecuteOpportunityAction::class, 1);
        $this->assertSame($executions, DB::table('opportunity_action_executions')->count(), 'No second execution.');
        $this->assertSame($transitions, DB::table('opportunity_transitions')->count(), 'No second confirmation.');
        $this->assertSame(OpportunityStatus::InProgress, \App\Models\Opportunity::find($id)->status);
        $this->assertNull($business->fresh()->description);
    }

    public function test_a_different_value_after_approval_was_requested_is_refused(): void
    {
        [, $business, $id] = $this->onboarded();
        Queue::fake();
        $action = \App\Models\Opportunity::find($id)->recommended_action;
        $action['parameters'] = ['value' => self::VALUE];
        DB::table('opportunities')->where('id', $id)->update(['recommended_action' => json_encode($action), 'recommended_action_hash' => (new OpportunityActionHash())->compute($action), 'status' => 'awaiting_approval', 'approval_expires_at' => now()->addHour()]);
        $url = $this->url('description.approve', $business, [$this->uid($id)]);

        $this->postJson($url, ['value' => 'Another description that is long enough to pass the fifty character minimum.', 'base' => hash('sha256', '')])
            ->assertStatus(409)->assertJson(['state' => 'mismatch']);

        Queue::assertNothingPushed();
        $this->assertSame(OpportunityStatus::AwaitingApproval, \App\Models\Opportunity::find($id)->status);
        $this->assertNull($business->fresh()->description);
    }

    public function test_an_already_completed_opportunity_reports_completed_without_executing_again(): void
    {
        [, $business, $id] = $this->onboarded();
        Queue::fake();
        DB::table('opportunities')->where('id', $id)->update(['status' => 'completed']);

        $this->postJson($this->url('description.approve', $business, [$this->uid($id)]), ['value' => self::VALUE, 'base' => hash('sha256', '')])
            ->assertOk()->assertJson(['state' => 'completed']);

        Queue::assertNothingPushed();
        $this->assertNull($business->fresh()->description, 'The controller never writes the description itself.');
    }

    public function test_the_ai_improve_flow_locks_the_text_and_never_overwrites_newer_edits_silently(): void
    {
        $view = file_get_contents(resource_path('views/customer/dashboard/home/v2/_description-proposal.blade.php'));

        // The textarea is read-only for the whole request and its previous state is restored on success, refusal and network error.
        $this->assertStringContainsString('text.readOnly = true; }', $view);
        $this->assertStringContainsString('text.readOnly = prevReadOnly', $view);
        // Defence in depth: if the text differs from what was sent, replacing it needs explicit confirmation.
        $this->assertStringContainsString('text.value !== before && !window.confirm', $view);
    }

    public function test_the_detail_page_returns_to_home_and_other_recommendations_still_render(): void
    {
        [, $business, $id] = $this->onboarded();
        $other = $this->recommendation($business, ['type' => 'missing_phone', 'title' => 'Add a phone']);

        foreach ([$id, $other] as $opportunityId) {
            $this->get(route('customer.opportunities.show', $opportunityId))->assertOk()->assertSee('Back to Home')->assertDontSee('Back to recommendations');
        }
    }

    private function minutesAgo(int $minutes): string
    {
        return now()->utc()->subMinutes($minutes)->format('Y-m-d H:i:s');
    }

    private function ledgerEntry(): \App\Models\AiUsageLedgerEntry
    {
        return new \App\Models\AiUsageLedgerEntry();
    }
}
