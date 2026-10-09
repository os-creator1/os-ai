<?php

namespace Tests\Feature\ExternalSite;

use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Library\ExternalSite\ExternalSiteConfig;
use App\Models\AcquisitionPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Acquisition\Support\InstallsEducationNiches;
use Tests\TestCase;

/**
 * Acquisition recommendations on the external Website, from a tutoring Business
 * installed from the real Blueprint: kept apart from the technical audit.
 */
class ExternalWebsiteAcquisitionRecommendationsTest extends TestCase
{
    use InstallsEducationNiches;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['external_site_audit.driver' => 'fake', 'external_site_audit.request_delay_ms' => 0]);
        $this->app->forgetInstance(ExternalSiteConfig::class);
        Queue::fake();
    }

    public function test_each_goal_without_a_landing_page_is_recommended_separately_and_never_called_an_error(): void
    {
        $this->seedNiche('tutoring_exam_prep');
        [$customer, $business, $workspace] = $this->businessInstalledFrom('tutoring_education');
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => 'external', 'website_url' => 'https://studio-fixture.example/']);
        $customer->user->email_verified_at = now();
        $customer->user->save();
        $this->withSession(['permissions' => collect(['access_backend', 'website', 'view_google_ads', 'view_meta_ads', 'manage_google_ads', 'manage_meta_ads'])]);
        $this->actingAs($customer->user);

        $url = route('customer.workspaces.businesses.website.external.overview', [$workspace->uid, $business->uid]);
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('Student Enrollment has no dedicated landing page.', $html);
        $this->assertStringContainsString('Teacher Recruitment has no dedicated landing page.', $html);
        $this->assertStringContainsString('They are not problems with your website', $html);
        $this->assertSame(2, substr_count($html, 'data-role="acquisition-recommendation"'));
        $this->assertStringContainsString('Pages that usually help for Teacher Recruitment', $html);
        $this->assertStringNotContainsString('data-role="issue"', $html, 'A missing marketing page is never listed as a technical issue.');

        // Each destination is configured separately; once one is chosen only that recommendation disappears.
        $student = AcquisitionPurpose::query()->where('business_id', $business->id)->where('purpose_key', 'student_enrollment')->firstOrFail();
        app(AcquisitionPurposeManager::class)->saveLinks($business, $student, ['destination_type' => 'external_url', 'destination_url' => 'https://example.lt/pamokos']);

        $after = $this->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('Student Enrollment has no dedicated landing page.', $after);
        $this->assertStringContainsString('Teacher Recruitment has no dedicated landing page.', $after);
        $this->assertStringContainsString('https://example.lt/pamokos', $after);
    }
}
