<?php

namespace Tests\Feature\Acquisition;

use App\Models\AcquisitionPurpose;
use App\Models\CrmPipeline;
use App\Models\Form;
use App\Models\NicheBlueprintVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acquisition\Support\InstallsEducationNiches;
use Tests\TestCase;

class TutoringBlueprintInstallTest extends TestCase
{
    use InstallsEducationNiches;
    use RefreshDatabase;

    public function test_install_into_a_fresh_business_creates_two_pipelines_and_two_separate_purposes(): void
    {
        $this->seedNiche('tutoring_exam_prep');
        [, $business] = $this->businessInstalledFrom('tutoring_education');

        $records = $this->installationRecords($business);
        $this->assertSame('installed', $records->get('tutoring_student_pipeline')?->state->value, json_encode($records->map(fn ($r) => [$r->component_key => $r->state->value.' '.$r->error_code])->values()->all()));
        $this->assertSame('installed', $records->get('tutoring_teacher_pipeline')?->state->value);

        $pipelines = CrmPipeline::query()->where('business_id', $business->id)->orderBy('position')->get();
        $this->assertSame(['Student Enrollment', 'Teacher Recruitment'], $pipelines->pluck('name')->all());

        $student = AcquisitionPurpose::query()->where('business_id', $business->id)->where('purpose_key', 'student_enrollment')->firstOrFail();
        $teacher = AcquisitionPurpose::query()->where('business_id', $business->id)->where('purpose_key', 'teacher_recruitment')->firstOrFail();

        $this->assertSame($pipelines[0]->id, $student->crm_pipeline_id);
        $this->assertSame($pipelines[1]->id, $teacher->crm_pipeline_id);
        $this->assertNotSame($student->form_id, $teacher->form_id);
        $this->assertSame('recurring_lessons', $student->calculator_key);
        $this->assertSame('recruitment', $teacher->calculator_key);
        $this->assertSame('student', $student->outcome_type);
        $this->assertSame('hire', $teacher->outcome_type);
        $this->assertNull($student->economics, 'No Business number is ever seeded.');
        $this->assertNull($teacher->economics);
    }

    public function test_each_form_opens_opportunities_in_its_own_pipeline(): void
    {
        $this->seedNiche('tutoring_exam_prep');
        [, $business] = $this->businessInstalledFrom('tutoring_education');

        $student = AcquisitionPurpose::query()->where('business_id', $business->id)->where('purpose_key', 'student_enrollment')->firstOrFail();
        $teacher = AcquisitionPurpose::query()->where('business_id', $business->id)->where('purpose_key', 'teacher_recruitment')->firstOrFail();

        $studentForm = Form::query()->findOrFail($student->form_id);
        $teacherForm = Form::query()->findOrFail($teacher->form_id);

        $this->assertSame($student->crm_pipeline_id, $studentForm->currentVersion()->opportunity_pipeline_id);
        $this->assertSame($teacher->crm_pipeline_id, $teacherForm->currentVersion()->opportunity_pipeline_id);
        $this->assertSame('Student inquiry', $studentForm->name);
        $this->assertSame('Teacher application', $teacherForm->name);
    }

    public function test_the_pipelines_use_canonical_crm_semantics(): void
    {
        $this->seedNiche('tutoring_exam_prep');
        [, $business] = $this->businessInstalledFrom('tutoring_education');

        foreach (CrmPipeline::query()->where('business_id', $business->id)->get() as $pipeline) {
            $first = $pipeline->stages()->first();
            $this->assertSame('new_inquiry', $first->semantic_key);
            $this->assertNotContains('won', $pipeline->stages->pluck('semantic_key')->all(), 'Won and lost are Opportunity statuses, not stages.');
        }
    }

    public function test_the_seed_is_idempotent_and_keeps_one_published_version(): void
    {
        $this->seedNiche('tutoring_exam_prep');
        $this->artisan('blueprint:seed-niche', ['niche' => 'tutoring_exam_prep'])->assertExitCode(0);

        $this->assertSame(1, NicheBlueprintVersion::query()->count());
    }
}
