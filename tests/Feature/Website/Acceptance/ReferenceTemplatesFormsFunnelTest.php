<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Forms\FormDeploymentSource;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\FormSubmission;
use App\Models\NicheBlueprint;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\Support\WebsiteAcceptance\PhotoBoothFixture;
use Tests\TestCase;

/**
 * The Check Availability funnel of the three reference templates, end to end on an isolated fixture tenant: a live
 * Forms-module lead form turns every inquiry CTA into "Check Availability" and is embedded on the contact page; a real
 * submission through the deployment the page references creates the business's own contact and opportunity (and never
 * messaging consent); a replay converges; and once the deployment is disabled the site falls back (fail closed).
 *
 * No message is sent: nothing in this test configures a channel, a workflow or a provider. Fixture data only — this is NOT
 * authentic client-account acceptance.
 */
final class ReferenceTemplatesFormsFunnelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures, CreatesFormsFixtures, DrivesGuidedWizard {
        DrivesGuidedWizard::tenant insteadof CreatesFormsFixtures;
        CreatesWebsiteFixtures::ensureRequiredAppConfigRowsExist insteadof CreatesFormsFixtures;
        CreatesWebsiteFixtures::platformAdminId insteadof CreatesFormsFixtures;
        CreatesWebsiteFixtures::createCustomer insteadof CreatesFormsFixtures;
        CreatesWebsiteFixtures::businessAttributes insteadof CreatesFormsFixtures;
        CreatesWebsiteFixtures::createBusinessWithWorkspace insteadof CreatesFormsFixtures;
        CreatesWebsiteFixtures::assignPaidPlanFixture insteadof CreatesFormsFixtures;
    }

    private string $publicRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();

        $this->publicRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ref-funnel-' . uniqid();
        mkdir($this->publicRoot, 0755, true);
        $this->app->usePublicPath($this->publicRoot);

        $ai = \Mockery::mock(WebsiteAiGenerationClient::class);
        $ai->shouldNotReceive('complete');
        $ai->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $ai->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $ai);
    }

    protected function tearDown(): void
    {
        if ($this->publicRoot !== '' && is_dir($this->publicRoot)) {
            exec('rm -rf ' . escapeshellarg($this->publicRoot));
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function templates(): array
    {
        return ['kaleb' => ['photo_booth_reference_kaleb'], 'booths' => ['photo_booth_reference_booths'], 'greenbelt' => ['photo_booth_reference_greenbelt']];
    }

    protected function uploadPhoto(string $name = 'photo', string $unique = ''): string
    {
        $response = $this->jpost($this->url('backdrop-image.upload'), ['photo' => PhotoBoothFixture::jpeg(900, 700, $name, crc32($name . $unique) % 1000), 'name' => $name]);
        $response->assertOk();

        return (string) $response->json('path');
    }

    private function generate(string $templateKey): Website
    {
        $this->tenant(['name' => 'Funnel Fixture Booths'], ['city' => 'Boise', 'region' => 'ID']);
        $this->completeGuidedSetup();
        $this->uploadGalleryPhotos(6);
        Website::where('business_id', $this->business->id)->update(['template_key' => $templateKey]);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();

        return Website::where('business_id', $this->business->id)->firstOrFail();
    }

    private function previewHtml(Website $website, string $slug): string
    {
        $page = $slug === '' ? $website->pages()->where('is_home', true)->firstOrFail() : $website->pages()->where('slug', $slug)->firstOrFail();

        return $this->get(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $this->business->uid, $page->uid]))->assertOk()->getContent();
    }

    /** Installs the Photo Booth lead form (pb3_form_lead) for the business and deploys it on its primary location. */
    private function installLeadForm(bool $deployed = true): array
    {
        $this->formsPipeline($this->business);
        $form = $this->makeForm($this->business, ['name' => 'Check Availability'], true);
        app(FormManager::class)->update($this->business, $form, $this->leadFormInput(['create_opportunity' => true]));

        $blueprint = NicheBlueprint::query()->first() ?? NicheBlueprint::create(['key' => 'test_niche', 'display_name' => 'Test niche', 'broad_industry' => 'photo_booth_service']);
        (new BusinessBlueprintComponentInstallation())->forceFill([
            'business_id' => $this->business->id, 'blueprint_id' => $blueprint->id, 'component_key' => 'pb3_form_lead', 'component_type' => 'form',
            'installed_from_version' => 1, 'state' => 'installed', 'required_feature_key' => 'forms', 'installed_record_id' => $form->id,
        ])->save();

        $deployment = $deployed
            ? app(FormManager::class)->setDeployment($this->business, $form, $this->business->primaryLocation()->firstOrFail(), true, FormDeploymentSource::Website)
            : null;

        return [$form, $deployment];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function test_a_live_forms_module_form_drives_check_availability_and_real_submissions(string $templateKey): void
    {
        $this->tenant(['name' => 'Funnel Fixture Booths'], ['city' => 'Boise', 'region' => 'ID']);
        [, $deployment] = $this->installLeadForm();

        $this->completeGuidedSetup();
        $this->uploadGalleryPhotos(6);
        Website::where('business_id', $this->business->id)->update(['template_key' => $templateKey]);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();

        // 1. Every inquiry CTA is "Check Availability" and the contact page embeds the module form (no legacy duplicate).
        $home = $this->previewHtml($website, '');
        $this->assertStringContainsString('Check Availability', $home);
        $this->assertStringNotContainsString('>Contact us<', $home);
        $contactPage = $website->pages()->where('slug', 'photo-booth-contact')->firstOrFail();
        $contactSections = collect($contactPage->sections);
        $this->assertSame($deployment->uid, $contactSections->firstWhere('type', 'forms_module_form')['data']['forms_module_deployment_uid'] ?? null);
        $this->assertNull($contactSections->firstWhere('type', 'form'), 'no legacy Website-owned duplicate form');
        $this->assertStringContainsString($deployment->uid, $this->previewHtml($website, 'photo-booth-contact'));

        // 2. A real submission through THAT deployment: the business's own contact + opportunity, never messaging consent.
        $before = ['s' => FormSubmission::count(), 'c' => Contacts::count(), 'o' => CrmOpportunity::count()];
        $token = FormOperationToken::issue($deployment);
        $result = app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, ['email' => 'fixture.lead@example.test'], $token));
        $submission = $result->submission->fresh();
        $contact = Contacts::findOrFail($submission->contact_id);
        $this->assertSame($before['s'] + 1, FormSubmission::count());
        $this->assertSame($before['c'] + 1, Contacts::count());
        $this->assertSame($before['o'] + 1, CrmOpportunity::count());
        $this->assertSame((int) $this->business->id, (int) $contact->business_id);
        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->status, 'an inquiry is never messaging consent');
        $this->assertSame((int) $this->business->id, (int) CrmOpportunity::findOrFail($submission->crm_opportunity_id)->business_id);

        // 3. Duplicate protection: replaying the same submission converges on the same single record.
        $replay = app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, ['email' => 'fixture.lead@example.test'], $token));
        $this->assertTrue($replay->replayed);
        $this->assertSame($submission->id, $replay->submission->id);
        $this->assertSame($before['s'] + 1, FormSubmission::count());
        $this->assertSame($before['o'] + 1, CrmOpportunity::count());

        // 4. Required-field validation refuses and writes nothing.
        $counts = [FormSubmission::count(), Contacts::count(), CrmOpportunity::count()];
        try {
            app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, ['your_name' => '', 'phone' => '', 'email' => '']));
            $this->fail('A submission with no contact details must be refused.');
        } catch (ValidationException) {
            // expected
        }
        $this->assertSame($counts, [FormSubmission::count(), Contacts::count(), CrmOpportunity::count()]);

        // 5. Disabled deployment: the site fails closed — no "Check Availability" claim for a form that cannot accept anything.
        app(FormManager::class)->setDeployment($this->business, $deployment->form, $this->business->primaryLocation()->firstOrFail(), false, FormDeploymentSource::Website);
        $afterDisable = $this->previewHtml($website, '');
        $this->assertStringNotContainsString('Check Availability', $afterDisable);
        try {
            app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment));
            $this->fail('A disabled deployment must accept nothing.');
        } catch (\Throwable) {
            // expected (FormUnavailableException)
        }
        $this->assertSame($counts, [FormSubmission::count(), Contacts::count(), CrmOpportunity::count()]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function test_without_an_eligible_form_the_funnel_is_never_claimed(string $templateKey): void
    {
        $website = $this->generate($templateKey);

        $home = $this->previewHtml($website, '');
        $this->assertStringNotContainsString('Check Availability', $home);
        $this->assertNull(collect($website->pages()->where('slug', 'photo-booth-contact')->firstOrFail()->sections)->firstWhere('type', 'forms_module_form'));
    }

    public function test_an_unentitled_forms_module_never_earns_the_check_availability_claim(): void
    {
        $this->tenant(['name' => 'Funnel Fixture Booths'], ['city' => 'Boise', 'region' => 'ID']);
        [, $deployment] = $this->installLeadForm();
        $this->completeGuidedSetup();
        $this->uploadGalleryPhotos(6);
        Website::where('business_id', $this->business->id)->update(['template_key' => 'photo_booth_reference_greenbelt']);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();

        $this->assertStringContainsString('Check Availability', $this->previewHtml($website, ''));

        // The public form refuses visitors without the Forms entitlement, so the site must not advertise it.
        $this->denyFeature($this->workspace, \App\Enums\Entitlement\PlatformFeature::Forms);
        $this->assertStringNotContainsString('Check Availability', $this->previewHtml($website, ''));
        $this->assertNotNull($deployment);
    }
}
