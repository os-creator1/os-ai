<?php

namespace Tests\Feature\Website\Tracking;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormSubmissionService;
use App\Library\Website\Tracking\WebsiteTrackingSettings;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\BusinessLocation;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Slice 3A — the verified lead-conversion bridge: only a stored submission of a Website-source deployment is ever announced
 * to the embedding page, only the business's lead-form frame listens, and no preview ever reports a conversion.
 */
class WebsiteLeadConversionBridgeTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private const DOMAIN = 'harbor-studio.test';

    private Business $business;

    private $workspace;

    private $owner;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->formsTenant();
        $this->formsLocation($this->business, 'Downtown');
        $this->formsPipeline($this->business);
        $this->website = Website::create(['business_id' => $this->business->id, 'name' => 'Harbor Site', 'status' => \App\Enums\Website\WebsiteStatus::Draft]);
        $revision = WebsiteRevision::create(['website_id' => $this->website->id, 'version_number' => 1, 'snapshot' => ['pages' => []], 'schema_version' => 1, 'created_by' => $this->owner->user_id]);
        $this->website->forceFill(['published_revision_id' => $revision->id])->save();
        $this->website->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'x', 'verified_at' => now(), 'activated_at' => now()]);
        $this->authenticateAs($this->owner);
    }

    private function blueprintId(): int
    {
        return (int) (\App\Models\NicheBlueprint::query()->value('id') ?? \App\Models\NicheBlueprint::create(['key' => 'test_niche', 'display_name' => 'Test niche', 'broad_industry' => 'photo_booth_service'])->id);
    }

    private function tracking(): WebsiteTrackingSettings
    {
        return app(WebsiteTrackingSettings::class);
    }

    private function location(): BusinessLocation
    {
        return BusinessLocation::query()->where('business_id', $this->business->id)->firstOrFail();
    }

    private function global(): array
    {
        return ['google_tag_id' => 'G-ABC123XYZ', 'google_ads_id' => 'AW-123456789', 'google_ads_lead_label' => 'AbCdEfGhIj', 'meta_pixel_id' => '123456789012345'];
    }

    // ------------------------------------------------------------------ conversion bridge

    private function leadDeployment(\App\Models\Business $business)
    {
        $form = $this->makeForm($business, ['name' => 'Check Availability'], true);
        (new BusinessBlueprintComponentInstallation())->forceFill([
            'business_id' => $business->id, 'blueprint_id' => $this->blueprintId(), 'component_key' => 'pb3_form_lead', 'component_type' => 'form',
            'installed_from_version' => 1, 'state' => 'installed', 'required_feature_key' => 'forms', 'installed_record_id' => $form->id,
        ])->save();
        $location = $this->location();

        return app(FormManager::class)->setDeployment($business, $form, $location, true, FormDeploymentSource::Website);
    }

    private function submit($deployment)
    {
        return app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment))->submission;
    }

    public function test_only_a_stored_submission_of_a_website_deployment_is_announced_to_the_embedding_page(): void
    {
        $business = $this->business;
        $deployment = $this->leadDeployment($business);
        $submission = $this->submit($deployment);

        $html = $this->get(route('public.forms.thanks', ['deploymentUid' => $deployment->uid, 's' => $submission->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('motiongrove:lead_submitted', $html);
        $this->assertStringContainsString($submission->uid, $html);
        $this->assertStringContainsString($deployment->uid, $html);
        $this->assertStringContainsString('https://' . self::DOMAIN, str_replace('\\/', '/', $html), 'posted to the business\'s own site origin, never a wildcard');
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
        $business = $this->business;
        $form = $this->makeForm($business, ['name' => 'Direct only'], true);
        $location = $this->location();
        $direct = $this->deploy($business, $form, $location);
        $submission = $this->submit($direct);

        $this->get(route('public.forms.thanks', ['deploymentUid' => $direct->uid, 's' => $submission->uid]))->assertOk()->assertDontSee('motiongrove:lead_submitted', false);

        // A refused (invalid) submission is answered with the form again (422) — it never reaches the thank-you page.
        $lead = $this->leadDeployment($business);
        $this->post(route('public.forms.submit', [$lead->uid]), [FormSubmissionService::TOKEN_FIELD => \App\Library\Forms\FormOperationToken::issue($lead)])->assertStatus(422)->assertDontSee('motiongrove:lead_submitted', false);
    }

    public function test_only_the_lead_form_frame_is_marked_for_conversion_and_never_in_preview(): void
    {
        $customer = $this->owner;
        $business = $this->business;
        $workspace = $this->workspace;
        $website = $this->website;
        $lead = $this->leadDeployment($business);
        $other = app(FormManager::class)->setDeployment($business, $this->makeForm($business, ['name' => 'Newsletter'], true), $this->location(), true, FormDeploymentSource::Website);
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
        // Code only: the file's explanatory comments legitimately say what the events are NOT.
        $js = preg_replace('#/\*.*?\*/#s', '', file_get_contents(public_path('js/website-tracking.js')));

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
