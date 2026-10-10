<?php

namespace Tests\Unit\Ads;

use App\Library\Acquisition\Economics\EconomicsProfile;
use App\Library\Acquisition\Economics\RecruitmentCalculator;
use App\Library\Acquisition\Economics\RecurringLessonsCalculator;
use App\Library\Ads\Decisions\AdsDecision;
use App\Library\Ads\Decisions\AdsDecisionCtaKind;
use App\Library\Ads\Decisions\AdsDecisionEngine;
use App\Library\Ads\Decisions\AdsDecisionInput;
use App\Library\Ads\Decisions\AdsDecisionPolicy;
use App\Library\Ads\Decisions\AdsDecisionState;
use Tests\TestCase;

/**
 * The deterministic Ads decision engine: every rule of the policy, the evidence
 * hierarchy (provider result != qualified lead != customer != profitable
 * customer) and the "unknown is a valid answer" semantics. Pure — constructs
 * inputs, calls decide(), no database.
 *
 * The money values below are TEST FIXTURES for the arithmetic, not niche data:
 * lesson price 10 - cost 6 = 4 contribution x 20 lessons = 80 life contribution;
 * 25% of qualified leads become students; target cost per student 25, ceiling
 * 40 => derived target cost per qualified lead 6.25, ceiling 10.
 */
class AdsDecisionEngineTest extends TestCase
{
    private const EUR = 1_000_000;

