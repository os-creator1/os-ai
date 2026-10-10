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
 * Tutoring ads decision acceptance: the two acquisition purposes (Student
 * Enrollment, Teacher Recruitment) judged on their own economics, end to end
 * through the pure deterministic engine.
 *
 * The money values are the previously discussed ILLUSTRATION, not defaults:
 * a lesson priced 10.00 with 6.50 of direct cost contributes 3.50, so 5 / 10 /
 * 20 / 30 paid lessons contribute 17.50 / 35 / 70 / 105. The business in this
 * file expects 20 lessons (70), wins 1 in 5 qualified inquiries, and accepts
 * 35 per enrolled student (hard 50) => derived target cost per qualified
 * inquiry 35 x 20% = 7.00 and ceiling 50 x 20% = 10.00.
 *
 * Tenancy (Business isolation, provider boundaries) is proven by the HTTP
 * tests in tests/Feature/Acquisition; this file proves the decisions.
 */
class TutoringAdsDecisionAcceptanceTest extends TestCase
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
    private function student(array $answers = [], array $unknown = []): EconomicsProfile
    {
        return (new RecurringLessonsCalculator)->profile($answers + [
            'lesson_price' => 10, 'variable_cost_per_lesson' => 6.5, 'median_paid_lessons' => 20,
            'qualified_to_customer_pct' => 20, 'target_cac' => 35, 'hard_cac' => 50,
        ], $unknown);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function teacher(array $answers = []): EconomicsProfile
    {
        return (new RecruitmentCalculator)->profile($answers + [
            'target_qualified_cpl' => 5, 'hard_cpl' => 9, 'target_cac' => 80, 'hard_cac' => 120,
            'screened_to_interview_pct' => 50, 'interview_to_hire_pct' => 40,
        ], []);
    }

    /** @param array<string, mixed> $o */
    private function studentInput(array $o = []): AdsDecisionInput
    {
        return new AdsDecisionInput(...($o + [
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
            'economics' => $this->student(),
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
            'focusCampaignUid' => 'camp-student',
            'purposeUid' => 'purpose-student',
            'businessCurrency' => 'EUR',
        ]));
    }

    /** @param array<string, mixed> $o */
    private function teacherInput(array $o = []): AdsDecisionInput
    {
        return new AdsDecisionInput(...($o + [
            'provider' => 'meta',
            'purposeName' => 'Teacher Recruitment',
            'outcomeType' => 'hire',
            'labels' => [
                'person' => 'teacher', 'lead' => 'qualified applicant', 'leads' => 'qualified applicants',
                'outcome' => 'hired teacher', 'outcomes' => 'hired teachers',
                'cost_per_lead' => 'Cost / qualified applicant', 'cost_per_outcome' => 'Cost / hire',
                'pipeline_cta' => 'Review teacher applicants',
            ],
            'pipelineLinked' => true,
            'economics' => $this->teacher(),
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
            'focusCampaignUid' => 'camp-teacher',
            'purposeUid' => 'purpose-teacher',
            'businessCurrency' => 'EUR',
        ]));
    }

    private function text(AdsDecision $d): string
    {
        return strtolower($d->headline . ' ' . implode(' ', $d->reasons) . ' ' . implode(' ', $d->notes) . ' ' . $d->doNotChange . ' ' . $d->nextReview);
    }

    // --------------------------------------------------------------- economics

    public function test_contribution_per_student_follows_the_owners_lessons_not_a_hard_coded_default(): void
    {
        foreach ([5 => 17_500_000, 10 => 35_000_000, 20 => 70_000_000, 30 => 105_000_000] as $lessons => $expected) {
            $profile = $this->student(['median_paid_lessons' => $lessons]);

            $this->assertSame(3_500_000, $profile->contributionPerUnitMicros);
            $this->assertSame($expected, $profile->contributionLtvMicros, "{$lessons} lessons");
        }

        // Another business, another price: nothing is carried over from the example.
        $other = $this->student(['lesson_price' => 20, 'variable_cost_per_lesson' => 12, 'median_paid_lessons' => 10]);
        $this->assertSame(80_000_000, $other->contributionLtvMicros);
    }

    public function test_target_cpl_is_target_cac_times_the_conversion_rate_and_only_when_both_are_known(): void
    {
        $profile = $this->student();

        $this->assertSame(7_000_000, $profile->targetCplMicros, '35 x 20%');
        $this->assertSame(10_000_000, $profile->hardCplMicros, '50 x 20%');

        $noRate = $this->student([], ['qualified_to_customer_pct']);
        $this->assertNull($noRate->targetCplMicros, 'An unknown rate must not produce a target.');
        $this->assertNull($noRate->hardCplMicros);
        $this->assertSame(35_000_000, $noRate->targetCacMicros, 'The CAC the owner typed is kept.');

        $noRateAtAll = (new RecurringLessonsCalculator)->profile(['target_cac' => 35], []);
        $this->assertNull($noRateAtAll->targetCplMicros);
    }

    public function test_a_missing_conversion_rate_asks_for_input_instead_of_judging_lead_cost(): void
    {
        $economics = $this->student([], ['qualified_to_customer_pct']);
        $d = $this->engine()->decide($this->studentInput(['economics' => $economics, 'spendMicros' => 40 * self::EUR, 'inquiries' => 6, 'qualified' => 5]));

        $this->assertSame(AdsDecisionState::NotEnoughData, $d->state);
        $this->assertSame(AdsDecisionCtaKind::SetTargets, $d->ctaKind);
        $this->assertStringContainsString('expected conversion rate', $this->text($d));
        $this->assertNotEmpty($d->nextReview);
    }

    public function test_unknown_lessons_never_become_zero_or_a_profit_claim(): void
    {
        $economics = $this->student([], ['median_paid_lessons']);

        $this->assertNull($economics->contributionLtvMicros);

        $d = $this->engine()->decide($this->studentInput([
            'economics' => $economics, 'spendMicros' => 100 * self::EUR, 'inquiries' => 30, 'qualified' => 20, 'outcomes' => 4,
        ]));

        $this->assertSame(AdsDecisionState::KeepRunning, $d->state);
        $this->assertFalse($d->claimsProfit);
        $this->assertStringNotContainsString('profitable', str_replace('whether these ads are profitable is not known', '', $this->text($d)));
        $rows = array_column($d->evidence, 'value', 'label');
        $this->assertSame('Not known yet', $rows['Expected contribution per enrolled student']);
    }

    // ------------------------------------------------------- the decision matrix

    public function test_wait_when_too_little_has_been_spent_to_expect_a_lead(): void
    {
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 3 * self::EUR]));

        $this->assertSame(AdsDecisionState::Wait, $d->state);
        $this->assertNotEmpty($d->nextReview);
        $this->assertStringContainsString('more spend', (string) $d->nextReview);
    }

    public function test_not_enough_data_when_a_costly_result_rests_on_a_handful_of_leads(): void
    {
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 40 * self::EUR, 'inquiries' => 4, 'qualified' => 3]));

        $this->assertSame(AdsDecisionState::NotEnoughData, $d->state);
        $this->assertStringContainsString('not enough evidence', $this->text($d));
        $this->assertNotEmpty($d->nextReview);
    }

    public function test_check_tracking_when_clicks_arrive_but_no_inquiry_is_ever_recorded(): void
    {
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 50 * self::EUR, 'clicks' => 120, 'attributedTouches' => 0]));

        $this->assertSame(AdsDecisionState::CheckTracking, $d->state);
        $this->assertSame(AdsDecisionCtaKind::FixTracking, $d->ctaKind);
        $this->assertStringContainsString('do not pause or edit the ads', $this->text($d));
        $this->assertStringContainsString('120 clicks', $this->text($d));
        $this->assertStringContainsString('shows up in your pipeline', (string) $d->nextReview);
    }

    public function test_check_tracking_when_the_provider_reports_far_more_results_than_were_recorded(): void
    {
        // Meta says 24 results, MotionGrove recorded 3 inquiries (12.5% < 25%).
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 90 * self::EUR, 'providerResults' => 24.0, 'inquiries' => 3, 'qualified' => 3]));

        $this->assertSame(AdsDecisionState::CheckTracking, $d->state);
        $this->assertStringContainsString('reports 24 results', $this->text($d));
        $this->assertStringContainsString('recorded only 3 inquiries', $this->text($d));
        $this->assertStringContainsString('do not pause or edit the ads', $this->text($d));
        $rows = array_column($d->evidence, 'value', 'label');
        $this->assertSame('Inconsistent', $rows['Tracking health']);

        // Roughly consistent numbers are not a tracking problem.
        $ok = $this->engine()->decide($this->studentInput(['spendMicros' => 90 * self::EUR, 'providerResults' => 24.0, 'inquiries' => 18, 'qualified' => 12]));
        $this->assertNotSame(AdsDecisionState::CheckTracking, $ok->state);
    }

    public function test_act_when_qualified_leads_cost_more_than_the_most_the_owner_would_pay(): void
    {
        // 12 qualified at 14.00 each: 2x the 7.00 target and above the 10.00 ceiling.
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 168 * self::EUR, 'inquiries' => 20, 'qualified' => 12, 'outcomes' => 1]));

        $this->assertSame(AdsDecisionState::Act, $d->state);
        $text = $this->text($d);
        $this->assertStringContainsString('14.00', $text);
        $this->assertStringContainsString('10.00', $text);
        $this->assertStringContainsString('7.00', $text);
        $this->assertNotEmpty($d->nextReview);
        $this->assertContains($d->ctaKind, [AdsDecisionCtaKind::OpenCampaign, AdsDecisionCtaKind::ReviewAd, AdsDecisionCtaKind::ReviewLandingPage, AdsDecisionCtaKind::ReviewSearchTerms]);
    }

    public function test_act_never_names_a_specific_ad_it_has_no_data_for(): void
    {
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 168 * self::EUR, 'inquiries' => 20, 'qualified' => 12, 'outcomes' => 1]));

        $this->assertStringContainsString('does not receive lead results per ad', $this->text($d));
        $this->assertStringContainsString('cannot say which campaign, ad set or ad is responsible', $this->text($d));
        $this->assertSame('camp-student', $d->focusCampaignUid, 'The link points at a campaign of this goal, not a guessed ad.');
    }

    public function test_fix_the_funnel_when_leads_are_affordable_but_nobody_enrolls(): void
    {
        // 12 qualified at 5.00 (under the 7.00 target), none enrolled.
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 60 * self::EUR, 'inquiries' => 18, 'qualified' => 12, 'outcomes' => 0]));

        $this->assertSame(AdsDecisionState::FixTheFunnel, $d->state);
        $this->assertSame(AdsDecisionCtaKind::OpenPipeline, $d->ctaKind);
        $this->assertStringContainsString('do not change the ads', strtolower((string) $d->doNotChange));
        $this->assertStringContainsString('after the lead, not before it', $this->text($d));
        $this->assertStringContainsString('followed up', (string) $d->nextReview);
    }

    public function test_keep_running_when_enrollment_cost_meets_the_target_with_enough_outcomes_and_profit_is_proven(): void
    {
        // 4 enrolled students from 100.00 => 25.00 each: under the 35.00 target and under the 70.00 contribution.
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 100 * self::EUR, 'inquiries' => 30, 'qualified' => 20, 'outcomes' => 4]));

        $this->assertSame(AdsDecisionState::KeepRunning, $d->state);
        $this->assertTrue($d->claimsProfit);
        $this->assertStringContainsString('25.00', $this->text($d));
        $this->assertStringContainsString('70.00', $this->text($d));
        $this->assertNotEmpty($d->doNotChange);
        $this->assertStringContainsString('whichever comes first', (string) $d->nextReview);
    }

    public function test_expensive_leads_are_forgiven_when_the_enrolled_student_cost_is_fine(): void
    {
        // qualified lead 12.50 (over target) but 4 students at 25.00 each.
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 100 * self::EUR, 'inquiries' => 12, 'qualified' => 8, 'outcomes' => 4]));

        $this->assertSame(AdsDecisionState::KeepRunning, $d->state);
        $this->assertStringContainsString('not a reason to pause', $this->text($d));
    }

    public function test_watch_when_no_lead_has_come_yet_and_spend_is_building(): void
    {
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 14 * self::EUR]));

        $this->assertSame(AdsDecisionState::Watch, $d->state);
        $this->assertStringContainsString('more spend', (string) $d->nextReview);
        $this->assertNotEmpty($d->doNotChange);
    }

    // ------------------------------------------------- the ~3x no-lead guardrail

    public function test_three_times_the_target_with_no_lead_asks_for_an_investigation_and_pauses_nothing(): void
    {
        // Target 5.00, spend 15.00, zero leads, tracking healthy, plenty of clicks.
        $economics = $this->student(['target_cac' => null, 'hard_cac' => null, 'target_qualified_cpl' => 5, 'hard_cpl' => 9]);
        $this->assertSame(5_000_000, $economics->targetCplMicros);

        $d = $this->engine()->decide($this->studentInput(['economics' => $economics, 'spendMicros' => 15 * self::EUR]));

        $this->assertSame(AdsDecisionState::Act, $d->state);
        $this->assertStringContainsString('3× your target', $this->text($d));
        $this->assertStringContainsString('do not pause or delete the ads on this signal alone', strtolower((string) $d->doNotChange));
        $this->assertStringContainsString('never pauses anything for you', (string) $d->doNotChange);

        // There is no pause/stop/disable call to action anywhere in the vocabulary.
        foreach (AdsDecisionCtaKind::cases() as $kind) {
            $this->assertDoesNotMatchRegularExpression('/pause|stop|disable|delete/i', $kind->name . ' ' . $kind->value);
        }
        $this->assertDoesNotMatchRegularExpression('/^(pause|stop|disable)/i', (string) $d->ctaLabel);
    }

    public function test_just_under_the_multiple_is_only_a_watch(): void
    {
        $economics = $this->student(['target_cac' => null, 'hard_cac' => null, 'target_qualified_cpl' => 5, 'hard_cpl' => 9]);
        $d = $this->engine()->decide($this->studentInput(['economics' => $economics, 'spendMicros' => 14.9 * self::EUR]));

        $this->assertSame(AdsDecisionState::Watch, $d->state);
    }

    public function test_the_multiple_is_a_policy_parameter_not_a_hidden_constant(): void
    {
        $economics = $this->student(['target_cac' => null, 'hard_cac' => null, 'target_qualified_cpl' => 5, 'hard_cpl' => 9]);
        $input = $this->studentInput(['economics' => $economics, 'spendMicros' => 15 * self::EUR]);

        $strict = new AdsDecisionEngine(new AdsDecisionPolicy(['zero_result_act_at_multiple' => 5.0] + AdsDecisionPolicy::fromConfig()->all()));

        $this->assertSame(AdsDecisionState::Watch, $strict->decide($input)->state);
        $this->assertSame(3.0, AdsDecisionPolicy::fromConfig()->zeroResultActAtMultiple());
    }

    public function test_the_multiple_waits_for_enough_clicks_before_blaming_the_ads(): void
    {
        $economics = $this->student(['target_cac' => null, 'hard_cac' => null, 'target_qualified_cpl' => 5, 'hard_cpl' => 9]);
        $d = $this->engine()->decide($this->studentInput(['economics' => $economics, 'spendMicros' => 15 * self::EUR, 'clicks' => 12, 'impressions' => 1000]));

        $this->assertSame(AdsDecisionState::Watch, $d->state);
        $this->assertStringContainsString('too few to say the ads are at fault', $this->text($d));
        $this->assertStringContainsString('30 clicks in total', (string) $d->nextReview);
    }

    public function test_the_multiple_never_fires_when_tracking_is_unhealthy(): void
    {
        $economics = $this->student(['target_cac' => null, 'hard_cac' => null, 'target_qualified_cpl' => 5, 'hard_cpl' => 9]);
        $d = $this->engine()->decide($this->studentInput(['economics' => $economics, 'spendMicros' => 15 * self::EUR, 'clicks' => 100, 'attributedTouches' => 0]));

        $this->assertSame(AdsDecisionState::CheckTracking, $d->state);
    }

    public function test_recruitment_uses_its_own_multiple_not_the_student_one(): void
    {
        // Same 5.00 target and 15.00 spend as the student example: 3x. A student goal acts; a teacher goal only watches.
        $student = $this->engine()->decide($this->studentInput([
            'economics' => $this->student(['target_cac' => null, 'hard_cac' => null, 'target_qualified_cpl' => 5, 'hard_cpl' => 9]),
            'spendMicros' => 15 * self::EUR,
        ]));
        $teacher = $this->engine()->decide($this->teacherInput(['spendMicros' => 15 * self::EUR]));
        $teacherLater = $this->engine()->decide($this->teacherInput(['spendMicros' => 25 * self::EUR]));

        $this->assertSame(AdsDecisionState::Act, $student->state);
        $this->assertSame(AdsDecisionState::Watch, $teacher->state);
        $this->assertSame(AdsDecisionState::Act, $teacherLater->state, '5x the target with no qualified applicant');
        $this->assertStringContainsString('qualified applicant', $this->text($teacherLater));
    }

    // ----------------------------------------------- recruitment is its own funnel

    public function test_recruitment_is_judged_on_applicants_and_hires_never_on_student_economics(): void
    {
        $d = $this->engine()->decide($this->teacherInput(['spendMicros' => 80 * self::EUR, 'inquiries' => 14, 'qualified' => 10, 'outcomes' => 1]));
        $text = $this->text($d);

        $this->assertStringNotContainsString('student', $text);
        $this->assertFalse($d->claimsProfit, 'A hire has no revenue: recruitment can never claim profit.');
        $this->assertStringContainsString('hired teacher', $text . ' ' . implode(' ', array_column($d->evidence, 'label')));
        $rows = array_column($d->evidence, 'value', 'label');
        $this->assertArrayNotHasKey('Expected contribution per hired teacher', $rows);
    }

    public function test_recruitment_cheap_applicants_who_are_never_hired_are_a_funnel_problem(): void
    {
        // 12 qualified applicants at 4.00 (under the 5.00 target), none hired.
        $d = $this->engine()->decide($this->teacherInput(['spendMicros' => 48 * self::EUR, 'inquiries' => 20, 'qualified' => 12, 'outcomes' => 0]));

        $this->assertSame(AdsDecisionState::FixTheFunnel, $d->state);
        $this->assertSame('Review teacher applicants', $d->ctaLabel);
        $this->assertStringNotContainsString('student', $this->text($d));
    }

    public function test_the_same_numbers_give_different_verdicts_under_different_purposes(): void
    {
        // 6 hires cost 12.00 each from 72.00: fine for 80 per hire. As students against a 35 CAC they would also pass, so
        // tighten the student CAC to show the purpose's OWN economics decide, not a shared threshold.
        $facts = ['spendMicros' => 72 * self::EUR, 'inquiries' => 20, 'qualified' => 12, 'outcomes' => 6];
        $tightStudent = $this->student(['target_cac' => 8, 'hard_cac' => 10, 'target_qualified_cpl' => null, 'hard_cpl' => null]);

        $teacher = $this->engine()->decide($this->teacherInput($facts));
        $student = $this->engine()->decide($this->studentInput($facts + ['economics' => $tightStudent]));

        $this->assertSame(AdsDecisionState::KeepRunning, $teacher->state);
        $this->assertNotSame(AdsDecisionState::KeepRunning, $student->state);
        $this->assertSame('purpose-teacher', $teacher->purposeUid);
        $this->assertSame('purpose-student', $student->purposeUid);
    }

    // ------------------------------------------------------- every decision explains itself

    public function test_every_actionable_decision_carries_evidence_and_a_measurable_next_review(): void
    {
        $scenarios = [
            'wait' => $this->studentInput(['spendMicros' => 3 * self::EUR]),
            'watch' => $this->studentInput(['spendMicros' => 14 * self::EUR]),
            'act' => $this->studentInput(['spendMicros' => 168 * self::EUR, 'inquiries' => 20, 'qualified' => 12, 'outcomes' => 1]),
            'funnel' => $this->studentInput(['spendMicros' => 60 * self::EUR, 'inquiries' => 18, 'qualified' => 12]),
            'keep' => $this->studentInput(['spendMicros' => 100 * self::EUR, 'inquiries' => 30, 'qualified' => 20, 'outcomes' => 4]),
            'tracking' => $this->studentInput(['spendMicros' => 50 * self::EUR, 'attributedTouches' => 0]),
            'mismatch' => $this->studentInput(['spendMicros' => 90 * self::EUR, 'providerResults' => 24.0, 'inquiries' => 3, 'qualified' => 3]),
            'no data' => $this->studentInput(['spendMicros' => 40 * self::EUR, 'inquiries' => 4, 'qualified' => 3]),
            'no target' => $this->studentInput(['economics' => $this->student([], ['target_cac', 'hard_cac', 'qualified_to_customer_pct']), 'spendMicros' => 40 * self::EUR]),
            'no pipeline' => $this->studentInput(['pipelineLinked' => false]),
            'currency' => $this->studentInput(['currencyMatchesBusiness' => false, 'businessCurrency' => 'USD']),
        ];

        foreach ($scenarios as $name => $input) {
            $d = $this->engine()->decide($input);

            $this->assertNotEmpty($d->reasons, "{$name}: reasons");
            $this->assertNotEmpty($d->evidence, "{$name}: evidence");
            $this->assertNotEmpty($d->nextReview, "{$name}: next review");
            $this->assertNotNull($d->ctaKind, "{$name}: where to act");
            $this->assertDoesNotMatchRegularExpression('/\b\d+ (days?|weeks?)\b/i', $this->text($d), "{$name}: reviews are evidence, not calendar");
        }
    }

    public function test_the_evidence_shows_the_inquiry_level_figures_the_owner_asked_for(): void
    {
        $d = $this->engine()->decide($this->studentInput(['spendMicros' => 100 * self::EUR, 'inquiries' => 20, 'qualified' => 15, 'outcomes' => 4]));
        $rows = array_column($d->evidence, 'value', 'label');

        $this->assertStringContainsString('5.00', $rows['Cost per inquiry']);
        $this->assertSame('20%', $rows['Inquiry to enrolled student rate']);
        $this->assertStringContainsString('70.00', $rows['Expected contribution per enrolled student']);

        $none = array_column($this->engine()->decide($this->studentInput(['spendMicros' => 5 * self::EUR]))->evidence, 'value', 'label');
        $this->assertSame('Not enough data', $none['Cost per inquiry']);
        $this->assertSame('Not enough data', $none['Inquiry to enrolled student rate']);
    }
}
