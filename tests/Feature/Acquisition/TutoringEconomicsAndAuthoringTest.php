<?php

namespace Tests\Feature\Acquisition;

use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Library\NicheBlueprint\Adapters\AcquisitionPurposeComponentAdapter;
use App\Library\NicheBlueprint\Adapters\BlueprintComponentAdapterRegistry;
use App\Library\NicheBlueprint\Workspace\BlueprintSurfaces;
use App\Library\NicheBlueprint\Workspace\BlueprintWorkspaceService;
use App\Library\Acquisition\PurposeWebsiteIntents;
use App\Library\Website\WebsiteBlueprintDefaults;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Acquisition\Support\InstallsEducationNiches;
use Tests\TestCase;

/**
 * Tutoring: two funnels that never mix (their questions, their economics, their
 * saved numbers, their website intents), the economics questions asked, and the
 * Platform Owner authoring seam for the new component type.
 */
class TutoringEconomicsAndAuthoringTest extends TestCase
{
    use InstallsEducationNiches;
    use RefreshDatabase;

    /** @return array{0: Business, 1: AcquisitionPurpose, 2: AcquisitionPurpose} */
    private function tutoring(): array
    {
        $this->seedNiche('tutoring_exam_prep');
        [, $business] = $this->businessInstalledFrom('tutoring_exam_prep');

        return [
            $business,
            AcquisitionPurpose::query()->where('business_id', $business->id)->where('purpose_key', 'student_enrollment')->firstOrFail(),
            AcquisitionPurpose::query()->where('business_id', $business->id)->where('purpose_key', 'teacher_recruitment')->firstOrFail(),
        ];
    }

    /** @return list<string> */
    private function keys(AcquisitionPurpose $purpose): array
    {
        return collect($purpose->question_schema)->pluck('key')->all();
    }

    public function test_student_enrollment_asks_the_recurring_lesson_economics_questions(): void
    {
        [, $student] = $this->tutoring();

        foreach (['lesson_price', 'variable_cost_per_lesson', 'median_paid_lessons', 'qualified_to_customer_pct', 'target_cac', 'hard_cac', 'target_qualified_cpl', 'hard_cpl'] as $question) {
            $this->assertContains($question, $this->keys($student));
        }

        $labels = collect($student->question_schema)->pluck('label', 'key');
        $this->assertSame('Average price charged per paid lesson', $labels['lesson_price']);
        $this->assertSame('Typical number of paid lessons per student', $labels['median_paid_lessons']);
    }

    public function test_teacher_recruitment_asks_only_recruitment_questions_and_never_student_economics(): void
    {
        [, $student, $teacher] = $this->tutoring();

        foreach (['teachers_needed', 'subjects_needed', 'target_qualified_cpl', 'hard_cpl', 'target_cac', 'hard_cac', 'application_to_screened_pct', 'screened_to_interview_pct', 'interview_to_hire_pct'] as $question) {
            $this->assertContains($question, $this->keys($teacher));
        }

        foreach (['lesson_price', 'variable_cost_per_lesson', 'median_paid_lessons'] as $studentOnly) {
            $this->assertNotContains($studentOnly, $this->keys($teacher), 'Teacher Recruitment never asks the student questions.');
        }

        $this->assertNotContains('teachers_needed', $this->keys($student));
    }

    public function test_targets_are_isolated_per_goal_and_the_business_can_edit_them_later(): void
    {
        [$business, $student, $teacher] = $this->tutoring();
        $manager = app(AcquisitionPurposeManager::class);

        $manager->saveEconomics($business, $student, ['answers' => ['lesson_price' => '20', 'variable_cost_per_lesson' => '12', 'median_paid_lessons' => '30', 'qualified_to_customer_pct' => '20', 'target_cac' => '40', 'hard_cac' => '60']]);
        $manager->saveEconomics($business, $teacher, ['answers' => ['target_qualified_cpl' => '15', 'hard_cpl' => '25', 'target_cac' => '90', 'hard_cac' => '150'], 'unknown' => ['interview_to_hire_pct' => '1']]);

        $studentProfile = $student->fresh()->economicsProfile();
        $teacherProfile = $teacher->fresh()->economicsProfile();

        $this->assertSame(8_000_000, $studentProfile->contributionPerUnitMicros, '20 - 12');
        $this->assertSame(240_000_000, $studentProfile->contributionLtvMicros, '8 x 30');
        $this->assertSame(8_000_000, $studentProfile->targetCplMicros, '40 x 20%');
        $this->assertSame(15_000_000, $teacherProfile->targetCplMicros);
        $this->assertNull($teacherProfile->contributionLtvMicros, 'A recruitment goal can never claim profit.');
        $this->assertSame(40_000_000, $studentProfile->targetCacMicros);
        $this->assertSame(90_000_000, $teacherProfile->targetCacMicros);

        // Edit the student price later: the teacher goal is untouched.
        $manager->saveEconomics($business, $student->fresh(), ['answers' => ['lesson_price' => '25', 'variable_cost_per_lesson' => '12', 'median_paid_lessons' => '30']]);

        $this->assertSame(13_000_000, $student->fresh()->economicsProfile()->contributionPerUnitMicros);
        $this->assertSame(15_000_000, $teacher->fresh()->economicsProfile()->targetCplMicros);
        $this->assertSame(['interview_to_hire_pct'], $teacher->fresh()->unknownKeys());
    }

