<?php

namespace Tests\Feature\Growth;

use App\Enums\Opportunity\OpportunityFreshness;
use App\Enums\Opportunity\OpportunityStatus;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthRuleRegistry;
use App\Library\Opportunity\OpportunityActionRegistry;
use App\Library\Opportunity\OpportunityManager;
use App\Library\Opportunity\OpportunityTypeRegistry;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Growth\Concerns\CreatesGrowthFixtures;
use Tests\TestCase;

/**
 * The Growth Center sits ON the canonical Opportunity Engine: lifecycle,
 * dedupe, scoring and resolution are the engine's, and these tests prove the
 * Growth rules ride it correctly — one Opportunity per problem, resolved when
 * the evidence disappears, reopened when it returns, dismissals that come back.
 */
class GrowthEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGrowthFixtures;

    private const TYPE = 'crm.unanswered_new_leads:v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGrowthBusiness();
    }

    public function test_every_registered_rule_resolves_through_the_engine_registries(): void
    {
        foreach (GrowthRuleRegistry::all() as $key => $rule) {
            $definition = $rule->definition();

            $type = OpportunityTypeRegistry::get($definition->worker->value, $key);
            $this->assertNotNull($type, "{$key} must be in OpportunityTypeRegistry");
            $this->assertSame($definition->title, $type['title_template']);
            $this->assertNotNull(OpportunityActionRegistry::get($definition->actionKey), "{$key} action must resolve");

            // A Growth action is navigation only: it can never mutate, spend or execute.
            $action = OpportunityActionRegistry::get($definition->actionKey);
            $this->assertFalse($action['mutates_business_data']);
            $this->assertFalse($action['paid_effect']);
            $this->assertFalse($action['approval_required']);
            $this->assertArrayNotHasKey('handler_identifier', $action);
        }

        $this->assertNull(OpportunityTypeRegistry::get('sales', 'not.a.rule:v1'));
        $this->assertNull(OpportunityTypeRegistry::get('business_advisor', self::TYPE), 'business_advisor never serves a Growth type');
    }

    public function test_unanswered_leads_become_one_opportunity_with_bounded_evidence(): void
    {
        $this->unansweredDeal(48, 70000, 'Sarah');
        $this->unansweredDeal(72, 90000, 'Mike');
        $this->unansweredDeal(30, 50000, 'Emily');

        $result = $this->evaluateGrowth();

        $this->assertTrue($result['ran']);
        $this->assertSame('succeeded', $result['workers']['sales']);

        $opportunities = $this->growthOpportunities(self::TYPE);
        $this->assertCount(1, $opportunities);

        $o = $opportunities->first();
        $this->assertSame(OpportunityWorkerKey::Sales, $o->worker_key);
        $this->assertSame(OpportunityStatus::Open, $o->status);
        $this->assertSame(OpportunityFreshness::Current, $o->freshness);
        $this->assertNotNull($o->location_id, 'a deal belongs to its Location, so the finding is Location-scoped');
        $this->assertNotNull($o->recommended_action);
        $this->assertSame('growth_follow_up_new_leads', $o->recommended_action['action_key']);

        $observed = $o->evidence[0]['observed_value'];
        $this->assertSame(3, $observed['count']);
        $this->assertSame(210000, $observed['value_minor'], 'sum of canonical deal values only');
        $this->assertCount(3, $observed['uids']);
        $this->assertArrayNotHasKey('names', $observed, 'no contact names or PII in evidence');
    }

    public function test_re_evaluating_updates_the_same_opportunity_instead_of_duplicating(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();

        $this->unansweredDeal(60, 10000, 'Another');
        $this->evaluateGrowth();
        $this->evaluateGrowth();

        $opportunities = $this->growthOpportunities(self::TYPE);
        $this->assertCount(1, $opportunities);
        $this->assertSame(2, $opportunities->first()->evidence[0]['observed_value']['count']);
        $this->assertSame(1, $opportunities->first()->occurrence_number);
    }

    public function test_the_opportunity_resolves_when_the_underlying_problem_is_fixed(): void
    {
        $deal = $this->unansweredDeal();
        $this->evaluateGrowth();
        $this->assertSame(OpportunityFreshness::Current, $this->growthOpportunities(self::TYPE)->first()->freshness);

        $this->markDealContacted($deal);
        $this->evaluateGrowth();

        $o = $this->growthOpportunities(self::TYPE)->first();
        $this->assertSame(OpportunityFreshness::Stale, $o->freshness, 'no longer detected => resolved by re-evaluation, not by a button click');
        $this->assertNotNull($o->stale_at);
    }

    public function test_the_opportunity_returns_when_the_condition_returns(): void
    {
        $deal = $this->unansweredDeal();
        $this->evaluateGrowth();
        $this->markDealContacted($deal);
        $this->evaluateGrowth();

        DB::table('crm_opportunities')->where('id', $deal->id)->update(['contact_status' => 'no_contact']);
        $this->evaluateGrowth();

        $opportunities = $this->growthOpportunities(self::TYPE);
        $this->assertCount(1, $opportunities, 'the same problem keeps one identity');
        $this->assertSame(OpportunityFreshness::Current, $opportunities->first()->freshness);
    }

    public function test_dismissal_respects_a_cooldown_then_the_problem_may_return(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = $this->growthOpportunities(self::TYPE)->first();

        $customer = Customer::query()->where("user_id", $this->business->customer_id)->firstOrFail();
        app(OpportunityManager::class)->dismiss($o, $customer);

        $this->evaluateGrowth();
        $this->assertSame(OpportunityStatus::Dismissed, $o->fresh()->status, 'still inside the cooldown');

        DB::table('opportunities')->where('id', $o->id)->update(['dismissed_at' => now()->subDays(31)]);
        $this->evaluateGrowth();

        $back = $o->fresh();
        $this->assertSame(OpportunityStatus::Open, $back->status);
        $this->assertSame(2, $back->occurrence_number, 'a new occurrence, deterministic');
        $this->assertNull($back->dismissed_at);
    }

    public function test_a_snoozed_opportunity_stays_snoozed_while_it_is_still_true(): void
    {
        $this->unansweredDeal();
        $this->evaluateGrowth();
        $o = $this->growthOpportunities(self::TYPE)->first();

        $customer = Customer::query()->where("user_id", $this->business->customer_id)->firstOrFail();
        app(OpportunityManager::class)->snooze($o, $customer, now()->addDays(3));
        $this->evaluateGrowth();

        $this->assertSame(OpportunityStatus::Snoozed, $o->fresh()->status);
    }

    public function test_a_location_scoped_finding_per_location_keeps_separate_identities(): void
    {
        $first = $this->unansweredDeal();
        $second = $this->secondLocation();
        $this->unansweredDeal(50, 20000, 'Other site', $second->id);

        $this->evaluateGrowth();

        $rows = $this->growthOpportunities(self::TYPE);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [(int) $first->location_id, (int) $second->id],
            $rows->pluck('location_id')->map(fn ($v) => (int) $v)->all(),
        );
        $this->assertCount(2, $rows->pluck('fingerprint')->unique());
    }

    public function test_a_location_that_belongs_to_another_business_is_rejected_by_the_engine(): void
    {
        [, $otherBusiness] = $this->crmTenant('Other Co', 'Other Workspace');
        $foreignLocationId = (int) DB::table('business_locations')->where('business_id', $otherBusiness->id)->value('id');

        $run = app(OpportunityManager::class)->beginRun($this->business, OpportunityWorkerKey::Sales, 1);
        $candidate = new \App\Library\Opportunity\OpportunityCandidateData(
            type: self::TYPE,
            context: ['business_location_id' => $foreignLocationId],
            templateParameters: [],
            impact: 4, urgency: 4, effort: 1, confidence: 1.0,
            relevantGoalKeys: [],
            evidence: [new \App\Library\Opportunity\OpportunityEvidenceFactData(
                'crm', 'business:' . $this->business->id, 'unanswered_new_leads', ['count' => 1],
                CarbonImmutable::now(), null, null, null, null,
            )],
            actionParameters: null,
        );

        $this->expectException(\App\Library\Opportunity\Exceptions\InvalidOpportunityCandidateException::class);
        app(OpportunityManager::class)->stageCandidate($run, $candidate);
    }

    public function test_the_business_advisor_still_refuses_a_non_null_context(): void
    {
        $run = OpportunityRun::create([
            'business_id' => $this->business->id,
            'worker_key' => OpportunityWorkerKey::BusinessAdvisor->value,
            'producer_version' => 1,
            'status' => 'running',
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        $candidate = new \App\Library\Opportunity\OpportunityCandidateData(
            type: 'missing_phone',
            context: ['business_location_id' => 1],
            templateParameters: [],
            impact: 3, urgency: 3, effort: 1, confidence: 0.9,
            relevantGoalKeys: [],
            evidence: [new \App\Library\Opportunity\OpportunityEvidenceFactData(
                'business_profile', 'business:' . $this->business->id, 'phone_blank', null,
                CarbonImmutable::now(), null, null, null, null,
            )],
            actionParameters: null,
        );

        $this->expectException(\App\Library\Opportunity\Exceptions\InvalidOpportunityCandidateException::class);
        app(OpportunityManager::class)->stageCandidate($run, $candidate);
    }

    public function test_findings_beyond_the_engines_candidate_cap_do_not_fail_the_run(): void
    {
        config(['opportunity.max_candidates_per_run' => 2]);
        $this->unansweredDeal();
        $this->conversation([['incoming', 30]]);
        $this->bookingType();

        $result = $this->evaluateGrowth();

        $this->assertSame('succeeded', $result['workers']['sales']);
        $types = Opportunity::where('business_id', $this->business->id)->where('worker_key', 'sales')->pluck('type')->sort()->values()->all();
        $this->assertSame(['booking.type_not_ready:v1', 'crm.unanswered_new_leads:v1'], $types, 'the highest-priority findings are staged; the least certain one waits');
    }

    public function test_the_engine_master_switch_stops_the_evaluation_cold(): void
    {
        config(['opportunity.enabled' => false]);
        $this->unansweredDeal();

        $result = $this->evaluateGrowth();

        $this->assertFalse($result['ran']);
        $this->assertSame('engine_disabled', $result['reason']);
        $this->assertCount(0, $this->growthOpportunities());
    }
}
