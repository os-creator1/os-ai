<?php

namespace Tests\Feature\Website\Tracking;

use App\Enums\Forms\FormDeploymentSource;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormSubmissionService;
use App\Library\Website\Tracking\WebsiteTrackingSettings;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Website;
use App\Models\WebsiteTrackingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Slice 3A — owner tracking (global + page) on PUBLISHED pages and the native blog only, and the verified lead-conversion
 * bridge: only a stored submission of a Website-source deployment is ever announced to the embedding page.
 */
class WebsiteTrackingTest extends TestCase
{
    use CreatesContentFixtures;
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private const HOST = 'http://' . self::DOMAIN;

    protected function setUp(): void
    {
        parent::setUp();

        app('cache')->flush();
    }

    private function tracking(): WebsiteTrackingSettings
    {
        return app(WebsiteTrackingSettings::class);
    }

    /** @return array<string, mixed> the config the page hands the loader, or [] when none is emitted */
    private function config(string $html): array
    {
        if (! preg_match('#<script type="application/json" id="mg-tracking-config">(.*?)</script>#s', $html, $m)) {
            return [];
        }

        return json_decode($m[1], true);
    }

    private function global(array $extra = []): array
    {
        return array_merge(['google_tag_id' => 'G-ABC123XYZ', 'google_ads_id' => 'AW-123456789', 'google_ads_lead_label' => 'AbCdEfGhIj', 'meta_pixel_id' => '123456789012345'], $extra);
    }

    // ------------------------------------------------------------------ rendering

    public function test_global_tracking_loads_on_published_pages_and_the_native_blog_through_one_loader(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);
        $this->tracking()->save($website, '*', $this->global());

