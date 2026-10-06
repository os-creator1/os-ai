<?php

namespace Tests\Feature\Website;

use App\Enums\Forms\FormDeploymentSource;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Library\Website\Forms\WebsiteFormsModuleReferences;
use App\Library\Website\WebsiteHealthChecker;
use App\Library\Website\WebsitePublisher;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Website V1 closure — the Website-side consumer of the Forms module's embed seam:
 * a `forms_module_form` section stores the stable uid of a Website-source FormDeployment;
 * Preview re-resolves it live, Publish freezes the resolved reference into the snapshot,
 * the public page embeds the real public Form, and anything stale or foreign fails safely.
 */
class WebsiteFormsModuleSeamTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private $owner;

    private Business $business;

    private $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->formsPipeline($this->business);
        $this->website = Website::create(['business_id' => $this->business->id, 'name' => 'Harbor Site', 'status' => \App\Enums\Website\WebsiteStatus::Draft]);
        $this->authenticateAs($this->owner);
    }

    /** A live Forms-module form offered to Website pages at one Location; returns [Form, reference]. */
    private function offered(BusinessLocation $location, ?Business $business = null, string $name = 'Quote request'): array
    {
        $business ??= $this->business;
        $form = $this->makeForm($business, ['name' => $name], true);
        $reference = app(FormManager::class)->setDeployment($business, $form, $location, true, FormDeploymentSource::Website);

        return [$form, $reference];
    }

    private function homePageWith(array $sections): WebsitePage
    {
        return WebsitePage::create([
            'website_id' => $this->website->id, 'title' => 'Home', 'slug' => null, 'is_home' => true, 'sort_order' => 0, 'noindex' => false,
            'seo_title' => null, 'meta_description' => null, 'sections' => $sections,
        ]);
    }

    private function formsSection(string $uid, array $extra = []): array
    {
        return ['type' => 'forms_module_form', 'data' => array_merge(['heading' => 'Ask us anything', 'forms_module_deployment_uid' => $uid], $extra)];
    }

    private function hero(): array
    {
        return ['type' => 'hero', 'data' => ['heading' => 'Welcome', 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]];
    }

    private function websiteUrl(string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.website.' . $name, array_merge([$this->workspace->uid, $this->business->uid], $extra));
    }

    private function savePage(WebsitePage $page, array $sections)
    {
        return $this->put($this->websiteUrl('pages.update', [$page->uid]), [
            'title' => $page->title, 'slug' => $page->slug, 'is_home' => '1', 'sections' => json_encode($sections),
            'seo_title' => '', 'meta_description' => '', 'noindex' => '',
        ]);
    }

    // ------------------------------------------------------------ choosing a form

    public function test_the_owner_is_offered_only_live_website_references_of_this_business(): void
    {
        [, $mine] = $this->offered($this->downtown, null, 'Quote request');
        $form = $this->makeForm($this->business, ['name' => 'Direct only'], true);
        $this->deploy($this->business, $form, $this->downtown);
        [$off, $offRef] = $this->offered($this->uptown, null, 'Switched off');
        app(FormManager::class)->setDeployment($this->business, $off, $this->uptown, false, FormDeploymentSource::Website);
        [, $other] = $this->formsTenant(\App\Enums\Entitlement\WorkspacePlanTier::Core, 'Other Business');
        $theirLocation = $this->formsLocation($other, 'Elsewhere');
        [, $theirRef] = $this->offered($theirLocation, $other, 'Theirs');

        $options = app(WebsiteFormsModuleReferences::class)->options($this->business, (int) $this->owner->user_id);

        $this->assertSame([$mine->uid], array_column($options, 'uid'));
        $this->assertSame('Quote request — Downtown', $options[0]['label']);
        $this->assertNotContains($theirRef->uid, array_column($options, 'uid'));

        $page = $this->homePageWith([$this->hero()]);
        $this->get($this->websiteUrl('pages.edit', [$page->uid]))->assertOk()->assertSee('Form from the Forms module')->assertSee($mine->uid);
    }

    public function test_a_staff_member_restricted_to_one_location_is_not_offered_another_locations_reference(): void
    {
        [, $downtownRef] = $this->offered($this->downtown, null, 'Downtown form');
        [, $uptownRef] = $this->offered($this->uptown, null, 'Uptown form');
        $staff = $this->staffGrantedOnly($this->workspace, $this->downtown);

        $options = app(WebsiteFormsModuleReferences::class)->options($this->business, (int) $staff->user_id);

        $this->assertSame([$downtownRef->uid], array_column($options, 'uid'));
        $this->assertNotContains($uptownRef->uid, array_column($options, 'uid'));
    }

    // ------------------------------------------------------ saving a page

    public function test_the_page_editor_stores_the_stable_reference_uid_and_nothing_else(): void
    {
        [, $reference] = $this->offered($this->downtown);
        $page = $this->homePageWith([$this->hero()]);

        $this->savePage($page, [$this->hero(), $this->formsSection($reference->uid)])->assertSessionHasNoErrors();

        $stored = collect($page->fresh()->sections)->firstWhere('type', 'forms_module_form');
        $this->assertSame($reference->uid, $stored['data']['forms_module_deployment_uid']);
        $this->assertArrayNotHasKey('resolved', $stored['data'], 'a draft holds the reference, never a resolved copy');
        $this->assertStringNotContainsString((string) $reference->id . '"', json_encode($stored), 'no raw row id');
    }

    public function test_a_foreign_unknown_or_non_website_reference_is_refused_and_never_stored(): void
    {
        [, $other] = $this->formsTenant(\App\Enums\Entitlement\WorkspacePlanTier::Core, 'Other Business');
        $theirLocation = $this->formsLocation($other, 'Elsewhere');
        [, $theirs] = $this->offered($theirLocation, $other);
        $direct = $this->deploy($this->business, $this->makeForm($this->business, [], true), $this->downtown);
        $page = $this->homePageWith([$this->hero()]);

        foreach ([$theirs->uid, $direct->uid, 'not-a-real-uid'] as $bad) {
            $this->savePage($page, [$this->hero(), $this->formsSection($bad)])->assertSessionHasErrors();
            $this->assertNull(collect($page->fresh()->sections)->firstWhere('type', 'forms_module_form'), 'nothing was stored for ' . $bad);
        }
    }

    public function test_the_guided_ai_can_never_write_a_forms_module_section(): void
    {
        [, $reference] = $this->offered($this->downtown);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Library\Website\WebsiteSectionValidator::class)->validate([$this->formsSection($reference->uid)], [], false);
    }

    // ------------------------------------------------------------ preview

    public function test_preview_embeds_the_real_public_form_and_shows_nothing_for_a_stale_reference(): void
    {
        [$form, $reference] = $this->offered($this->downtown);
        $page = $this->homePageWith([$this->hero(), $this->formsSection($reference->uid)]);

        $html = $this->get($this->websiteUrl('preview', [$page->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('<iframe src="' . route('public.forms.show', [$reference->uid]) . '"', $html);
        $this->assertStringContainsString('Ask us anything', $html);
        $this->assertStringContainsString('data-form-uid="' . $form->uid . '"', $html);

        app(FormManager::class)->setDeployment($this->business, $form, $this->downtown, false, FormDeploymentSource::Website);
        $stale = $this->get($this->websiteUrl('preview', [$page->uid]))->assertOk()->getContent();
        $this->assertStringNotContainsString('<iframe', $stale);
        $this->assertStringNotContainsString('Ask us anything', $stale, 'no empty band with a heading and nothing under it');
    }

    public function test_content_can_never_inject_an_arbitrary_iframe_or_url(): void
    {
        [, $reference] = $this->offered($this->downtown);
        $page = $this->homePageWith([$this->hero(), $this->formsSection($reference->uid, ['url' => 'https://evil.example/phish', 'src' => 'https://evil.example/x', 'html' => '<iframe src="https://evil.example"></iframe>'])]);

        $html = $this->get($this->websiteUrl('preview', [$page->uid]))->assertOk()->getContent();

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertSame(1, substr_count($html, '<iframe'));
        $this->assertStringContainsString('src="' . route('public.forms.show', [$reference->uid]) . '"', $html);
    }

    // ------------------------------------------------------------ publish

    public function test_publish_freezes_the_resolved_reference_and_the_public_page_embeds_the_real_form(): void
    {
        [$form, $reference] = $this->offered($this->uptown);
        $this->homePageWith([$this->hero(), $this->formsSection($reference->uid)]);

        $revision = app(WebsitePublisher::class)->publish($this->website, $this->platformAdminId());

        $section = collect($revision->fresh()->snapshot['pages'][0]['sections'])->firstWhere('type', 'forms_module_form');
        $this->assertSame($reference->uid, $section['data']['forms_module_deployment_uid']);
        $this->assertSame(route('public.forms.show', [$reference->uid]), $section['data']['resolved']['url']);
        $this->assertSame($this->uptown->uid, $section['data']['resolved']['location_uid'], 'the reference carries its Location');
        $this->assertSame($form->uid, $section['data']['resolved']['form_uid']);

        $html = $this->get(route('public.website.home', $this->website->fresh()->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('<iframe src="' . route('public.forms.show', [$reference->uid]) . '"', $html);

        // The embedded form is a normal Forms submission at the reference's Location.
        $submission = app(FormSubmissionService::class)->submit($reference->uid, $this->submitInput($reference, [FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($reference)]))->submission;
        $this->assertSame((int) $this->uptown->id, (int) $submission->business_location_id);
        $this->assertNotNull($submission->contact_id);
    }

    public function test_a_reference_that_goes_stale_after_publishing_stays_frozen_and_the_next_publish_drops_it_safely(): void
    {
        [$form, $reference] = $this->offered($this->downtown);
        $this->homePageWith([$this->hero(), $this->formsSection($reference->uid)]);
        app(WebsitePublisher::class)->publish($this->website, $this->platformAdminId());
        $url = route('public.website.home', $this->website->fresh()->public_id);

        app(FormManager::class)->setDeployment($this->business, $form, $this->downtown, false, FormDeploymentSource::Website);

        $this->assertStringContainsString('<iframe', $this->get($url)->assertOk()->getContent(), 'a published revision is frozen');

        // Health tells the owner; publishing again never fails and renders nothing for it.
        $health = app(WebsiteHealthChecker::class)->check($this->website->fresh(), ['pages' => '/pages']);
        $this->assertSame('warn', collect($health['checks'])->firstWhere('key', 'forms_module')['status']);

        $this->travel(5)->seconds();
        app(\Illuminate\Support\Facades\Cache::class)::flush();
        app(WebsitePublisher::class)->publish($this->website->fresh(), $this->platformAdminId());
        $this->assertStringNotContainsString('<iframe', $this->get($url)->assertOk()->getContent());
    }

    public function test_publishing_refuses_a_forged_foreign_reference_that_bypassed_the_editor(): void
    {
        [, $other] = $this->formsTenant(\App\Enums\Entitlement\WorkspacePlanTier::Core, 'Other Business');
        $theirLocation = $this->formsLocation($other, 'Elsewhere');
        [, $theirs] = $this->offered($theirLocation, $other);
        $this->homePageWith([$this->hero(), $this->formsSection($theirs->uid)]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(WebsitePublisher::class)->publish($this->website, $this->platformAdminId());
    }

    public function test_the_native_website_form_is_untouched_by_the_forms_module_section(): void
    {
        $this->homePageWith([$this->hero()]);
        $legacy = \App\Models\WebsiteForm::create(['website_id' => $this->website->id, 'business_id' => $this->business->id, 'name' => 'Quote request', 'fields' => [['key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true]], 'submit_label' => 'Send']);
        WebsitePage::where('website_id', $this->website->id)->update(['sections' => [$this->hero(), ['type' => 'form', 'data' => ['heading' => 'Request a quote', 'form_uid' => $legacy->uid]]]]);

        app(WebsitePublisher::class)->publish($this->website, $this->platformAdminId());
        $html = $this->get(route('public.website.home', $this->website->fresh()->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('class="website-form-fields"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }
}