    public function test_a_suggested_cac_is_a_suggestion_the_engine_never_uses_as_a_target(): void
    {
        [$business, $student] = $this->tutoring();
        app(AcquisitionPurposeManager::class)->saveEconomics($business, $student, ['answers' => ['lesson_price' => '10', 'variable_cost_per_lesson' => '6', 'median_paid_lessons' => '20', 'qualified_to_customer_pct' => '25']]);

        $profile = $student->fresh()->economicsProfile();

        $this->assertSame(80_000_000, $profile->contributionLtvMicros);
        $this->assertSame(24_000_000, $profile->suggestions['target_cac']['value_micros'], 'A conservative 30% of life contribution, labelled a suggestion.');
        $this->assertSame(6_000_000, $profile->suggestions['target_qualified_cpl']['value_micros']);
        $this->assertNull($profile->targetCacMicros, 'The owner has not confirmed a target, so none is used.');
        $this->assertNull($profile->targetCplMicros);
    }

    public function test_the_hosted_website_learns_both_funnel_intents_and_keeps_the_audiences_apart(): void
    {
        [$business, $student, $teacher] = $this->tutoring();

        $intents = app(PurposeWebsiteIntents::class)->forBusiness($business);
        $this->assertSame(['student_enrollment', 'teacher_recruitment'], $intents->pluck('purpose_key')->all());
        $this->assertSame('Parents and students', $student->website_intent['audience']);
        $this->assertSame('Teachers and tutors', $teacher->website_intent['audience']);
        $this->assertSame('student-enrollment', $student->website_intent['pages'][0]['page_key']);
        $this->assertSame('teach-with-us', $teacher->website_intent['pages'][0]['page_key']);

        $hints = app(WebsiteBlueprintDefaults::class)->generationHints($business, []);
        $this->assertNotNull($hints);
        $joined = implode("\n", $hints['content_prompts']);
        $this->assertStringContainsString('Lessons and exam preparation', $joined);
        $this->assertStringContainsString('Teach with us', $joined);
        $this->assertStringContainsString('Student inquiry form', $joined);
        $this->assertStringContainsString('Teacher application form', $joined);
    }

    public function test_a_business_with_no_goals_gets_no_generation_hints_from_this_seam(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        [, $business] = $this->tenant();

        $this->assertNull(app(WebsiteBlueprintDefaults::class)->generationHints($business, []));
    }

    // ----- Platform Owner authoring ----------------------------------------------------

    public function test_the_platform_owner_workspace_knows_the_goal_component_type_and_surface(): void
    {
        $this->assertTrue(BlueprintSurfaces::has('acquisition'));
        $this->assertGreaterThan(BlueprintSurfaces::ALL['forms']['rank'], BlueprintSurfaces::ALL['acquisition']['rank'], 'A goal installs after the pipelines and forms it references.');
        $this->assertTrue(app(BlueprintComponentAdapterRegistry::class)->has('acquisition_purpose'));

        $definitions = app(BlueprintWorkspaceService::class)->definitions();
        $this->assertArrayHasKey('acquisition_purpose', $definitions);
    }

    public function test_the_authoring_form_round_trips_a_rich_payload_without_losing_questions_or_intent(): void
    {
        $adapter = app(AcquisitionPurposeComponentAdapter::class);
        $payload = [
            'purpose_key' => 'wedding_bookings', 'name' => 'Wedding bookings', 'outcome_type' => 'booking', 'calculator' => 'simple_outcome',
            'labels' => ['person' => 'couple', 'outcome' => 'booked wedding'],
            'questions' => [['key' => 'target_cac', 'label' => 'What is a booked wedding worth spending to win?', 'suggested_min' => 5, 'suggested_max' => 500]],
            'guidance' => [['title' => 'Start with reviews', 'body' => 'Couples read reviews first.']],
            'website_intent' => ['audience' => 'Engaged couples', 'cta' => 'Enquiry form', 'pages' => [['page_key' => 'weddings', 'title' => 'Weddings']]],
        ];

        $adapter->validateDescriptor($payload);
        $roundTrip = $adapter->payloadFromInput($adapter->inputFromPayload($payload));

        $this->assertSame($payload['questions'], $roundTrip['questions']);
        $this->assertSame($payload['website_intent'], $roundTrip['website_intent']);
        $this->assertSame($payload['guidance'], $roundTrip['guidance']);
    }

    public function test_a_blueprint_cannot_invent_an_input_or_a_calculator(): void
    {
        $adapter = app(AcquisitionPurposeComponentAdapter::class);
        $base = ['purpose_key' => 'x', 'name' => 'X', 'outcome_type' => 'customer', 'calculator' => 'simple_outcome'];

        $adapter->validateDescriptor($base);

        $this->expectException(InvalidArgumentException::class);
        $adapter->validateDescriptor($base + ['questions' => [['key' => 'lesson_price']]]);
    }

    public function test_an_unknown_calculator_fails_publication(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(AcquisitionPurposeComponentAdapter::class)->validateDescriptor(['purpose_key' => 'x', 'name' => 'X', 'outcome_type' => 'customer', 'calculator' => 'made_up']);
    }
}
