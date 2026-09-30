<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsitePageStrategy;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\WebsiteFormSubmission;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Independent-review correction round 4: the earlier browser acceptance
 * sweep checked the guided-generated Contact page's visible content but
 * never proved lead submission still works. This proves it end to end,
 * through the REAL public rendering + submission routes — never a direct
 * model assertion alone — for a Website built (and later rebuilt)
 * entirely through the guided-generation pipeline (mocked AI, live AI
 * unavailable in this environment).
 */
class GuidedGenerationContactFormSubmissionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function validBatchFor(array $plan): string
    {
        return json_encode(['pages' => collect($plan)->map(fn ($page) => [
            'page_key' => $page['page_key'],
            'title' => $page['title'],
            'seo_title' => $page['title'] . ' seo title',
            'meta_description' => $page['title'] . ' meta description for this specific page.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => $page['title']]],
            ],
        ])->values()->all()]);
    }

    public function test_a_visitor_can_submit_the_guided_generated_contact_pages_form(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);
        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $this->mockAiClient($this->validBatchFor($plan));

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business, $website, $template, $customer->user_id, 'idem-public-form-1');
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);

        $this->authenticateAsCustomer($customer);
        app(WebsitePublisher::class)->publish($website->fresh(), $customer->user_id);
        $website = $website->fresh();

        $contactPage = $website->pages()->where('slug', 'photo-booth-contact')->firstOrFail();
        $form = $website->forms()->sole();

        $home = $this->get(route('public.website.home', $website->public_id))->assertOk();
        $home->assertSee(route('public.website.page', [$website->public_id, 'photo-booth-contact']), false);

        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid, $contactPage->uid]);

        $this->post($submitUrl, [
            'name' => 'Jane Visitor',
            'phone' => '5551234567',
        ])->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
    }

    public function test_a_visitor_can_submit_the_rebuilt_contact_pages_form_after_republishing(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->seed(WebsiteTemplateSeeder::class);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        // The deterministic starter draft creates and publishes the real
        // form first; a rebuild must retain it, not require the owner to
        // recreate it before the site works again.
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);
        $this->authenticateAsCustomer($customer);
        app(WebsitePublisher::class)->publish($website->fresh(), $customer->user_id);

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website->fresh());
        $this->mockAiClient($this->validBatchFor($plan));

        $attempt = app(GuidedGenerationCommitService::class)->rebuild($business, $website->fresh(), $template, $customer->user_id, 'idem-public-form-rebuild');
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status);

        // Rebuild only replaces the mutable draft — the pre-rebuild
        // published revision is still what visitors see until the owner
        // explicitly republishes (unchanged rebuild semantics).
        app(WebsitePublisher::class)->publish($website->fresh(), $customer->user_id);
        $website = $website->fresh();

        $contactPage = $website->pages()->where('slug', 'photo-booth-contact')->firstOrFail();
        $form = $website->forms()->sole();

        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid, $contactPage->uid]);

        $this->post($submitUrl, [
            'name' => 'Jane Rebuilt-Site Visitor',
            'phone' => '5559876543',
        ])->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
    }
}
