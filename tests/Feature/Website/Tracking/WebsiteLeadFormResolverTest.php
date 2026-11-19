<?php

namespace Tests\Feature\Website\Tracking;

use App\Enums\Forms\FormDeploymentSource;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormSubmissionService;
use App\Library\NicheBlueprint\Niches\DogBoardingBlueprint;
use App\Library\NicheBlueprint\Niches\TutoringBlueprint;
use App\Library\Website\Forms\WebsiteLeadFormResolver;
use App\Library\Website\GuidedGeneration\MediaBindingService;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\Website;
use App\Models\WebsiteForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Slice 3A — the website lead form is the niche blueprint's own Forms-module form, resolved from the business's real
 * installation records and live Website-source deployment; never by name, never the legacy `form` section when a module form
 * exists, and never a broken or foreign reference.
 */
class WebsiteLeadFormResolverTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private Business $business;

    private $workspace;

    private $location;

    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        [$owner, $this->business, $this->workspace] = $this->formsTenant();
        $this->location = $this->formsLocation($this->business, 'Downtown');
        $this->formsPipeline($this->business);
        $this->website = Website::create(['business_id' => $this->business->id, 'name' => 'Harbor Site', 'status' => \App\Enums\Website\WebsiteStatus::Draft]);
        $this->authenticateAs($owner);
    }

    /** A form recorded as installed by a blueprint component for $business (drafts unless $live). */
    private function installed(string $componentKey, string $name, bool $live = true, ?Business $business = null, ?Business $formOwner = null): Form
    {
        $business ??= $this->business;
        $formOwner ??= $business;
        $form = $this->makeForm($formOwner, ['name' => $name], $live);

        (new BusinessBlueprintComponentInstallation())->forceFill([
            'business_id' => $business->id, 'blueprint_id' => 1, 'component_key' => $componentKey, 'component_type' => 'form',
            'installed_from_version' => 1, 'state' => 'installed', 'required_feature_key' => 'forms', 'installed_record_id' => $form->id,
        ])->save();

        return $form;
    }

    private function offer(Form $form, ?Business $business = null, $location = null): FormDeployment
    {
        return app(FormManager::class)->setDeployment($business ?? $this->business, $form, $location ?? $this->location, true, FormDeploymentSource::Website);
    }

    private function resolver(): WebsiteLeadFormResolver
    {
        return app(WebsiteLeadFormResolver::class);
    }

    public function test_a_photo_booth_site_selects_the_installed_pb3_lead_form_over_other_forms(): void
    {
        $v2 = $this->installed('photo_booth_form_availability', 'Check availability');
        $this->offer($v2);
        $lookalike = $this->makeForm($this->business, ['name' => 'Check Availability'], true);
        $this->offer($lookalike);
        $v3 = $this->installed('pb3_form_lead', 'Check Availability');
        $deployment = $this->offer($v3);

        $embed = $this->resolver()->resolve($this->business);

        $this->assertSame($deployment->uid, $embed['uid']);
        $this->assertSame('pb3_form_lead', $embed['component_key']);
        $this->assertSame($v3->uid, $embed['form_uid']);
    }

    public function test_a_v2_only_installation_selects_its_availability_form(): void
    {
        $v2 = $this->installed('photo_booth_form_availability', 'Check availability');
        $deployment = $this->offer($v2);

        $this->assertSame($deployment->uid, $this->resolver()->resolve($this->business)['uid']);
        $this->assertSame('photo_booth_form_availability', $this->resolver()->inspect($this->business)['component_key']);
    }

    public function test_another_niche_selects_its_own_lead_form_not_a_photo_booth_one_and_never_a_staff_form(): void
    {
        $this->offer($this->installed(TutoringBlueprint::TEACHER_FORM, 'Teacher application'));
        $this->assertSame(WebsiteLeadFormResolver::STATE_NOT_INSTALLED, $this->resolver()->inspect($this->business)['state'], 'a teacher application is not a website lead form');

        $stay = $this->installed(DogBoardingBlueprint::FORM_STAY, 'Request a Stay');
        $deployment = $this->offer($stay);

        $embed = $this->resolver()->resolve($this->business);
        $this->assertSame($deployment->uid, $embed['uid']);
        $this->assertSame(DogBoardingBlueprint::FORM_STAY, $embed['component_key']);
    }

    public function test_a_form_that_is_only_named_like_a_lead_form_is_never_chosen(): void
    {
        $this->offer($this->makeForm($this->business, ['name' => 'Check Availability'], true));

        $this->assertNull($this->resolver()->resolve($this->business));
        $this->assertSame(WebsiteLeadFormResolver::STATE_NOT_INSTALLED, $this->resolver()->inspect($this->business)['state']);
    }

    public function test_an_installed_draft_or_undeployed_form_needs_owner_setup_and_is_never_silently_activated(): void
    {
        $draft = $this->installed('pb3_form_lead', 'Check Availability', false);

        $state = $this->resolver()->inspect($this->business);
        $this->assertSame(WebsiteLeadFormResolver::STATE_NEEDS_SETUP, $state['state']);
        $this->assertStringContainsString('Check Availability', $state['message']);
        $this->assertSame(0, FormDeployment::query()->where('form_id', $draft->id)->count(), 'nothing is deployed for the owner');
        $this->assertFalse($draft->fresh()->isActive(), 'nothing is activated for the owner');

        // Active but offered nowhere, then offered and later switched off.
        app(FormManager::class)->activate($this->business, $draft);
        $this->assertSame(WebsiteLeadFormResolver::STATE_NEEDS_SETUP, $this->resolver()->inspect($this->business)['state']);
        $this->offer($draft);
        $this->assertSame(WebsiteLeadFormResolver::STATE_READY, $this->resolver()->inspect($this->business)['state']);
        app(FormManager::class)->setDeployment($this->business, $draft->fresh(), $this->location, false, FormDeploymentSource::Website);
        $this->assertNull($this->resolver()->resolve($this->business));
    }

    public function test_a_form_or_location_of_another_business_is_never_resolved(): void
    {
        [, $other] = $this->formsTenant(\App\Enums\Entitlement\WorkspacePlanTier::Core, 'Other Business');
        $theirLocation = $this->formsLocation($other, 'Elsewhere');

        // The installation row of THIS business names a form that belongs to the other business.
        $foreign = $this->installed('pb3_form_lead', 'Check Availability', true, $this->business, $other);
        $this->offer($foreign, $other, $theirLocation);
        $this->assertSame(WebsiteLeadFormResolver::STATE_NOT_INSTALLED, $this->resolver()->inspect($this->business)['state']);

        // Their installation never leaks to us either.
        $this->assertNull($this->resolver()->resolve($other), 'the other business has no installation row of its own');

        // A deployment of our form pointing at their location is refused by the embed seam.
        $mine = $this->installed('photo_booth_form_availability', 'Check availability');
        (new FormDeployment())->forceFill(['form_id' => $mine->id, 'business_location_id' => $theirLocation->id, 'source' => FormDeploymentSource::Website->value, 'is_enabled' => true])->save();
        $this->assertSame(WebsiteLeadFormResolver::STATE_NEEDS_SETUP, $this->resolver()->inspect($this->business)['state']);
    }

    public function test_the_contact_page_gets_the_module_form_reference_and_no_legacy_website_form(): void
    {
        $deployment = $this->offer($this->installed('pb3_form_lead', 'Check Availability'));

        $bound = app(MediaBindingService::class)->bind($this->website->fresh(), [$this->contactPage()]);

        $sections = $bound['pages'][0]['sections'];
        $moduleForm = collect($sections)->firstWhere('type', 'forms_module_form');
        $this->assertSame($deployment->uid, $moduleForm['data']['forms_module_deployment_uid']);
        $this->assertSame(['heading', 'forms_module_deployment_uid'], array_keys($moduleForm['data']), 'a reference only: no url, id or markup');
        $this->assertNull(collect($sections)->firstWhere('type', 'form'));
        $this->assertSame(0, WebsiteForm::query()->where('website_id', $this->website->id)->count(), 'no duplicate legacy form row is created');
    }

    public function test_a_lead_form_that_is_not_live_yields_a_safe_contact_fallback_and_an_owner_warning(): void
    {
        $this->installed('pb3_form_lead', 'Check Availability', false);

        $bound = app(MediaBindingService::class)->bind($this->website->fresh(), [$this->contactPage()]);

        $types = array_column($bound['pages'][0]['sections'], 'type');
        $this->assertContains('contact_details', $types);
        $this->assertNotContains('forms_module_form', $types);
        $this->assertNotContains('form', $types);
        $this->assertSame(0, WebsiteForm::query()->where('website_id', $this->website->id)->count());
        $this->assertTrue(collect($bound['warnings'])->contains(fn ($w) => str_contains($w, 'not live on your website yet')));
    }

    public function test_a_business_with_no_niche_lead_form_keeps_the_existing_quote_form(): void
    {
        $bound = app(MediaBindingService::class)->bind($this->website->fresh(), [$this->contactPage()]);

        $this->assertContains('form', array_column($bound['pages'][0]['sections'], 'type'));
    }

    public function test_a_submission_through_the_bound_deployment_is_a_normal_forms_submission_with_a_contact_and_no_booking(): void
    {
        $deployment = $this->offer($this->installed('pb3_form_lead', 'Check Availability'));

        $result = app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment));

        $this->assertNotNull($result->submission->contact_id, 'the Forms submission service still resolves the Contact');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('appointments')->count(), 'an inquiry is never a booking');
    }

    private function contactPage(): array
    {
        return ['page_key' => 'contact', 'page_type' => 'contact', 'is_home' => false, 'slug' => 'contact', 'title' => 'Contact', 'seo_title' => null, 'meta_description' => null, 'sections' => [
            ['type' => 'hero', 'data' => ['heading' => 'Contact us', 'subheading' => null, 'primary_cta' => null, 'secondary_cta' => null]],
        ]];
    }
}