        foreach (['/', '/service-wedding-photo-booth-rental', '/blog', '/blog/' . $article->slug] as $path) {
            $html = $this->get(self::HOST . $path)->assertOk()->getContent();
            $config = $this->config($html);

            $this->assertSame(['G-ABC123XYZ', 'AW-123456789'], $config['google_tag_ids'], $path);
            $this->assertSame(['123456789012345'], $config['meta_pixel_ids'], $path);
            $this->assertSame([['id' => 'AW-123456789', 'label' => 'AbCdEfGhIj']], $config['ads_conversions'], $path);
            $this->assertSame(1, substr_count($html, 'js/website-tracking.js'), $path);
            $this->assertStringNotContainsString('googletagmanager.com', $html, 'the page itself loads no third-party script; the consent-aware loader does');
            $this->assertStringNotContainsString('connect.facebook.net', $html);
        }
    }

    public function test_page_tracking_adds_to_global_without_loading_the_same_tag_or_pixel_twice(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', $this->global());
        $this->tracking()->save($website, 'home', ['google_tag_id' => 'G-ABC123XYZ', 'meta_pixel_id' => '123456789012345']);
        $this->tracking()->save($website, 'photo-booth-contact', ['meta_pixel_id' => '999999999999']);

        $home = $this->config($this->get(self::HOST . '/')->getContent());
        $this->assertSame(['G-ABC123XYZ', 'AW-123456789'], $home['google_tag_ids'], 'the same Google tag is listed once');
        $this->assertSame(['123456789012345'], $home['meta_pixel_ids'], 'the same pixel is listed once');

        $contact = $this->config($this->get(self::HOST . '/photo-booth-contact')->getContent());
        $this->assertSame(['123456789012345', '999999999999'], $contact['meta_pixel_ids'], 'a page adds its own pixel to the inherited one');

        $other = $this->config($this->get(self::HOST . '/photo-booth-about')->getContent());
        $this->assertSame(['123456789012345'], $other['meta_pixel_ids'], 'other pages only inherit the global setting');
    }

    public function test_disabled_or_removed_tracking_emits_nothing(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', $this->global(['is_enabled' => false]));
        $this->assertSame([], $this->config($this->get(self::HOST . '/')->getContent()));

        $this->tracking()->save($website, '*', $this->global());
        $this->assertNotSame([], $this->config($this->get(self::HOST . '/')->getContent()));

        $this->tracking()->remove($website, '*');
        $html = $this->get(self::HOST . '/')->getContent();
        $this->assertSame([], $this->config($html));
        $this->assertStringNotContainsString('website-tracking.js', $html);
    }

    public function test_the_owner_preview_and_unpublished_state_never_run_tracking(): void
    {
        [$customer, , $workspace, $website] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', $this->global());
        $this->assertNull($this->tracking()->effective($website, 'home', false, true), 'a preview/unpublished render gets no tracking at all');

        $page = $website->pages()->create(['title' => 'Home', 'slug' => null, 'is_home' => true, 'sort_order' => 0, 'noindex' => false, 'sections' => [['type' => 'text', 'data' => ['heading' => 'Hi', 'body' => 'Draft copy.']]]]);
        $this->authenticateAs($customer);

        $html = $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $website->business->uid, $page->uid]))->assertOk()->getContent();

        $this->assertSame([], $this->config($html));
        $this->assertStringNotContainsString('website-tracking.js', $html);
    }

    public function test_owner_code_runs_only_on_the_owners_own_domain_never_on_the_shared_platform_origin(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $snippet = '<script>window.ownerPixel=1;</script>';
        $this->tracking()->save($website, '*', $this->global(['head_snippet' => $snippet]));

        $own = $this->config($this->get(self::HOST . '/')->getContent());
        $this->assertSame([$snippet], $own['snippets']);

        // The same site, on the platform path (the dashboard's origin): structured tags only, no owner code.
        $platform = $this->tracking()->effective($website, 'home', true, false);
        $this->assertSame([], $platform['snippets']);
        $this->assertSame(['G-ABC123XYZ', 'AW-123456789'], $platform['google_tag_ids']);
    }

    public function test_the_config_block_cannot_be_broken_out_of_by_owner_text(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', ['head_snippet' => '<script>var a = "</scr" + "ipt><b>x</b>";</script>']);

        $html = $this->get(self::HOST . '/')->getContent();

        $this->assertSame(1, substr_count($html, 'id="mg-tracking-config"'));
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertNotEmpty($this->config($html)['snippets']);
    }

    // ------------------------------------------------------------------ validation

    public function test_identifiers_and_owner_code_are_validated_and_length_limited(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();

        foreach ([
            ['google_tag_id' => 'UA-1'],
            ['google_ads_id' => '123'],
            ['google_ads_lead_label' => 'abcdef123', 'google_ads_id' => null],
            ['meta_pixel_id' => 'pixel'],
            ['head_snippet' => '<iframe src="https://x.example"></iframe>'],
            ['head_snippet' => '<script src="http://x.example/a.js"></script>'],
            ['head_snippet' => '<div>hi</div>'],
            ['head_snippet' => '<?php echo 1; ?>'],
            ['head_snippet' => '<!-- c --><script>1</script>'],
            ['head_snippet' => '<script>' . str_repeat('a', WebsiteTrackingSettings::MAX_SNIPPET) . '</script>'],
        ] as $bad) {
            try {
                $this->tracking()->save($website, '*', $bad);
                $this->fail('Expected refusal for ' . json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame(0, WebsiteTrackingSetting::query()->count());
            }
        }

        $this->tracking()->save($website, '*', ['head_snippet' => '<script async src="https://example.com/t.js"></script><script>for(var i=0;i<3;i++){window.n=i}</script>']);
        $this->assertSame(1, WebsiteTrackingSetting::query()->count(), 'ordinary tag-manager style code, including `i<3` in a script body, is accepted');
    }

    // ------------------------------------------------------------------ owner screen + tenancy + survival

    public function test_the_owner_manages_tracking_from_the_website_tracking_tab_and_another_tenant_cannot(): void
    {
        [$customer, , $workspace, $website] = $this->photoBoothContentTenant();
        [$intruder] = $this->photoBoothContentTenant();
        $url = route('customer.workspaces.businesses.website.tracking.index', [$workspace->uid, $website->business->uid]);
        $save = route('customer.workspaces.businesses.website.tracking.save', [$workspace->uid, $website->business->uid]);

        $this->authenticateAs($customer);
        $this->get($url)->assertOk()->assertSee('Tracking for the whole website')->assertSee('data-testid="lead-form-status"', false);
        $this->post($save, ['scope' => '*', 'is_enabled' => '1', 'require_consent' => '1', 'meta_pixel_id' => '123456789012345'])->assertSessionHasNoErrors();
        $this->assertSame('123456789012345', $this->tracking()->find($website, '*')->meta_pixel_id);
        $this->post($save, ['scope' => '*', 'meta_pixel_id' => 'nope'])->assertSessionHasErrors('meta_pixel_id');
        $this->post($save, ['scope' => 'no-such-page', 'meta_pixel_id' => '123456789012345'])->assertNotFound();

        $this->authenticateAs($intruder);
        $this->post($save, ['scope' => '*', 'meta_pixel_id' => '555555555555'])->assertStatus(404);
        $this->assertSame('123456789012345', $this->tracking()->find($website, '*')->meta_pixel_id, 'a foreign tenant changed nothing');

        $this->authenticateAs($customer);
        $this->delete(route('customer.workspaces.businesses.website.tracking.destroy', [$workspace->uid, $website->business->uid]), ['scope' => '*'])->assertRedirect();
        $this->assertNull($this->tracking()->find($website, '*'));
    }

    public function test_settings_survive_regeneration_and_never_reach_another_tenants_site(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        [, , , $other] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', $this->global());
        $this->tracking()->save($website, 'photo-booth-contact', ['meta_pixel_id' => '999999999999']);

        // Regeneration replaces every draft page row (new uids); settings are keyed by page identity, not by row.
        $website->pages()->delete();
        $website->pages()->create(['title' => 'Contact', 'slug' => 'photo-booth-contact', 'is_home' => false, 'sort_order' => 1, 'noindex' => false, 'sections' => [['type' => 'text', 'data' => ['heading' => 'Contact', 'body' => 'Hello.']]]]);

        $this->assertSame(2, WebsiteTrackingSetting::query()->where('website_id', $website->id)->count());
        $this->assertSame(['123456789012345', '999999999999'], $this->tracking()->effective($website->fresh(), 'photo-booth-contact', true, true)['meta_pixel_ids']);
        $this->assertNull($this->tracking()->effective($other, 'home', true, true), 'another tenant has no tracking');
    }

    // ------------------------------------------------------------------ conversion bridge

    private function leadDeployment(\App\Models\Business $business)
    {
        $form = $this->makeForm($business, ['name' => 'Check Availability'], true);
        (new BusinessBlueprintComponentInstallation())->forceFill([
            'business_id' => $business->id, 'blueprint_id' => 1, 'component_key' => 'pb3_form_lead', 'component_type' => 'form',
            'installed_from_version' => 1, 'state' => 'installed', 'required_feature_key' => 'forms', 'installed_record_id' => $form->id,
        ])->save();
        $location = \App\Models\BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();

        return app(FormManager::class)->setDeployment($business, $form, $location, true, FormDeploymentSource::Website);
    }

    private function submit($deployment)
    {
        return app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment))->submission;
    }

    public function test_only_a_stored_submission_of_a_website_deployment_is_announced_to_the_embedding_page(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $this->formsPipeline($business);
        $deployment = $this->leadDeployment($business);
        $submission = $this->submit($deployment);

        $html = $this->get(route('public.forms.thanks', ['deploymentUid' => $deployment->uid, 's' => $submission->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('motiongrove:lead_submitted', $html);
        $this->assertStringContainsString($submission->uid, $html);
        $this->assertStringContainsString($deployment->uid, $html);
        $this->assertStringContainsString('https://' . self::DOMAIN, $html, 'posted to the business\'s own site origin, never a wildcard');
        $this->assertStringNotContainsString("'*'", $html);
        $this->assertStringNotContainsString('postMessage(message, "*")', $html);
        foreach (['Ada Lovelace', 'example.test', '555-1234', 'Looking for a quote'] as $personal) {
            $this->assertStringNotContainsString($personal, $html, 'no contact details or answers in the announcement');
        }

        // Spam (honeypot redirect), an unknown id, a forged id of another deployment, and an old submission announce nothing.
        $this->get(route('public.forms.thanks', ['deploymentUid' => $deployment->uid]))->assertOk()->assertDontSee('motiongrove:lead_submitted', false);
        $this->get(route('public.forms.thanks', ['deploymentUid' => $deployment->uid, 's' => (string) \Illuminate\Support\Str::uuid()]))->assertDontSee('motiongrove:lead_submitted', false);
        $this->travel(20)->minutes();
        $this->get(route('public.forms.thanks', ['deploymentUid' => $deployment->uid, 's' => $submission->uid]))->assertOk()->assertDontSee('motiongrove:lead_submitted', false);
    }

    public function test_a_direct_link_form_is_never_announced_and_a_failed_submission_has_no_thank_you(): void
    {
        [, $business] = $this->photoBoothContentTenant();
        $this->formsPipeline($business);
        $form = $this->makeForm($business, ['name' => 'Direct only'], true);
        $location = \App\Models\BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $direct = $this->deploy($business, $form, $location);
        $submission = $this->submit($direct);

        $this->get(route('public.forms.thanks', ['deploymentUid' => $direct->uid, 's' => $submission->uid]))->assertOk()->assertDontSee('motiongrove:lead_submitted', false);

        // A refused (invalid) submission is answered with the form again (422) — it never reaches the thank-you page.
        $lead = $this->leadDeployment($business);
        $this->post(route('public.forms.submit', [$lead->uid]), [FormSubmissionService::TOKEN_FIELD => \App\Library\Forms\FormOperationToken::issue($lead)])->assertStatus(422)->assertDontSee('motiongrove:lead_submitted', false);
    }

    public function test_only_the_lead_form_frame_is_marked_for_conversion_and_never_in_preview(): void
    {
        [$customer, $business, $workspace, $website] = $this->photoBoothContentTenant();
        $this->formsPipeline($business);
        $lead = $this->leadDeployment($business);
        $other = app(FormManager::class)->setDeployment($business, $this->makeForm($business, ['name' => 'Newsletter'], true), \App\Models\BusinessLocation::query()->where('business_id', $business->id)->firstOrFail(), true, FormDeploymentSource::Website);
        $this->tracking()->save($website, '*', $this->global());

        $snapshotPages = fn (array $uids) => collect($uids)->map(fn ($uid) => ['type' => 'forms_module_form', 'data' => ['heading' => 'Form', 'forms_module_deployment_uid' => $uid, 'resolved' => app(\App\Library\Website\Forms\WebsiteFormsModuleReferences::class)->resolve($business, $uid)]])->all();

        $page = $website->pages()->create(['title' => 'Contact', 'slug' => 'photo-booth-contact', 'is_home' => false, 'sort_order' => 1, 'noindex' => false, 'sections' => $snapshotPages([$lead->uid, $other->uid])]);
        $this->authenticateAs($customer);
        $html = $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid, $page->uid]))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-mg-lead-form', $html, 'a draft preview never reports conversions');

        $view = view('public.website.components.forms_module_form', ['data' => $snapshotPages([$lead->uid])[0]['data'], 'website' => $website, 'isPreview' => false])->render();
        $this->assertStringContainsString('data-mg-lead-form="1"', $view);
        $this->assertStringContainsString('data-deployment-uid="' . $lead->uid . '"', $view);

        $unrelated = view('public.website.components.forms_module_form', ['data' => $snapshotPages([$other->uid])[0]['data'], 'website' => $website, 'isPreview' => false])->render();
        $this->assertStringNotContainsString('data-mg-lead-form', $unrelated, 'an unrelated form never reports a lead');
    }

    public function test_the_loader_contract_is_origin_checked_once_per_submission_consent_aware_and_lead_only(): void
    {
        $js = file_get_contents(public_path('js/website-tracking.js'));

        // Origin + source + deployment + UUID validation, and one conversion per submission id.
        $this->assertStringContainsString('event.origin !== origin', $js);
        $this->assertStringContainsString('contentWindow === event.source', str_replace('frames[i].contentWindow === event.source', 'contentWindow === event.source', $js));
        $this->assertStringContainsString("data.deployment_uid !== frame.getAttribute('data-deployment-uid')", $js);
        $this->assertStringContainsString('sent[lead.submissionUid]', $js);
        // Consent gate, and the events are a lead — never a purchase or a booking.
        $this->assertStringContainsString("consent() === 'denied'", $js);
        $this->assertStringContainsString("fbq('track', 'Lead')", $js);
        $this->assertStringContainsString("event: 'motiongrove_lead'", $js);
        foreach (['Purchase', 'purchase', 'booking_confirmed', 'CompleteRegistration', "'submit'"] as $notALead) {
            $this->assertStringNotContainsString($notALead, $js);
        }
        // No personal data in analytics payloads.
        foreach (['email', 'phone', 'consent', 'answers'] as $personal) {
            $this->assertStringNotContainsString($personal, strtolower(substr($js, strpos($js, 'function fire'), 900)));
        }
    }
}
