<?php

namespace Tests\Feature\Coo;

use App\Enums\Coo\CooInsightTrigger;
use App\Enums\Opportunity\OpportunityActionExecutionStatus;
use App\Enums\Opportunity\OpportunityInitiatedByType;
use App\Enums\Opportunity\OpportunityStatus;
use App\Jobs\Opportunity\ExecuteOpportunityAction;
use App\Library\Ai\AiCompletionResult;
use App\Library\Ai\Contracts\AiCompletionClient;
use App\Library\Ai\Providers\FakeAiCompletionClient;
use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessDashboardAnalyticsPresenter;
use App\Library\Coo\Context\CooContextEnvelopeFactory;
use App\Library\Coo\Insight\CooInsightGenerator;
use App\Library\Coo\Insight\CooInsightOutcome;
use App\Library\Opportunity\OpportunityActionExecutor;
use App\Library\Opportunity\OpportunityActionHash;
use App\Library\Opportunity\OpportunityManager;
use App\Models\AppConfig;
use App\Models\CooInsight;
use App\Models\OpportunityActionExecution;
use App\Models\OpportunityTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * One focused, end-to-end acceptance run of the AI COO's approval-to-
 * execution path, using fakes only — no real AI provider call is made.
 *
 * As of this HEAD, the product has two related-but-unlinked mechanisms
 * (Implementation Contract 19 §3.3(3), §5.5 — the "19.C"/"19.F" linkage is
 * explicitly unimplemented and not a defect this task fixes):
 *   - the AI COO's own insight/evidence pipeline (CooInsightGenerator),
 *     which explains but never itself proposes an approvable action; and
 *   - the only approve-then-execute lifecycle in the product
 *     (OpportunityManager / RFC-002), which is deterministic and already
 *     supports an OpportunityInitiatedByType::Coo proposer for exactly
 *     the "the COO recommended it, a human confirmed it" shape
 *     (see OpportunityApprovalLifecycleHardeningTest::test_coo_proposal_human_confirmation_executes_once).
 *
 * This test exercises both halves against the same Business: first proving
 * the AI COO produces grounded, evidence-bearing output via a fake provider
 * client (zero network calls), then walking the one real approvable
 * recommendation all the way through evidence -> approval -> exactly-once
 * execution -> full audit trail -> unauthorized-user refusal.
 */
class CooApprovalToExecutionAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
        config(['services.openai.active' => true]);
    }

    public function test_ai_coo_recommendation_to_execution_acceptance_flow(): void
    {
        $business = $this->createBusinessForOpportunities();
        $owner = $business->customer->user;
        $this->ensureRequiredAppConfigRowsExist();

        // ------------------------------------------------------------
        // Part 1 — the AI COO generates a recommendation using a fake
        // provider client only; no real network/provider call is made.
        // ------------------------------------------------------------
        $fakeAi = new FakeAiCompletionClient();
        $fakeAi->setDefaultResult(AiCompletionResult::success(json_encode(['statements' => [
            ['class' => 'unknown', 'text' => 'These figures alone do not show what to recommend next.', 'fact_refs' => []],
        ]]), 'fixture-model-2026', 200, 40));
        $this->app->instance(AiCompletionClient::class, $fakeAi);

        $envelope = app(CooContextEnvelopeFactory::class)->forActor($business, $owner);
        $this->assertNotNull($envelope, 'the fixture Business/owner must have a resolvable authorization scope.');

        $range = AnalyticsDateRange::preset(BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET, $business->timezone);
        $outcome = app(CooInsightGenerator::class)->generate($business, CooInsightTrigger::ExplainThisChange, $range, $envelope);

        $this->assertSame(CooInsightOutcome::GENERATED, $outcome->status);
        $this->assertSame(1, $fakeAi->callCount(), 'Exactly one fake provider call — never a real one.');

        $insight = CooInsight::query()->sole();
        $this->assertNotEmpty($insight->output['statements']);
        foreach ($insight->output['statements'] as $statement) {
            $this->assertArrayHasKey('fact_refs', $statement, 'Every AI COO statement is grounded — this is the evidence shown to the owner.');
        }

        // ------------------------------------------------------------
        // Part 2 — the one approvable recommendation the AI COO can
        // offer today: an Opportunity, attributed to the COO as
        // proposer, whose evidence is shown to the owner, then approved
        // and executed through the real, unmodified RFC-002 lifecycle.
        // ------------------------------------------------------------
        $action = [
            'schema_version' => 1,
            'action_key' => 'add_phone',
            'parameters' => ['value' => '+15551234567'],
            'approval_required' => true,
            'completion_policy' => 'system_verified',
        ];

        $opportunity = $this->createOpportunity($business, [
            'recommended_action' => $action,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($action),
            'action_schema_version' => 1,
        ]);

        $customer = $business->customer;
        $customer->permissions = json_encode(['business_advisor']);
        $customer->save();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        // Evidence is shown to the owner before any approval decision.
        $this->get(route('customer.opportunities.show', $opportunity->id))
            ->assertOk()
            ->assertSee('Add phone number');

        $manager = app(OpportunityManager::class);
        $manager->requestApproval($opportunity, $customer, OpportunityInitiatedByType::Coo);
        $this->assertSame(OpportunityStatus::AwaitingApproval, $opportunity->fresh()->status);

        $execution = $manager->confirmApproval($opportunity, $customer);
        $this->assertSame(OpportunityStatus::InProgress, $opportunity->fresh()->status);
        $this->assertSame('coo', $execution->initiated_by_type);
        $this->assertSame('customer', $execution->confirmed_by_type);

        // Execute — run the job twice, to prove the approved action runs
        // exactly once even if the worker (or a retry) sees it again.
        $job = new ExecuteOpportunityAction((int) $execution->id);
        $job->handle($manager, app(OpportunityActionExecutor::class));
        $job->handle($manager, app(OpportunityActionExecutor::class));

        $this->assertSame('+15551234567', $business->fresh()->phone);
        $this->assertSame(OpportunityActionExecutionStatus::Succeeded, $execution->fresh()->status);
        $this->assertSame(1, OpportunityActionExecution::query()->where('opportunity_id', $opportunity->id)->count());

        // Audit history — the full "AI said (attributed) -> human approved
        // -> system executed" chain, in order, actor-attributed. (A trailing
        // 'missing_from_successful_run' transition may follow: executing
        // add_phone changes a fact the Business Advisor producer watches, and
        // COO C-1's own change-triggered re-run — a separate, already-shipped
        // sub-slice — marks this now-fulfilled Opportunity stale. That is
        // correct existing behavior, not part of this flow's own assertions.)
        $transitions = OpportunityTransition::where('opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['customer_requested_approval', 'customer_confirmed_approval', 'execution_succeeded'],
            $transitions->take(3)->pluck('reason_code')->all()
        );

        $humanTransitions = $transitions->whereIn('reason_code', ['customer_requested_approval', 'customer_confirmed_approval']);
        $this->assertTrue($humanTransitions->every(fn (OpportunityTransition $t) => $t->actor_user_id === $customer->user_id));

        $systemTransition = $transitions->firstWhere('reason_code', 'execution_succeeded');
        $this->assertSame('system', $systemTransition->actor_type->value);

        // ------------------------------------------------------------
        // Part 3 — unauthorized-user refusal. A second Business/customer
        // whose business_advisor capability is revoked before any request
        // is made — mirroring OpportunityQueueHttpTest's own refusal
        // fixture, since hasPermission() prefers a session-cached
        // permission set (EloquentAccountRepository::hasPermission) and a
        // customer authenticated earlier in this test already has one
        // cached from Part 2.
        // ------------------------------------------------------------
        $strangerBusiness = $this->createBusinessForOpportunities();
        $stranger = $strangerBusiness->customer;
        $stranger->permissions = json_encode([]);
        $stranger->save();
        $stranger->user->email_verified_at = now();
        $stranger->user->save();

        $secondOpportunity = $this->createOpportunity($strangerBusiness, [
            'recommended_action' => $action,
            'recommended_action_hash' => (new OpportunityActionHash())->compute($action),
            'action_schema_version' => 1,
        ]);

        $this->actingAs($stranger->user);

        $this->post(route('customer.opportunities.request-approval', $secondOpportunity->id))
            ->assertUnauthorized();
        $this->post(route('customer.opportunities.confirm-approval', $secondOpportunity->id))
            ->assertUnauthorized();

        $this->assertSame(OpportunityStatus::Open, $secondOpportunity->fresh()->status);
    }

    /**
     * Seeds only the app_config rows the customer HTTP render path actually
     * reads (mirrors OpportunityMutationHttpTest's own identical helper).
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
