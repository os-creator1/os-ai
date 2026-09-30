<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessIndustry;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Jobs\AutomationJob;
use App\Library\Crm\CrmPipelineService;
use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsiteFormSubmissionService;
use App\Library\Website\WebsitePublisher;
use App\Models\Blacklists;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The Forms slice: the smallest reusable foundation (WebsiteForm,
 * WebsiteFormSubmission, one closed `form` section type), shipping
 * exactly one preset (Photo Booth quote request, offered only to Photo
 * Booth businesses). A real submission always produces a durable
 * WebsiteFormSubmission; it additionally produces a Contact (matched or
 * created by phone) and, only when the Business already has a CRM
 * pipeline, a CrmOpportunity tagged `source = website_form`.
 *
 * An anonymous inquiry is never messaging consent: the Contact it creates
 * is never subscribed and never fires a contact-created automation.
 *
 * A form's fields and its "is this form live" status are both read from
 * the immutable published snapshot, never live from website_forms — a
 * submission is accepted only for a form the current published revision
 * actually renders on one of its pages, and the recorded source page is
 * derived from that same snapshot, never from anything the visitor posted.
 */
class WebsiteFormTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private function createQuoteForm(Website $website): WebsiteForm
    {
        return $website->forms()->create([
            'business_id' => $website->business_id,
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Request a quote',
        ]);
    }

    /**
     * @return WebsitePage the published "quote" page carrying the form
     */
    private function publishFormPage(Website $website, WebsiteForm $form): WebsitePage
    {
        $this->homePage($website);
        $page = $this->subPage($website, 'quote', [
            'sections' => [$this->section('form', ['form_uid' => $form->uid])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        return $page;
    }

    private function submitRoute(Website $website, WebsiteForm $form, WebsitePage $page): string
    {
        return route('public.website.form.submit', [$website->public_id, $form->uid, $page->uid]);
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

    public function test_the_photo_booth_preset_is_never_offered_to_another_niche(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $business->update(['industry' => BusinessIndustry::Other]);
        $website = $this->createWebsite($business->fresh());
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.forms.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertDontSee('Create quote request form')
            ->assertDontSee('Photo Booth quote request form');

        // Defence in depth: a direct POST is refused too, not just hidden
        // from the view.
        $this->post(route('customer.workspaces.businesses.website.forms.store', [$workspace->uid, $business->uid]))
            ->assertSessionHasErrors('form');

        $this->assertSame(0, $website->forms()->count());
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

    public function test_a_form_section_naming_another_websites_real_form_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        [, $foreignBusiness] = $this->entitledTenant();
        $foreignWebsite = $this->createWebsite($foreignBusiness);
        $foreignForm = $this->createQuoteForm($foreignWebsite);

        $this->post(route('customer.workspaces.businesses.website.pages.store', [$workspace->uid, $business->uid]), [
            'title' => 'Home',
            'is_home' => 1,
            'sections' => [$this->section('form', ['form_uid' => $foreignForm->uid])],
        ])->assertSessionHasErrors('sections.0.form_uid');
    }

    // ---------------------------------------------------------------
    // Public rendering: preview never submits; live page does; fields
    // stay stable to what was actually published
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

        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid, $home->uid]);

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
        $home = $this->homePage($website, [
            'sections' => [$this->section('form', ['form_uid' => $form->uid, 'heading' => 'Request your quote'])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $submitUrl = route('public.website.form.submit', [$website->public_id, $form->uid, $home->uid]);

        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee('Request your quote')
            ->assertSee($submitUrl, false)
            ->assertSee('name="phone"', false);
    }

    public function test_a_form_absent_from_the_published_revision_404s(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        // Published, but no page references this form at all.
        $home = $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid, $home->uid]), [
            'name' => 'Never Published', 'phone' => '5551110000',
        ])->assertNotFound();

        $this->assertSame(0, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
    }

    public function test_a_mismatched_page_and_form_pairing_is_refused(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        // $home is a real, currently-published page, but its own sections
        // never reference $form — the form lives on $quotePage instead.
        $home = $this->homePage($website);
        $this->subPage($website, 'quote', [
            'sections' => [$this->section('form', ['form_uid' => $form->uid])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        // Each half is individually valid — $form is genuinely live and
        // $home is genuinely published — but this exact pairing never
        // matched, so it must be refused rather than silently accepted.
        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid, $home->uid]), [
            'name' => 'Mismatched', 'phone' => '5551110002',
        ])->assertNotFound();

        $this->assertSame(0, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
    }

    public function test_the_same_form_on_two_published_pages_each_submission_records_its_actual_page(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $this->homePage($website);
        $quotePage = $this->subPage($website, 'quote', [
            'sections' => [$this->section('form', ['form_uid' => $form->uid])],
        ]);
        $bookingPage = $this->subPage($website, 'booking', [
            'sections' => [$this->section('form', ['form_uid' => $form->uid])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid, $quotePage->uid]), [
            'name' => 'From Quote Page', 'phone' => '5551000001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid, $bookingPage->uid]), [
            'name' => 'From Booking Page', 'phone' => '5551000002',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $submissionsByName = WebsiteFormSubmission::where('website_form_id', $form->id)
            ->get()
            ->keyBy(fn ($submission) => $submission->data['name']);

        // Each submission's recorded page is the one the visitor actually
        // posted from, not whichever page a snapshot scan happens to find
        // first for this form_uid.
        $this->assertSame('quote', $submissionsByName['From Quote Page']->page_slug);
        $this->assertSame('booking', $submissionsByName['From Booking Page']->page_slug);
    }

    // ---------------------------------------------------------------
    // Submission -> Contact -> CrmOpportunity, spam, duplicates, tenancy
    // ---------------------------------------------------------------

    public function test_a_real_submission_creates_a_findable_inquiry_and_a_contact(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->publishFormPage($website, $form);

        $this->post($this->submitRoute($website, $form, $page), [
            'name' => 'Jamie Rivera',
            'phone' => '5551234567',
            'email' => 'jamie@example.test',
            'event_date' => '2027-06-01',
            'event_type' => 'Wedding',
            'message' => 'Looking for a mirror booth for 150 guests.',
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

    /**
     * The submit controller already flashes `status`/`message` on
     * success (WebsiteFormController::submit()) — this proves the page
     * the visitor lands back on actually DISPLAYS it, not merely that
     * the flash data exists in the session.
     */
    public function test_a_successful_submission_shows_a_visible_confirmation_on_the_page_the_visitor_returns_to(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->publishFormPage($website, $form);
        $pageUrl = route('public.website.page', [$website->public_id, $page->slug]);

        $this->withHeaders(['referer' => $pageUrl])
            ->post($this->submitRoute($website, $form, $page), [
                'name' => 'Jamie Rivera',
                'phone' => '5551234567',
            ])
            ->assertRedirect($pageUrl);

        $this->get($pageUrl)->assertSee('Thanks — we received your request and will be in touch soon.');
    }

    public function test_a_posted_page_slug_is_ignored_the_server_derives_it_from_the_published_page(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->publishFormPage($website, $form);

        $this->post($this->submitRoute($website, $form, $page), [
            'name' => 'Spoofer', 'phone' => '5551119999',
            'page_slug' => 'totally-fake-page-i-was-never-on',
        ])->assertRedirect();

        $submission = WebsiteFormSubmission::where('website_form_id', $form->id)->sole();
        $this->assertSame('quote', $submission->page_slug);
    }

    public function test_no_messaging_jobs_are_dispatched_and_the_contact_is_not_subscribed(): void
    {
        Bus::fake();

        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->publishFormPage($website, $form);

        $this->post($this->submitRoute($website, $form, $page), [
            'name' => 'Quiet Visitor', 'phone' => '5552221000', 'email' => 'quiet@example.test',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::where('website_form_id', $form->id)->sole();
        $contact = Contacts::findOrFail($submission->contact_id);

        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->status);
        Bus::assertNotDispatched(AutomationJob::class);
        Bus::assertNotDispatched(EnrollWorkflowContact::class);
    }

    public function test_a_submission_creates_a_crm_opportunity_only_when_a_pipeline_already_exists(): void
    {
        [, $businessNoPipeline] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessNoPipeline);
        $formA = $this->createQuoteForm($websiteA);
        $pageA = $this->publishFormPage($websiteA, $formA);

        $this->post($this->submitRoute($websiteA, $formA, $pageA), [
            'name' => 'No Pipeline Visitor', 'phone' => '5559990001',
        ]);

        $submissionA = WebsiteFormSubmission::where('website_form_id', $formA->id)->sole();
        $this->assertNull($submissionA->crm_opportunity_id);
        $this->assertNotNull($submissionA->contact_id);

        [, $businessWithPipeline] = $this->entitledTenant();
        app(CrmPipelineService::class)->setUpStandardPipeline($businessWithPipeline);
        $websiteB = $this->createWebsite($businessWithPipeline);
        $formB = $this->createQuoteForm($websiteB);
        $pageB = $this->publishFormPage($websiteB, $formB);

        $this->post($this->submitRoute($websiteB, $formB, $pageB), [
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
        $page = $this->publishFormPage($website, $form);

        $this->post($this->submitRoute($website, $form, $page), [
            'name' => 'Bot', 'phone' => '5550001111',
            WebsiteFormSubmissionService::HONEYPOT_FIELD => 'I am a bot',
        ])->assertRedirect();

        $submission = WebsiteFormSubmission::where('website_form_id', $form->id)->sole();
        $this->assertTrue($submission->is_spam);
        $this->assertNull($submission->contact_id);
        $this->assertNull($submission->crm_opportunity_id);
        $this->assertSame(0, Contacts::where('business_id', $business->id)->count());
    }

    public function test_recheck_blacklisting_even_when_a_matching_contact_already_exists(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->publishFormPage($website, $form);

        $this->post($this->submitRoute($website, $form, $page), [
            'name' => 'Later Blacklisted', 'phone' => '5553334444',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $existingContact = Contacts::where('business_id', $business->id)->where('phone', 5553334444)->sole();

        // Blacklisted AFTER the first, legitimate inquiry.
        Blacklists::create(['number' => '5553334444']);

        $this->post($this->submitRoute($website, $form, $page), [
            'name' => 'Later Blacklisted', 'phone' => '5553334444', 'message' => 'A second, different message.',
        ])->assertSessionHasErrors('phone');

        // No second submission was recorded, and the existing Contact is untouched.
        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
        $this->assertSame($existingContact->id, Contacts::where('business_id', $business->id)->sole()->id);
    }

    public function test_an_exact_resubmission_within_the_window_is_not_duplicated(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->publishFormPage($website, $form);

        $payload = ['name' => 'Double Click', 'phone' => '5552223333', 'email' => 'double@example.test'];

        $this->post($this->submitRoute($website, $form, $page), $payload)->assertRedirect();
        $this->post($this->submitRoute($website, $form, $page), $payload)->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
        $this->assertSame(1, Contacts::where('business_id', $business->id)->count());
    }

    public function test_a_second_request_for_a_different_event_from_the_same_person_is_retained(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->publishFormPage($website, $form);

        $this->post($this->submitRoute($website, $form, $page), [
            'name' => 'Repeat Customer', 'phone' => '5557778888', 'email' => 'repeat@example.test',
            'event_date' => '2027-03-01', 'event_type' => 'Birthday', 'message' => 'First inquiry.',
        ])->assertRedirect();

        $this->post($this->submitRoute($website, $form, $page), [
            'name' => 'Repeat Customer', 'phone' => '5557778888', 'email' => 'repeat@example.test',
            'event_date' => '2027-09-01', 'event_type' => 'Corporate', 'message' => 'A completely different event.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
        // Same phone -> the same Contact is reused, not duplicated.
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
        $pageB = $this->publishFormPage($websiteB, $formB);

        // formB's uid (and pageB's uid) do not belong to websiteA.
        $this->post(route('public.website.form.submit', [$websiteA->public_id, $formB->uid, $pageB->uid]), [
            'name' => 'Cross Tenant', 'phone' => '5554445555',
        ])->assertNotFound();

        $this->assertSame(0, WebsiteFormSubmission::where('website_form_id', $formB->id)->count());
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->publishFormPage($website, $form);

        $this->post($this->submitRoute($website, $form, $page), [
            'email' => 'no-name-or-phone@example.test',
        ])->assertSessionHasErrors(['name', 'phone']);

        $this->assertSame(0, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
    }

    public function test_an_unpublished_or_disabled_businesss_form_404s_publicly(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $this->createQuoteForm($website);
        $page = $this->homePage($website);
        // Never published.

        $this->post(route('public.website.form.submit', [$website->public_id, $form->uid, $page->uid]), [
            'name' => 'Too Early', 'phone' => '5556667777',
        ])->assertNotFound();
    }
}
