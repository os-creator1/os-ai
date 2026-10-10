<?php

namespace Tests\Feature\Acquisition;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\CrmPipeline;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Acquisition\Support\SeedsAcquisitionFunnel;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * The "What should you do now?" panel on the provider pages, end to end: real
 * routes, real tenancy, cached rows, the deterministic engine and the CRM
 * funnel. Clock pinned to 2026-10-04; the default period is the last 30 days
 * (2026-09-04 .. 2026-10-03), so leads created on 2026-09-20 are in it.
 */
class AdsDecisionPanelHttpTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;
    use SeedsAcquisitionFunnel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp(withGoogle: true);
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    private function manager(): AcquisitionPurposeManager
    {
        return app(AcquisitionPurposeManager::class);
    }

    /** @return array{0: \App\Models\Customer, 1: Business, 2: Workspace, 3: \App\Models\MetaAdsAccount} */
    private function readyMeta(): array
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $account = $this->metaSelected($business);
        $this->asMetaUser($customer, [self::META_VIEW, self::META_MANAGE, self::G_VIEW, self::G_MANAGE]);

        return [$customer, $business, $workspace, $account];
    }

    /**
     * A student goal wired to a 4-stage pipeline, with one Meta campaign spending $spend over three days.
     *
     * @return array{0: AcquisitionPurpose, 1: CrmPipeline, 2: \App\Models\MetaAdsCampaign}
     */
    private function studentGoal(Business $business, \App\Models\MetaAdsAccount $account, int $spendMicros, string $name = 'VBE parents'): array
    {
        $pipeline = $this->seedPipeline($business, 'Student Enrollment', [['New inquiry', 'new_inquiry'], ['Contacted', 'contacted'], ['Trial booked', 'trial_booked'], ['Trial attended', 'trial_attended']]);
        $purpose = $this->manager()->createManual($business, 'Student Enrollment', (int) $pipeline->id);
        $purpose->forceFill([
            'calculator_key' => 'recurring_lessons',
            'outcome_type' => 'student',
            'labels' => ['person' => 'student', 'lead' => 'qualified student inquiry', 'leads' => 'qualified student inquiries', 'outcome' => 'enrolled student', 'outcomes' => 'enrolled students', 'cost_per_lead' => 'Cost / qualified lead', 'cost_per_outcome' => 'Student CAC', 'pipeline_cta' => 'Open Student Enrollment pipeline'],
        ])->save();
        $this->manager()->saveEconomics($business, $purpose, ['answers' => [
            'lesson_price' => 10, 'variable_cost_per_lesson' => 6, 'median_paid_lessons' => 20,
            'qualified_to_customer_pct' => 25, 'target_cac' => 25, 'hard_cac' => 40,
        ]]);

        $campaign = $this->seedMetaCampaign($account, $name);
        $this->seedMetaDays($account, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-09-20', '2026-09-22', intdiv($spendMicros, 3), 2);
        $this->manager()->assignCampaign($business, 'meta', (string) $campaign->uid, (string) $purpose->uid);

        return [$purpose->fresh(), $pipeline, $campaign];
    }

    public function test_cheap_leads_with_poor_enrollment_show_fix_the_funnel_first_and_link_to_the_student_pipeline(): void
    {
        [, $business, $workspace, $account] = $this->readyMeta();
        [, $pipeline] = $this->studentGoal($business, $account, (int) (14 * 4.7 * 1_000_000));

        // 14 qualified (moved past New inquiry), 1 of them won, all tagged as Meta.
        for ($i = 0; $i < 13; $i++) {
            $this->seedLead($business, $pipeline, 1, 'open', ['utm_source' => 'facebook']);
        }
        $this->seedLead($business, $pipeline, 3, 'won', ['utm_source' => 'facebook']);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('What should you do now?', $html);
        $this->assertStringContainsString('data-state="fix_the_funnel"', $html);
        $this->assertStringContainsString('FIX THE FUNNEL', $html);
        $this->assertStringContainsString('Do not change the ads', $html);
        $this->assertStringContainsString('after the lead, not before it', $html);
        $this->assertStringContainsString('Open Student Enrollment pipeline', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.crm.board', [$workspace->uid, $business->uid, 'pipeline' => $pipeline->uid]), html_entity_decode($html));

        // The decision comes BEFORE the period controls and the provider KPIs.
        $this->assertLessThan(strpos($html, 'data-role="period-selector"'), strpos($html, 'data-role="decision-panel"'));
        $this->assertLessThan(strpos($html, 'data-role="kpi-cards"'), strpos($html, 'data-role="outcome-kpis"'));
        foreach (['Spend', 'Qualified student inquiries', 'Cost / qualified lead', 'Enrolled students', 'Student CAC'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('Why am I seeing this?', $html);
        $this->assertSame(0, $this->fakeMeta->callCount(), 'The panel reads cached data only.');
    }

    public function test_a_campaign_without_a_goal_asks_the_owner_to_assign_one(): void
    {
        [, $business, $workspace, $account] = $this->readyMeta();
        $campaign = $this->seedMetaCampaign($account, 'Unlabelled spring campaign');
        $this->seedMetaDays($account, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-09-20', '2026-09-22', 5_000_000, 1);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Tell MotionGrove what these ads are for.', $html);
        $this->assertStringContainsString('Assign a goal so MotionGrove can judge this campaign properly.', $html);
        $this->assertStringContainsString('Unlabelled spring campaign', $html);
        $this->assertStringNotContainsString('data-role="outcome-kpis"', $html);
    }

    public function test_student_and_teacher_goals_are_judged_separately_on_the_same_provider_page(): void
    {
        [, $business, $workspace, $account] = $this->readyMeta();
        [$student, $studentPipeline] = $this->studentGoal($business, $account, 40_000_000, 'Parents autumn');

        $teacherPipeline = $this->seedPipeline($business, 'Teacher Recruitment', [['New applicant', 'new_inquiry'], ['Screened', 'screened'], ['Interviewed', 'interviewed']]);
        $teacher = $this->manager()->createManual($business, 'Teacher Recruitment', (int) $teacherPipeline->id);
        $teacher->forceFill([
            'calculator_key' => 'recruitment', 'outcome_type' => 'hire',
            'labels' => ['person' => 'teacher', 'lead' => 'qualified applicant', 'leads' => 'qualified applicants', 'outcome' => 'hired teacher', 'outcomes' => 'hired teachers', 'cost_per_lead' => 'Cost / qualified applicant', 'cost_per_outcome' => 'Cost / hire', 'pipeline_cta' => 'Review teacher applicants', 'milestone_label' => 'Interviews', 'milestone_stage' => 'interviewed'],
        ])->save();
        $this->manager()->saveEconomics($business, $teacher, ['answers' => ['target_qualified_cpl' => 12, 'hard_cpl' => 20, 'target_cac' => 80, 'hard_cac' => 120]]);

        $teacherCampaign = $this->seedMetaCampaign($account, 'Math teachers 2027');
        $this->seedMetaDays($account, MetaAdsLevel::Campaign, (int) $teacherCampaign->id, '2026-09-20', '2026-09-22', 4_000_000, 1);
        $this->manager()->assignCampaign($business, 'meta', (string) $teacherCampaign->uid, (string) $teacher->uid);

        // Student side: lots of inquiries tagged Meta. Teacher side: two screened, one interviewed.
        for ($i = 0; $i < 3; $i++) {
            $this->seedLead($business, $studentPipeline, 1, 'open', ['utm_source' => 'facebook']);
        }
        $this->seedLead($business, $teacherPipeline, 1, 'open', ['utm_source' => 'facebook']);
        $this->seedLead($business, $teacherPipeline, 2, 'open', ['utm_source' => 'facebook']);

        $html = $this->get($this->metaPage($workspace, $business))->assertOk()->getContent();
        $text = strip_tags($html);

        $this->assertStringContainsString('Student Enrollment', $text);
        $this->assertStringContainsString('Teacher Recruitment', $text);
        $this->assertSame(1, substr_count($html, 'data-role="decision-primary"'));
        $this->assertSame(1, substr_count($html, 'data-role="decision-other"'), 'The second goal is listed behind the first, not merged into it.');

        // The teacher goal's own funnel: 2 qualified applicants (both past New applicant), 1 reached Interviews.
        $facts = app(\App\Library\Ads\Decisions\PurposeFunnelReader::class)->read($teacher->fresh(), 'meta', \Carbon\CarbonImmutable::parse('2026-09-04'), \Carbon\CarbonImmutable::parse('2026-10-03'));
        $this->assertSame(['inquiries' => 2, 'qualified' => 2, 'outcomes' => 0, 'milestone' => 1], $facts);

        $studentFacts = app(\App\Library\Ads\Decisions\PurposeFunnelReader::class)->read($student, 'meta', \Carbon\CarbonImmutable::parse('2026-09-04'), \Carbon\CarbonImmutable::parse('2026-10-03'));
        $this->assertSame(3, $studentFacts['inquiries'], 'Teacher applicants never count as student inquiries.');
    }

    public function test_attribution_is_provider_scoped_a_google_tagged_lead_is_not_a_meta_lead(): void
    {
        [, $business, , $account] = $this->readyMeta();
        [$student, $pipeline] = $this->studentGoal($business, $account, 30_000_000);

        $this->seedLead($business, $pipeline, 1, 'open', ['utm_source' => 'google']);
        $this->seedLead($business, $pipeline, 1, 'open', ['gclid' => 'abc']);
        $this->seedLead($business, $pipeline, 1, 'open', ['utm_source' => 'instagram']);
        $this->seedLead($business, $pipeline, 1, 'open', []);

        $reader = app(\App\Library\Ads\Decisions\PurposeFunnelReader::class);
        $from = \Carbon\CarbonImmutable::parse('2026-09-04');
        $to = \Carbon\CarbonImmutable::parse('2026-10-03');

        $this->assertSame(1, $reader->read($student, 'meta', $from, $to)['inquiries']);
        $this->assertSame(2, $reader->read($student, 'google', $from, $to)['inquiries']);
    }

    public function test_a_goal_is_judged_only_on_its_own_business(): void
    {
        [, $business, , $account] = $this->readyMeta();
        [$student, $pipeline] = $this->studentGoal($business, $account, 30_000_000);
        $this->seedLead($business, $pipeline, 1, 'open', ['utm_source' => 'facebook']);

        [, $otherBusiness] = $this->metaTenant('Other Co');
        $otherPipeline = $this->seedPipeline($otherBusiness, 'Other pipeline', [['New inquiry', 'new_inquiry'], ['Contacted', null]]);
        $this->seedLead($otherBusiness, $otherPipeline, 1, 'open', ['utm_source' => 'facebook']);

        $facts = app(\App\Library\Ads\Decisions\PurposeFunnelReader::class)->read($student, 'meta', \Carbon\CarbonImmutable::parse('2026-09-04'), \Carbon\CarbonImmutable::parse('2026-10-03'));

        $this->assertSame(1, $facts['inquiries']);
    }

    public function test_the_google_page_shows_its_own_panel_and_never_the_meta_only_goal_campaigns(): void
    {
        [, $business, $workspace, $metaAccount] = $this->readyMeta();
        [, $pipeline] = $this->studentGoal($business, $metaAccount, 30_000_000);
        $googleAccount = $this->adsAccountFor($business, ['selected_at' => now(), 'last_successful_sync_at' => now()->subHour(), 'data_through_date' => '2026-10-03']);
        $this->seedLead($business, $pipeline, 1, 'open', ['gclid' => 'abc']);

        $html = $this->get(route('customer.workspaces.businesses.ads.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('What should you do now?', $html);
        // The goal exists, but none of the Google campaigns is assigned to it.
        $this->assertStringContainsString('Assign a goal to your campaigns.', $html);
        $this->assertSame($googleAccount->currency_code, 'USD');
    }

    public function test_the_decision_panel_copy_never_mentions_search_terms_on_meta(): void
    {
        [, $business, $workspace, $account] = $this->readyMeta();
        [, $pipeline] = $this->studentGoal($business, $account, 40_000_000);

        $html = strtolower(strip_tags($this->get($this->metaPage($workspace, $business))->assertOk()->getContent()));
        $panel = substr($html, (int) strpos($html, 'what should you do now?'), 1800);

        $this->assertStringNotContainsString('search term', $panel);
        $this->assertStringNotContainsString('negative keyword', $panel);
    }

    public function test_economics_unknown_answers_stay_unknown_and_no_profit_claim_appears(): void
    {
        [, $business, , $account] = $this->readyMeta();
        [$student] = $this->studentGoal($business, $account, 30_000_000);

        $this->manager()->saveEconomics($business, $student, ['answers' => ['lesson_price' => 10, 'variable_cost_per_lesson' => 6], 'unknown' => ['median_paid_lessons' => '1', 'qualified_to_customer_pct' => '1']]);
        $student = $student->fresh();

        $this->assertContains('median_paid_lessons', $student->unknownKeys());
        $this->assertArrayNotHasKey('median_paid_lessons', $student->answers(), 'Unknown is never stored as a number.');
        $this->assertNull($student->economicsProfile()->contributionLtvMicros);
        $this->assertFalse($student->economicsProfile()->supportsProfitabilityClaim(1_000_000));
    }
}
