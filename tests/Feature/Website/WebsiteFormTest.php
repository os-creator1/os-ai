<?php

namespace Tests\Feature\Website;

use App\Library\Crm\CrmPipelineService;
use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsiteFormSubmissionService;
use App\Library\Website\WebsitePublisher;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The Forms slice: the smallest reusable foundation (WebsiteForm,
 * WebsiteFormSubmission, one closed `form` section type), shipping
 * exactly one preset (Photo Booth quote request). A real submission
 * always produces a durable WebsiteFormSubmission; it additionally
 * produces a Contact (matched or created by phone) and, only when the
 * Business already has a CRM pipeline, a CrmOpportunity tagged
 * `source = website_form`.
 */
class WebsiteFormTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private function createQuoteForm(Website $website): WebsiteForm
    {
        return $website->forms()->create([
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Request a quote',
        ]);
    }

    private function publishFormPage(Website $website, WebsiteForm $form): void
    {
        $this->homePage($website);
        $formPage = $this->subPage($website, 'quote', [
            'sections' => [$this->section('form', ['form_uid' => $form->uid])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
    }

    // ---------------------------------------------------------------
    // Business side: create the form, see it, tenancy-safe submissions
    // ---------------------------------------------------------------

    public function test_business_can_create_the_quote_request_form_once(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.forms.index', [$workspace->uid, $business->uid]))
            ->assertOk()->assertSee('Create quote request form');

        $this->post(route('customer.workspaces.businesses.website.forms.store', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.website.forms.index', [$workspace->uid, $business->uid]));

        $this->assertSame(1, $website->forms()->count());

        // Idempotent: a second store never creates a duplicate form.
        $this->post(route('customer.workspaces.businesses.website.forms.store', [$workspace->uid, $business->uid]));
        $this->assertSame(1, $website->forms()->count());

        $this->get(route('customer.workspaces.businesses.website.forms.index', [$workspace->uid, $business->uid]))
            ->assertOk()->assertSee('View inquiries');
    }

    public function test_a_foreign_businesss_form_and_submissions_never_leak(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->entitledTenant();
        $this->createWebsite($businessA);
        $this->authenticateAsCustomer($customerA);

        [, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $formB = $this->createQuoteForm($websiteB);

        $this->get(route('customer.workspaces.businesses.website.forms.index', [$workspaceB->uid, $businessB->uid]))
            ->assertNotFound();
        $this->post(route('customer.workspaces.businesses.website.forms.store', [$workspaceB->uid, $businessB->uid]))
            ->assertNotFound();
        $this->get(route('customer.workspaces.businesses.website.forms.submissions', [$workspaceB->uid, $businessB->uid, $formB->uid]))
            ->assertNotFound();
    }

    public function test_the_form_section_type_is_offered_and_a_quote_page_can_reference_it(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $form = $this->createQuoteForm($website);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.pages.create', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('<option value="form">Form</option>', false)
            ->assertSee($form->name, false);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Get a Quote',
            'slug' => 'quote',
            'is_home' => 0,
            'sections' => [$this->section('form', ['form_uid' => $form->uid, 'heading' => 'Request your quote'])],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('form', $website->pages()->where('slug', 'quote')->firstOrFail()->sections[0]['type']);
    }

    public function test_a_form_section_naming_an_unknown_form_uid_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => 1,
            'sections' => [$this->section('form', ['form_uid' => null])],
        ])->assertSessionHasErrors('sections.0.form_uid');
    }

    // ---------------------------------------------------------------
    // Public rendering: preview never submits; live page does
    // ---------------------------------------------------------------

    public function test_preview_renders_a_disabled_form_and_never_a_real_submit_action(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $home = $this->homePage($website, [
            'sections' => [$this->section('form', ['form_uid' => $form->uid, 'heading' => 'Request your quote'])],
        ]);
        $this->authenticateAsCustomer($customer);

        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid]);

        $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid, $home->uid]))
            ->assertOk()
            ->assertSee('Preview')
            ->assertSee('Request your quote')
            ->assertDontSee($submitUrl, false)
            ->assertSee('disabled', false);
    }

    public function test_public_page_renders_a_working_form_that_posts_to_the_submit_route(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $this->homePage($website, [
            'sections' => [$this->section('form', ['form_uid' => $form->uid, 'heading' => 'Request your quote'])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid]);

        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee('Request your quote')
            ->assertSee($submitUrl, false)
            ->assertSee('name="phone"', false);
    }

    // ---------------------------------------------------------------
    // Submission -> Contact -> CrmOpportunity, spam, duplicates, tenancy
    // ---------------------------------------------------------------

    public function test_a_real_submission_creates_a_findable_inquiry_and_a_contact(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $this->publishFormPage($website, $form);

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid]), [
            'name' => 'Jamie Rivera',
            'phone' => '5551234567',
            'email' => 'jamie@example.test',
            'event_date' => '2027-06-01',
            'event_type' => 'Wedding',
            'message' => 'Looking for a mirror booth for 150 guests.',
            'page_slug' => 'quote',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::where('website_form_id', $form->id)->sole();
        $this->assertFalse($submission->is_spam);
        $this->assertSame('quote', $submission->page_slug);
        $this->assertSame('Jamie Rivera', $submission->data['name']);
        $this->assertNotNull($submission->contact_id);

        $contact = Contacts::findOrFail($submission->contact_id);
        $this->assertSame((int) $business->id, (int) $contact->business_id);
        $this->assertSame(5551234567, $contact->phone);

        $this->authenticateAsCustomer($customer);
        $this->get(route('customer.workspaces.businesses.website.forms.submissions', [$workspace->uid, $business->uid, $form->uid]))
            ->assertOk()
            ->assertSee('Jamie Rivera')
            ->assertSee('jamie@example.test');
    }

    public function test_a_submission_creates_a_crm_opportunity_only_when_a_pipeline_already_exists(): void
    {
        [, $businessNoPipeline] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessNoPipeline);
        $formA = $this->createQuoteForm($websiteA);
        $this->publishFormPage($websiteA, $formA);

        $this->post(route('public.website.form.submit', [$websiteA->public_id, $formA->uid]), [
            'name' => 'No Pipeline Visitor', 'phone' => '5559990001',
        ]);

        $submissionA = WebsiteFormSubmission::where('website_form_id', $formA->id)->sole();
        $this->assertNull($submissionA->crm_opportunity_id);
        $this->assertNotNull($submissionA->contact_id);

        [, $businessWithPipeline] = $this->entitledTenant();
        app(CrmPipelineService::class)->setUpStandardPipeline($businessWithPipeline);
        $websiteB = $this->createWebsite($businessWithPipeline);
        $formB = $this->createQuoteForm($websiteB);
        $this->publishFormPage($websiteB, $formB);

        $this->post(route('public.website.form.submit', [$websiteB->public_id, $formB->uid]), [
            'name' => 'Pipeline Visitor', 'phone' => '5559990002',
        ]);

        $submissionB = WebsiteFormSubmission::where('website_form_id', $formB->id)->sole();
        $this->assertNotNull($submissionB->crm_opportunity_id);

        $opportunity = CrmOpportunity::findOrFail($submissionB->crm_opportunity_id);
        $this->assertSame('website_form', $opportunity->source);
        $this->assertSame((int) $businessWithPipeline->id, (int) $opportunity->business_id);
        $this->assertSame((int) $submissionB->contact_id, (int) $opportunity->contact_id);
    }

    public function test_a_honeypot_filled_submission_is_recorded_as_spam_and_creates_no_contact(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $this->publishFormPage($website, $form);

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid]), [
            'name' => 'Bot', 'phone' => '5550001111',
            WebsiteFormSubmissionService::HONEYPOT_FIELD => 'I am a bot',
        ])->assertRedirect();

        $submission = WebsiteFormSubmission::where('website_form_id', $form->id)->sole();
        $this->assertTrue($submission->is_spam);
        $this->assertNull($submission->contact_id);
        $this->assertNull($submission->crm_opportunity_id);
        $this->assertSame(0, Contacts::where('business_id', $business->id)->count());
    }

    public function test_a_resubmission_with_the_same_identity_within_the_window_is_not_duplicated(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $this->publishFormPage($website, $form);

        $payload = ['name' => 'Double Click', 'phone' => '5552223333', 'email' => 'double@example.test'];

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid]), $payload)->assertRedirect();
        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid]), $payload)->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
        $this->assertSame(1, Contacts::where('business_id', $business->id)->count());
    }

    public function test_submitting_to_a_foreign_websites_form_404s(): void
    {
        [, $businessA] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessA);
        $this->publishFormPage($websiteA, $this->createQuoteForm($websiteA));

        [, $businessB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $formB = $this->createQuoteForm($websiteB);
        $this->publishFormPage($websiteB, $formB);

        // formB's uid does not belong to websiteA.
        $this->post(route('public.website.form.submit', [$websiteA->public_id, $formB->uid]), [
            'name' => 'Cross Tenant', 'phone' => '5554445555',
        ])->assertNotFound();

        $this->assertSame(0, WebsiteFormSubmission::where('website_form_id', $formB->id)->count());
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $this->publishFormPage($website, $form);

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid]), [
            'email' => 'no-name-or-phone@example.test',
        ])->assertSessionHasErrors(['name', 'phone']);

        $this->assertSame(0, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
    }

    public function test_an_unpublished_or_disabled_businesss_form_404s_publicly(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        // Never published.

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid]), [
            'name' => 'Too Early', 'phone' => '5556667777',
        ])->assertNotFound();
    }
}
