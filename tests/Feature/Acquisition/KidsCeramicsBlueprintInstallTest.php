<?php

namespace Tests\Feature\Acquisition;

use App\Library\Acquisition\PurposeWebsiteIntents;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Models\AcquisitionPurpose;
use App\Models\CrmPipeline;
use App\Models\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acquisition\Support\InstallsEducationNiches;
use Tests\TestCase;

/**
 * Kids Ceramics Studio: a local, physical Business with ONE customer pipeline and
 * ONE acquisition purpose, local-first guidance, and no teacher or recruitment
 * concept anywhere.
 */
class KidsCeramicsBlueprintInstallTest extends TestCase
{
    use InstallsEducationNiches;
    use RefreshDatabase;

    public function test_a_fresh_ceramics_business_gets_one_pipeline_one_purpose_and_a_routed_form(): void
    {
        $this->seedNiche('kids_ceramics');
        [, $business] = $this->businessInstalledFrom('kids_activities');

        $pipelines = CrmPipeline::query()->where('business_id', $business->id)->get();
        $this->assertSame(['Class Enrollment'], $pipelines->pluck('name')->all(), 'Exactly one customer pipeline.');
        $this->assertSame(['New inquiry', 'Contacted', 'Trial class booked', 'Attended'], $pipelines[0]->stages->pluck('name')->all());
        $this->assertSame('new_inquiry', $pipelines[0]->stages->first()->semantic_key);

        $purposes = AcquisitionPurpose::query()->where('business_id', $business->id)->get();
        $this->assertCount(1, $purposes, 'One acquisition purpose.');
        $purpose = $purposes[0];
        $this->assertSame('class_enrollment', $purpose->purpose_key);
        $this->assertSame('class_enrollment', $purpose->calculator_key);
        $this->assertSame($pipelines[0]->id, $purpose->crm_pipeline_id);

        $form = Form::query()->findOrFail($purpose->form_id);
        $this->assertSame($pipelines[0]->id, $form->currentVersion()->opportunity_pipeline_id);
        $this->assertNull($purpose->economics, 'No Business number is seeded.');
    }

    public function test_nothing_about_teachers_or_recruitment_exists_for_ceramics(): void
    {
        $this->seedNiche('kids_ceramics');
        [, $business] = $this->businessInstalledFrom('kids_activities');

        $purpose = AcquisitionPurpose::query()->where('business_id', $business->id)->firstOrFail();
        $everything = strtolower(json_encode([$purpose->toArray(), CrmPipeline::query()->where('business_id', $business->id)->with('stages')->get()->toArray()]));

        foreach (['teacher', 'recruit', 'applicant', 'hire'] as $word) {
            $this->assertStringNotContainsString($word, $everything, "A ceramics Business must not see [$word].");
        }

        $questions = collect($purpose->question_schema)->pluck('key')->all();
        $this->assertContains('enrollment_type', $questions);
        $this->assertNotContains('median_paid_lessons', $questions, 'Recurring-student economics is not forced on a studio.');
    }

    public function test_the_niche_carries_local_orientation_booking_and_seo_guidance(): void
    {
        $this->seedNiche('kids_ceramics');
        [, $business] = $this->businessInstalledFrom('kids_activities');
        $purpose = AcquisitionPurpose::query()->where('business_id', $business->id)->firstOrFail();

        $pages = collect($purpose->website_intent['pages'])->pluck('page_key')->all();
        foreach (['kids-ceramics-classes', 'trial-class', 'birthday-workshops', 'holiday-workshops', 'about-the-studio', 'find-us'] as $expected) {
            $this->assertContains($expected, $pages);
        }

        $guidance = strtolower(json_encode($purpose->guidance));
        $this->assertStringContainsString('google business profile', $guidance);
        $this->assertStringContainsString('reviews', $guidance);
        $this->assertStringContainsString('meta ads are optional', $guidance);

        $seo = app(BlueprintConfigReader::class)->seoStrategy($business);
        $patterns = collect($seo['keyword_patterns'] ?? [])->pluck('pattern')->all();
        $this->assertContains('kids ceramics classes {city}', $patterns);
        $this->assertContains('ceramics birthday workshop {city}', $patterns);
        $this->assertStringNotContainsString('vilnius', strtolower(json_encode($seo)));
    }

    public function test_a_ceramics_business_is_asked_for_the_missing_landing_pages_through_the_shared_seam(): void
    {
        $this->seedNiche('kids_ceramics');
        [, $business] = $this->businessInstalledFrom('kids_activities');

        $missing = app(PurposeWebsiteIntents::class)->withoutDestination($business);

        $this->assertCount(1, $missing);
        $this->assertSame('Class Enrollment', $missing[0]->name);
    }
}