    private function engine(): AdsDecisionEngine
    {
        return new AdsDecisionEngine(AdsDecisionPolicy::fromConfig());
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $unknown
     */
    private function studentEconomics(array $answers = [], array $unknown = []): EconomicsProfile
    {
        return (new RecurringLessonsCalculator)->profile($answers + [
            'lesson_price' => 10, 'variable_cost_per_lesson' => 6, 'median_paid_lessons' => 20,
            'qualified_to_customer_pct' => 25, 'target_cac' => 25, 'hard_cac' => 40,
        ], $unknown);
    }

    /** @param array<string, mixed> $o */
    private function input(array $o = []): AdsDecisionInput
    {
        $o += [
            'provider' => 'meta',
            'purposeName' => 'Student Enrollment',
            'outcomeType' => 'student',
            'labels' => [
                'person' => 'student', 'lead' => 'qualified student inquiry', 'leads' => 'qualified student inquiries',
                'outcome' => 'enrolled student', 'outcomes' => 'enrolled students',
                'cost_per_lead' => 'Cost / qualified lead', 'cost_per_outcome' => 'Student CAC',
                'pipeline_cta' => 'Open Student Enrollment pipeline',
            ],
            'pipelineLinked' => true,
            'economics' => $this->studentEconomics(),
            'currency' => 'EUR',
            'currencyMatchesBusiness' => true,
            'spendMicros' => 0,
            'impressions' => 20000,
            'clicks' => 300,
            'providerResults' => null,
            'inquiries' => 0,
            'qualified' => 0,
            'outcomes' => 0,
            'attributedTouches' => 10,
            'searchTermWasteMicros' => null,
            'focusCampaignUid' => 'camp-1',
            'purposeUid' => 'purpose-1',
            'businessCurrency' => 'EUR',
        ];

        return new AdsDecisionInput(...$o);
    }

    private function text(AdsDecision $d): string
    {
        return strtolower($d->headline.' '.implode(' ', $d->reasons).' '.implode(' ', $d->notes).' '.$d->doNotChange.' '.$d->nextReview);
    }

    public function test_the_derived_targets_match_the_owners_own_answers(): void
    {
        $profile = $this->studentEconomics();

        $this->assertSame(6_250_000, $profile->targetCplMicros);
        $this->assertSame(10_000_000, $profile->hardCplMicros);
        $this->assertSame(80_000_000, $profile->contributionLtvMicros);
    }

    public function test_1_cheap_qualified_leads_on_a_tiny_sample_keep_running_and_say_the_evidence_is_thin(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => 20 * self::EUR, 'inquiries' => 5, 'qualified' => 4]));

        $this->assertSame(AdsDecisionState::KeepRunning, $d->state);
        $this->assertStringContainsString('not enough evidence', $this->text($d));
        $this->assertStringContainsString('whichever comes first', (string) $d->nextReview);
        $this->assertSame(AdsDecisionCtaKind::OpenCampaign, $d->ctaKind);
    }

    public function test_1b_costly_leads_on_a_tiny_sample_are_not_enough_data_not_a_verdict(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => 30 * self::EUR, 'inquiries' => 3, 'qualified' => 2]));

        $this->assertSame(AdsDecisionState::NotEnoughData, $d->state);
        $this->assertStringContainsString('not enough evidence to conclude', $this->text($d));
    }

    public function test_2_spend_of_three_times_the_target_with_no_qualified_lead_and_healthy_tracking_is_act(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => 20 * self::EUR, 'qualified' => 0, 'inquiries' => 0, 'attributedTouches' => 5]));

        $this->assertSame(AdsDecisionState::Act, $d->state);
        $this->assertStringContainsString('3.2× your target', $this->text($d));
        $this->assertNull($d->doNotChange);
    }

    public function test_zero_result_thresholds_are_evidence_multiples_not_days(): void
    {
        $wait = $this->engine()->decide($this->input(['spendMicros' => 3 * self::EUR]));
        $watch = $this->engine()->decide($this->input(['spendMicros' => 10 * self::EUR]));
        $act = $this->engine()->decide($this->input(['spendMicros' => 17 * self::EUR]));

        $this->assertSame(AdsDecisionState::Wait, $wait->state);
        $this->assertSame(AdsDecisionState::Watch, $watch->state);
        $this->assertSame(AdsDecisionState::Act, $act->state);
        $this->assertDoesNotMatchRegularExpression('/\b\d+ days?\b/', $this->text($wait).$this->text($watch).$this->text($act));
    }

    public function test_3_cheap_leads_and_terrible_enrollment_fix_the_funnel_and_point_at_the_pipeline_not_the_ads(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => (int) (14 * 4.7 * self::EUR), 'inquiries' => 18, 'qualified' => 14, 'outcomes' => 1]));

        $this->assertSame(AdsDecisionState::FixTheFunnel, $d->state);
        $this->assertSame(AdsDecisionCtaKind::OpenPipeline, $d->ctaKind);
        $this->assertSame('Open Student Enrollment pipeline', $d->ctaLabel);
        $this->assertStringContainsString('do not change the ads', $this->text($d));
        $this->assertStringContainsString('after the lead, not before it', $this->text($d));
    }

    public function test_3b_a_costly_outcome_with_cheap_leads_is_also_a_funnel_problem(): void
    {
        // 15 qualified for 90 (6.00 each, under the 6.25 target); 2 enrolled = 45 each, over the 40 ceiling.
        $d = $this->engine()->decide($this->input(['spendMicros' => 90 * self::EUR, 'inquiries' => 20, 'qualified' => 15, 'outcomes' => 2]));

        $this->assertSame(AdsDecisionState::FixTheFunnel, $d->state);
        $this->assertSame(AdsDecisionCtaKind::OpenPipeline, $d->ctaKind);
    }

    public function test_4_higher_provider_cost_but_a_student_cost_within_target_keeps_running_and_may_claim_profit(): void
    {
        // 100 spent, 12 qualified (8.33 each: above the 6.25 lead target), 4 enrolled (25.00 each: on target).
        $d = $this->engine()->decide($this->input(['spendMicros' => 100 * self::EUR, 'inquiries' => 14, 'qualified' => 12, 'outcomes' => 4]));

        $this->assertSame(AdsDecisionState::KeepRunning, $d->state);
        $this->assertStringContainsString('not a reason to pause', $this->text($d));
        $this->assertStringContainsString('do not pause them because a surface number', $this->text($d));
        $this->assertTrue($d->claimsProfit, 'Life contribution is known and the student cost is below it.');
    }

    public function test_5_unknown_retention_or_ltv_never_produces_a_profitability_claim(): void
    {
        $economics = $this->studentEconomics([], ['median_paid_lessons']);

        $this->assertNull($economics->contributionLtvMicros);

        $d = $this->engine()->decide($this->input([
            'economics' => $economics, 'spendMicros' => 100 * self::EUR, 'inquiries' => 14, 'qualified' => 12, 'outcomes' => 4,
        ]));

        $this->assertSame(AdsDecisionState::KeepRunning, $d->state);
        $this->assertFalse($d->claimsProfit);
        $this->assertStringContainsString('not known', $this->text($d));
        $this->assertStringContainsString('whether these ads are profitable is not known', $this->text($d));
        $this->assertStringNotContainsString('profitable.', $this->text($d));
    }

    public function test_5b_delivery_looks_healthy_is_said_without_calling_the_ads_profitable(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => 50 * self::EUR, 'inquiries' => 14, 'qualified' => 12, 'outcomes' => 0]));

        $this->assertFalse($d->claimsProfit);
        $this->assertStringNotContainsString('profitable', strtolower($d->headline));
    }

    public function test_7_a_teacher_goal_speaks_only_in_applicants_and_hires(): void
    {
        $economics = (new RecruitmentCalculator)->profile([
            'target_qualified_cpl' => 12, 'hard_cpl' => 20, 'target_cac' => 80, 'hard_cac' => 120,
            'screened_to_interview_pct' => 50, 'interview_to_hire_pct' => 40,
        ], []);

        $this->assertNull($economics->contributionLtvMicros, 'Recruitment has no revenue, so it can never claim profit.');

        $d = $this->engine()->decide($this->input([
            'purposeName' => 'Teacher Recruitment', 'outcomeType' => 'hire', 'economics' => $economics,
            'labels' => ['person' => 'teacher', 'lead' => 'qualified applicant', 'leads' => 'qualified applicants', 'outcome' => 'hired teacher', 'outcomes' => 'hired teachers',
                'cost_per_lead' => 'Cost / qualified applicant', 'cost_per_outcome' => 'Cost / hire', 'pipeline_cta' => 'Review teacher applicants'],
            'spendMicros' => 100 * self::EUR, 'inquiries' => 12, 'qualified' => 10, 'outcomes' => 1,
        ]));

        $text = $this->text($d);
        $this->assertStringNotContainsString('student', $text);
        $this->assertStringContainsString('applicant', $text);
        $this->assertFalse($d->claimsProfit);
    }

    public function test_7b_a_teacher_funnel_problem_points_at_the_applicants_pipeline(): void
    {
        $economics = (new RecruitmentCalculator)->profile(['target_qualified_cpl' => 12, 'hard_cpl' => 20, 'target_cac' => 80, 'hard_cac' => 120], []);

        $d = $this->engine()->decide($this->input([
            'purposeName' => 'Teacher Recruitment', 'outcomeType' => 'hire', 'economics' => $economics,
            'labels' => ['person' => 'teacher', 'lead' => 'qualified applicant', 'leads' => 'qualified applicants', 'outcome' => 'hired teacher', 'outcomes' => 'hired teachers',
                'cost_per_lead' => 'Cost / qualified applicant', 'cost_per_outcome' => 'Cost / hire', 'pipeline_cta' => 'Review teacher applicants'],
            'spendMicros' => 100 * self::EUR, 'inquiries' => 14, 'qualified' => 12, 'outcomes' => 0,
        ]));

        $this->assertSame(AdsDecisionState::FixTheFunnel, $d->state);
        $this->assertSame('Review teacher applicants', $d->ctaLabel);
        $this->assertStringNotContainsString('student', $this->text($d));
    }

    public function test_8_meta_never_gets_google_only_search_term_advice(): void
    {
        $d = $this->engine()->decide($this->input([
            'provider' => 'meta', 'spendMicros' => 20 * self::EUR, 'searchTermWasteMicros' => 12 * self::EUR, 'clicks' => 20,
        ]));

        $this->assertSame(AdsDecisionState::Act, $d->state);
        $this->assertNotSame(AdsDecisionCtaKind::ReviewSearchTerms, $d->ctaKind);
        $this->assertStringNotContainsString('search term', $this->text($d));
        $this->assertStringNotContainsString('negative keyword', $this->text($d));
        $this->assertSame(AdsDecisionCtaKind::ReviewAd, $d->ctaKind);
    }

    public function test_9_google_search_term_waste_points_at_the_search_terms(): void
    {
        $d = $this->engine()->decide($this->input([
            'provider' => 'google', 'spendMicros' => 20 * self::EUR, 'searchTermWasteMicros' => 12 * self::EUR,
        ]));

        $this->assertSame(AdsDecisionState::Act, $d->state);
        $this->assertSame(AdsDecisionCtaKind::ReviewSearchTerms, $d->ctaKind);
        $this->assertSame('Review search terms', $d->ctaLabel);
        $this->assertStringContainsString('negative keywords', $this->text($d));
    }

    public function test_10_clicks_without_any_recorded_inquiry_check_tracking_instead_of_giving_creative_advice(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => 50 * self::EUR, 'clicks' => 120, 'inquiries' => 0, 'qualified' => 0, 'attributedTouches' => 0]));

        $this->assertSame(AdsDecisionState::CheckTracking, $d->state);
        $this->assertSame(AdsDecisionCtaKind::FixTracking, $d->ctaKind);
        $this->assertStringContainsString('do not pause or edit the ads', $this->text($d));
        $this->assertStringNotContainsString('headline', $this->text($d));
    }

    public function test_a_goal_without_a_pipeline_cannot_be_judged(): void
    {
        $d = $this->engine()->decide($this->input(['pipelineLinked' => false, 'spendMicros' => 50 * self::EUR]));

        $this->assertSame(AdsDecisionState::NotEnoughData, $d->state);
        $this->assertSame(AdsDecisionCtaKind::FinishGoalSetup, $d->ctaKind);
    }

    public function test_a_goal_with_no_targets_asks_for_them_instead_of_inventing_any(): void
    {
        $d = $this->engine()->decide($this->input(['economics' => (new RecurringLessonsCalculator)->profile([], []), 'spendMicros' => 50 * self::EUR, 'qualified' => 5, 'inquiries' => 5]));

        $this->assertSame(AdsDecisionState::NotEnoughData, $d->state);
        $this->assertSame(AdsDecisionCtaKind::SetTargets, $d->ctaKind);
    }

    public function test_a_currency_mismatch_is_never_converted_silently(): void
    {
        $d = $this->engine()->decide($this->input(['currency' => 'USD', 'currencyMatchesBusiness' => false, 'spendMicros' => 50 * self::EUR]));

        $this->assertSame(AdsDecisionState::NotEnoughData, $d->state);
        $this->assertStringContainsString('will not guess an exchange rate', $this->text($d));
    }

    public function test_inquiries_nobody_has_worked_are_a_follow_up_problem_not_an_ad_problem(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => 20 * self::EUR, 'inquiries' => 6, 'qualified' => 0]));

        $this->assertSame(AdsDecisionState::FixTheFunnel, $d->state);
        $this->assertSame(AdsDecisionCtaKind::OpenPipeline, $d->ctaKind);
        $this->assertStringContainsString('do not change the ads', $this->text($d));
    }

    public function test_a_weak_click_through_rate_does_not_override_a_student_cost_that_is_fine(): void
    {
        $d = $this->engine()->decide($this->input([
            'impressions' => 100000, 'clicks' => 200, 'spendMicros' => 100 * self::EUR, 'inquiries' => 14, 'qualified' => 12, 'outcomes' => 4,
        ]));

        $this->assertSame(AdsDecisionState::KeepRunning, $d->state);
    }

    public function test_the_business_outcome_kpi_row_is_in_order_and_never_shows_a_fake_zero_or_a_doubled_word(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => 20 * self::EUR, 'inquiries' => 0, 'qualified' => 0, 'outcomes' => 0]));

        $this->assertSame(['Spend', 'Qualified student inquiries', 'Cost / qualified lead', 'Enrolled students', 'Student CAC'], array_column($d->kpis, 'label'));
        $this->assertSame('—', $d->kpis[2]['value'], 'No qualified lead: the cost is unknown, not 0.');
        $this->assertSame('—', $d->kpis[4]['value']);
        $this->assertSame('Not enough data', $d->kpis[4]['note']);
    }

    public function test_sentences_use_the_right_article_for_the_outcome_noun(): void
    {
        $d = $this->engine()->decide($this->input(['spendMicros' => (int) (14 * 4.7 * self::EUR), 'inquiries' => 18, 'qualified' => 14, 'outcomes' => 1]));

        $this->assertStringContainsString('became an enrolled student', implode(' ', $d->reasons));
        $this->assertStringNotContainsString('qualified qualified', strtolower(implode(' ', $d->reasons).' '.implode(' ', array_column($d->kpis, 'label'))));
    }

    public function test_the_teacher_goal_adds_an_interviews_kpi_between_cost_and_hires(): void
    {
        $economics = (new RecruitmentCalculator)->profile(['target_qualified_cpl' => 12, 'hard_cpl' => 20], []);
        $d = $this->engine()->decide($this->input([
            'economics' => $economics, 'milestone' => 3,
            'labels' => ['person' => 'teacher', 'lead' => 'qualified applicant', 'leads' => 'qualified applicants', 'outcome' => 'hired teacher', 'outcomes' => 'hired teachers',
                'cost_per_lead' => 'Cost / qualified applicant', 'cost_per_outcome' => 'Cost / hire', 'milestone_label' => 'Interviews'],
            'spendMicros' => 40 * self::EUR, 'inquiries' => 4, 'qualified' => 3,
        ]));

        $this->assertSame(['Spend', 'Qualified applicants', 'Cost / qualified applicant', 'Interviews', 'Hired teachers', 'Cost / hire'], array_column($d->kpis, 'label'));
    }

    public function test_the_default_policy_is_pinned(): void
    {
        $p = AdsDecisionPolicy::fromConfig();

        $this->assertSame(1.0, $p->zeroResultWatchFromMultiple());
        $this->assertSame(2.5, $p->zeroResultActAtMultiple());
        $this->assertSame(10, $p->minQualifiedForCostJudgement());
        $this->assertSame(10, $p->minQualifiedForFunnelJudgement());
        $this->assertSame(2, $p->minOutcomesForCac());
        $this->assertSame(30, $p->minClicksForTrackingCheck());
        $this->assertSame(4, $p->reviewAfterMoreQualified());
        $this->assertSame(0.25, $p->searchTermWasteShare());
    }

    public function test_every_state_has_a_label_and_a_distinct_urgency(): void
    {
        $urgencies = [];

        foreach (AdsDecisionState::cases() as $state) {
            $this->assertNotSame('', $state->label());
            $urgencies[] = $state->urgency();
        }

        $this->assertCount(count(AdsDecisionState::cases()), array_unique($urgencies));
    }
}
