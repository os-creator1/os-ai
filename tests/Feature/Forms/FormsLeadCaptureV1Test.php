<?php

namespace Tests\Feature\Forms;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Forms\FormSubmissionRecorded;
use App\Library\Crm\CrmPipelineService;
use App\Library\Timeline\Sources\FormSubmissionActivitySource;
use App\Library\Timeline\TimelineSubject;
use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsiteFormSubmissionService;
use App\Library\Website\WebsitePublisher;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use App\Models\WebsitePage;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Forms / Questionnaires V1 completion (Blueprint §16): a public form
 * submission becomes the correct Business/Location CRM state, exactly once.
 *
 *   form -> Location the form carries -> Contact (Location-local identity)
 *        -> Opportunity (where configured) -> submission row -> event
 *
 * The true multi-process idempotency proof lives in
 * FormsSubmissionConcurrencyTest; this file covers everything that can run
 * inside RefreshDatabase.
 */
class FormsLeadCaptureV1Test extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private int $locationSequence = 0;

    private function location(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'name' => 'Location '.(++$this->locationSequence),
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    private function quoteForm(Website $website, ?BusinessLocation $location, array $overrides = []): WebsiteForm
    {
        return $website->forms()->create(array_merge([
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Request a quote',
            'location_id' => $location?->id,
        ], $overrides));
    }

    /**
     * @return array{0: Website, 1: WebsitePage}
     */
    private function publish(Website $website, WebsiteForm $form): array
    {
        if (! $website->pages()->where('is_home', true)->exists()) {
            $this->homePage($website);
        }
        $slug = 'quote'.($website->pages()->count());
        $page = $this->subPage($website, $slug, [
            'sections' => [$this->section('form', ['form_uid' => $form->uid])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        return [$website->fresh(), $page];
    }

    private function route(Website $website, WebsiteForm $form, WebsitePage $page): string
    {
        return route('public.website.form.submit', [$website->public_id, $form->uid, $page->uid]);
    }

    /**
     * One published, active, Location-bound form on a fresh tenant.
     *
     * @return array{0: \App\Models\Customer, 1: Business, 2: \App\Models\Workspace, 3: Website, 4: WebsiteForm, 5: WebsitePage, 6: BusinessLocation}
     */
    private function liveForm(?callable $configure = null): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->location($business);
        $website = $this->createWebsite($business);
        $form = $this->quoteForm($website, $location);
        if ($configure !== null) {
            $configure($business, $form, $location);
        }
        [$website, $page] = $this->publish($website, $form);

        return [$customer, $business, $workspace, $website, $form, $page, $location];
    }

    private function contactAt(Business $business, ?BusinessLocation $location, string $phone, string $name = 'Existing'): Contacts
    {
        $group = ContactGroups::query()->where('business_id', $business->id)->first()
            ?? ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Clients', 'status' => true]);

        $contact = Contacts::create([
            'customer_id' => $group->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'location_id' => $location?->id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
        $contact->updateFields(['FIRST_NAME' => $name, 'PHONE' => $phone]);

        return $contact;
    }

    private function fieldValue(Contacts $contact, string $tag): ?string
    {
        return \App\Models\ContactsCustomField::query()
            ->where('contact_id', $contact->id)
            ->whereIn('field_id', \App\Models\ContactGroupFields::query()->where('tag', $tag)->select('id'))
            ->value('value');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Jamie Rivera', 'phone' => '5551234567'], $overrides);
    }

    // ---------------------------------------------------------------
    // 1. Definitions: Business-wide, authorized, activate/deactivate
    // ---------------------------------------------------------------

    public function test_definition_screens_require_the_website_permission_and_the_tenancy_chain(): void
    {
        // The first user ever created is the app's built-in super admin (id 1); burn it so the customer below is an ordinary one.
        $this->platformAdminId();
        [$customer, $business, $workspace, , $form] = $this->liveForm();
        $edit = route('customer.workspaces.businesses.website.forms.edit', [$workspace->uid, $business->uid, $form->uid]);
        $update = route('customer.workspaces.businesses.website.forms.update', [$workspace->uid, $business->uid, $form->uid]);

        // No `website` permission: a customer permission failure answers 401.
        $this->authenticateAsCustomer($customer, []);
        $this->get(route('customer.workspaces.businesses.website.forms.index', [$workspace->uid, $business->uid]))->assertStatus(401);
        $this->get($edit)->assertStatus(401);
        $this->put($update, ['name' => 'Hacked'])->assertStatus(401);

        // Another Business's owner cannot reach this Business's definition.
        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($otherCustomer);
        $this->get(route('customer.workspaces.businesses.website.forms.edit', [$otherWorkspace->uid, $otherBusiness->uid, $form->uid]))->assertNotFound();
        $this->put(route('customer.workspaces.businesses.website.forms.update', [$otherWorkspace->uid, $otherBusiness->uid, $form->uid]), ['name' => 'Hacked'])->assertNotFound();
        $this->get(route('customer.workspaces.businesses.website.forms.edit', [$workspace->uid, $business->uid, $form->uid]))->assertNotFound();

        $this->assertSame('Photo Booth Quote Request', $form->fresh()->name);
    }

    public function test_the_owner_can_edit_activate_and_deactivate_a_definition_and_it_gates_public_submissions(): void
    {
        [$customer, $business, $workspace, $website, $form, $page, $location] = $this->liveForm();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.website.forms.edit', [$workspace->uid, $business->uid, $form->uid]))
            ->assertOk()->assertSee('Accepting submissions');

        $update = route('customer.workspaces.businesses.website.forms.update', [$workspace->uid, $business->uid, $form->uid]);
        $base = ['name' => 'Renamed Quote Form', 'submit_label' => 'Ask now', 'location_uid' => $location->uid, 'create_opportunity' => '1'];

        // Unchecked "Accepting submissions" = deactivated.
        $this->put($update, $base)->assertRedirect()->assertSessionHasNoErrors();
        $form->refresh();
        $this->assertSame('Renamed Quote Form', $form->name);
        $this->assertFalse($form->is_active);

        auth()->logout();
        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasErrors('form');
        $this->assertSame(0, WebsiteFormSubmission::count());
        $this->assertSame(0, Contacts::where('business_id', $business->id)->count());

        // Reactivated: submissions flow again.
        $this->authenticateAsCustomer($customer);
        $this->put($update, $base + ['is_active' => '1'])->assertRedirect();
        $this->assertTrue($form->fresh()->is_active);

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(1, WebsiteFormSubmission::count());
    }

    public function test_a_definition_can_only_be_bound_to_an_active_location_of_its_own_business(): void
    {
        [$customer, $business, $workspace, , $form, , $location] = $this->liveForm();
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->location($otherBusiness);
        $archived = $this->location($business);
        $archived->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value, 'archived_at' => now()])->save();

        $this->authenticateAsCustomer($customer);
        $update = route('customer.workspaces.businesses.website.forms.update', [$workspace->uid, $business->uid, $form->uid]);
        $base = ['name' => 'Quote', 'submit_label' => 'Send', 'is_active' => '1'];

        foreach ([$foreign->uid, $archived->uid, 'not-a-location'] as $bad) {
            $this->put($update, $base + ['location_uid' => $bad])->assertSessionHasErrors('location_uid');
            $this->assertSame((int) $location->id, (int) $form->fresh()->location_id);
        }
    }

    public function test_definitions_are_business_wide_and_two_locations_each_carry_their_own_form(): void
    {
        [$customer, $business, $workspace, $website, $formA, , $locationA] = $this->liveForm();
        $locationB = $this->location($business);

        $this->authenticateAsCustomer($customer);
        $store = route('customer.workspaces.businesses.website.forms.store', [$workspace->uid, $business->uid]);

        // Two active Locations: one must be NAMED, never defaulted to "the first".
        $this->post($store, [])->assertSessionHasErrors('location_uid');
        $this->post($store, ['location_uid' => $locationB->uid])->assertRedirect()->assertSessionHasNoErrors();
        // The same Location cannot get a second form of the same type.
        $this->post($store, ['location_uid' => $locationB->uid])->assertRedirect();

        $this->assertSame(2, $website->forms()->count());
        $formB = $website->forms()->where('location_id', $locationB->id)->sole();

        $this->get(route('customer.workspaces.businesses.website.forms.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee($locationA->name)
            ->assertSee($locationB->name);

        // Two forms of one type on ONE Location is a database-level refusal too.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->quoteForm($website, $locationA, ['uid' => (string) Str::uuid()]);
        $this->assertNotSame($formA->id, $formB->id);
    }

    // ---------------------------------------------------------------
    // 2. Location resolution
    // ---------------------------------------------------------------

    public function test_a_submission_is_bound_to_the_location_its_form_carries_not_the_first_one(): void
    {
        [, $business] = $this->entitledTenant();
        $first = $this->location($business, ['name' => 'First']);
        $second = $this->location($business, ['name' => 'Second']);
        $website = $this->createWebsite($business);
        $form = $this->quoteForm($website, $second);
        $pipeline = app(CrmPipelineService::class)->setUpStandardPipeline($business);
        [$website, $page] = $this->publish($website, $form);

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $this->assertSame((int) $second->id, (int) $submission->location_id);
        $this->assertNotSame((int) $first->id, (int) $submission->location_id);
        $this->assertSame((int) $second->id, (int) Contacts::findOrFail($submission->contact_id)->location_id);

        $opportunity = CrmOpportunity::findOrFail($submission->crm_opportunity_id);
        $this->assertSame((int) $second->id, (int) $opportunity->location_id);
        $this->assertSame((int) $pipeline->id, (int) $opportunity->pipeline_id);
        $this->assertNotNull($opportunity->stage_id);
        $this->assertSame('website_form', $opportunity->source);
    }

    public function test_a_posted_location_may_only_restate_the_forms_own_location_and_a_foreign_one_fails_closed(): void
    {
        [, $business, , $website, $form, $page, $location] = $this->liveForm();
        $sibling = $this->location($business);
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->location($otherBusiness);
        $url = $this->route($website, $form, $page);

        foreach ([$sibling->uid, $foreign->uid, '999999', 'garbage'] as $forged) {
            $this->post($url, $this->payload(['location_uid' => $forged]))->assertSessionHasErrors('location_uid');
        }
        $this->assertSame(0, WebsiteFormSubmission::count());
        $this->assertSame(0, Contacts::count());
        $this->assertSame(0, CrmOpportunity::count());

        $this->post($url, $this->payload(['location_uid' => $location->uid]))->assertSessionHasNoErrors();
        $this->assertSame(1, WebsiteFormSubmission::count());
    }

    public function test_a_form_with_no_location_an_archived_location_or_a_foreign_location_accepts_nothing(): void
    {
        [, $business, , $website, $form, $page, $location] = $this->liveForm();
        $url = $this->route($website, $form, $page);

        // No Location configured.
        $form->forceFill(['location_id' => null])->save();
        $this->post($url, $this->payload())->assertSessionHasErrors('form');

        // Archived Location.
        $form->forceFill(['location_id' => $location->id])->save();
        $location->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value, 'archived_at' => now()])->save();
        $this->post($url, $this->payload())->assertSessionHasErrors('form');

        // A Location of ANOTHER Business wired onto the form row.
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->location($otherBusiness);
        $form->forceFill(['location_id' => $foreign->id])->save();
        $this->post($url, $this->payload())->assertSessionHasErrors('form');

        $this->assertSame(0, WebsiteFormSubmission::count());
        $this->assertSame(0, Contacts::where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // 3. Contacts
    // ---------------------------------------------------------------

    public function test_a_new_lead_creates_a_location_bound_unsubscribed_contact(): void
    {
        [, $business, , $website, $form, $page, $location] = $this->liveForm();

        $this->post($this->route($website, $form, $page), $this->payload(['email' => 'jamie@example.test']))->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $contact = Contacts::findOrFail($submission->contact_id);
        $this->assertSame('created', $submission->contact_resolution);
        $this->assertSame((int) $business->id, (int) $contact->business_id);
        $this->assertSame((int) $location->id, (int) $contact->location_id);
        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->status);
    }

    public function test_an_existing_same_business_same_location_contact_is_matched_and_left_untouched(): void
    {
        [, $business, , $website, $form, $page, $location] = $this->liveForm();
        $existing = $this->contactAt($business, $location, '5551234567', 'OriginalFirst');

        $this->post($this->route($website, $form, $page), $this->payload(['name' => 'Attacker Rename']))->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $this->assertSame('matched', $submission->contact_resolution);
        $this->assertSame((int) $existing->id, (int) $submission->contact_id);
        $this->assertSame(1, Contacts::where('business_id', $business->id)->count());
        $this->assertSame('OriginalFirst', $this->fieldValue($existing, 'FIRST_NAME'));
    }

    public function test_the_same_phone_at_another_location_of_the_same_business_is_a_separate_contact(): void
    {
        [, $business, , $website, $form, $page, $location] = $this->liveForm();
        $otherLocation = $this->location($business);
        $atOther = $this->contactAt($business, $otherLocation, '5551234567', 'AtOther');

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $this->assertSame('created', $submission->contact_resolution);
        $this->assertNotSame((int) $atOther->id, (int) $submission->contact_id);
        $this->assertSame((int) $location->id, (int) Contacts::findOrFail($submission->contact_id)->location_id);
        $this->assertSame((int) $otherLocation->id, (int) $atOther->fresh()->location_id);
        $this->assertSame(2, Contacts::where('business_id', $business->id)->count());
    }

    public function test_a_contact_of_another_business_is_never_reused(): void
    {
        [, $business, , $website, $form, $page] = $this->liveForm();
        [, $otherBusiness] = $this->entitledTenant();
        $otherLocation = $this->location($otherBusiness);
        $foreignContact = $this->contactAt($otherBusiness, $otherLocation, '5551234567', 'Foreign');

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $this->assertNotSame((int) $foreignContact->id, (int) $submission->contact_id);
        $this->assertSame((int) $business->id, (int) Contacts::findOrFail($submission->contact_id)->business_id);
        $this->assertSame('Foreign', $this->fieldValue($foreignContact, 'FIRST_NAME'));
        $this->assertSame(1, Contacts::where('business_id', $otherBusiness->id)->count());
    }

    public function test_ambiguous_identity_picks_no_row_creates_nothing_and_keeps_the_submission(): void
    {
        [, $business, , $website, $form, $page, $location] = $this->liveForm(function (Business $business) {
            app(CrmPipelineService::class)->setUpStandardPipeline($business);
        });
        $this->contactAt($business, $location, '5551234567', 'DupA');
        $this->contactAt($business, $location, '5551234567', 'DupB');
        $events = [];
        Event::listen(FormSubmissionRecorded::class, function (FormSubmissionRecorded $e) use (&$events): void {
            $events[] = $e;
        });

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $this->assertSame('ambiguous', $submission->contact_resolution);
        $this->assertNull($submission->contact_id);
        $this->assertNull($submission->crm_opportunity_id);
        $this->assertSame(0, CrmOpportunity::count());
        $this->assertSame(2, Contacts::where('business_id', $business->id)->count());

        $this->assertCount(1, $events);
        $this->assertNull($events[0]->contactId);
        $this->assertSame('ambiguous', $events[0]->contactResolution);
    }

    public function test_a_blacklisted_phone_rolls_the_whole_submission_back(): void
    {
        [, $business, , $website, $form, $page] = $this->liveForm();
        $events = 0;
        Event::listen(FormSubmissionRecorded::class, function () use (&$events): void {
            $events++;
        });
        Blacklists::create(['user_id' => $business->customer_id, 'number' => '5551234567', 'reason' => 'test']);

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasErrors('phone');

        $this->assertSame(0, WebsiteFormSubmission::count());
        $this->assertSame(0, Contacts::count());
        $this->assertSame(0, $events);
    }

    // ---------------------------------------------------------------
    // 4. Opportunities
    // ---------------------------------------------------------------

    public function test_a_submission_opens_one_location_bound_opportunity_in_the_configured_pipeline(): void
    {
        [, $business, , $website, $form, $page, $location] = $this->liveForm();
        $first = app(CrmPipelineService::class)->setUpStandardPipeline($business);
        $second = \App\Models\CrmPipeline::create([
            'business_id' => $business->id, 'name' => 'Second', 'position' => 5,
        ]);
        $this->assertNotNull($first);
        $stage = \App\Models\CrmPipelineStage::create([
            'business_id' => $business->id, 'pipeline_id' => $second->id, 'name' => 'Start', 'position' => 1,
            'semantic_key' => \App\Enums\Crm\CrmStageSemanticKey::NewInquiry->value,
        ]);
        $form->forceFill(['crm_pipeline_id' => $second->id])->save();

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();

        $opportunity = CrmOpportunity::sole();
        $this->assertSame((int) $second->id, (int) $opportunity->pipeline_id);
        $this->assertSame((int) $stage->id, (int) $opportunity->stage_id);
        $this->assertSame((int) $location->id, (int) $opportunity->location_id);
        $this->assertSame((int) WebsiteFormSubmission::sole()->contact_id, (int) $opportunity->contact_id);
        $this->assertSame((int) $business->id, (int) $opportunity->business_id);
    }

    public function test_a_definition_that_does_not_create_opportunities_creates_none(): void
    {
        [, $business, , $website, $form, $page] = $this->liveForm();
        app(CrmPipelineService::class)->setUpStandardPipeline($business);
        $form->forceFill(['create_opportunity' => false])->save();

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();

        $this->assertNotNull(WebsiteFormSubmission::sole()->contact_id);
        $this->assertSame(0, CrmOpportunity::count());
    }

    public function test_a_retry_of_the_same_submission_never_duplicates_the_opportunity(): void
    {
        [, $business, , $website, $form, $page] = $this->liveForm();
        app(CrmPipelineService::class)->setUpStandardPipeline($business);
        $token = (string) Str::uuid();

        $this->post($this->route($website, $form, $page), $this->payload([WebsiteFormSubmissionService::TOKEN_FIELD => $token]))->assertSessionHasNoErrors();
        $this->post($this->route($website, $form, $page), $this->payload([WebsiteFormSubmissionService::TOKEN_FIELD => $token]))->assertSessionHasNoErrors();

        $this->assertSame(1, WebsiteFormSubmission::count());
        $this->assertSame(1, Contacts::where('business_id', $business->id)->count());
        $this->assertSame(1, CrmOpportunity::count());
    }

    // ---------------------------------------------------------------
    // 5. Idempotency
    // ---------------------------------------------------------------

    public function test_the_same_token_is_one_submission_and_one_event_but_identical_bodies_without_one_are_two(): void
    {
        [, , , $website, $form, $page] = $this->liveForm();
        $url = $this->route($website, $form, $page);
        $events = 0;
        Event::listen(FormSubmissionRecorded::class, function () use (&$events): void {
            $events++;
        });
        $token = (string) Str::uuid();

        $this->post($url, $this->payload([WebsiteFormSubmissionService::TOKEN_FIELD => $token]))->assertSessionHasNoErrors();
        $this->post($url, $this->payload([WebsiteFormSubmissionService::TOKEN_FIELD => $token, 'message' => 'a retry may differ in nothing that matters']))->assertSessionHasNoErrors();
        $this->assertSame(1, WebsiteFormSubmission::count());
        $this->assertSame(1, $events);

        // A fresh page render carries a fresh token: a genuinely new inquiry,
        // even with a byte-identical body.
        $this->post($url, $this->payload([WebsiteFormSubmissionService::TOKEN_FIELD => (string) Str::uuid()]));
        // And a post with no (or a malformed) token is its own submission.
        $this->post($url, $this->payload());
        $this->post($url, $this->payload([WebsiteFormSubmissionService::TOKEN_FIELD => 'not-a-uuid']));

        $this->assertSame(4, WebsiteFormSubmission::count());
        $this->assertSame(4, $events);
        $this->assertSame(1, Contacts::count(), 'Same person, same Location: one Contact however many inquiries.');
    }

    public function test_the_database_itself_refuses_a_second_row_for_one_token(): void
    {
        [, , , , $form, , $location] = $this->liveForm();
        $row = ['website_form_id' => $form->id, 'location_id' => $location->id, 'data' => ['name' => 'x'], 'idempotency_key' => (string) Str::uuid(), 'status' => 'new'];

        WebsiteFormSubmission::create($row);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        WebsiteFormSubmission::create($row);
    }

    public function test_the_rendered_form_carries_a_distinct_token_per_render(): void
    {
        [, , , $website, $form, $page] = $this->liveForm();
        $tokens = [];
        for ($i = 0; $i < 2; $i++) {
            $html = $this->get(route('public.website.page', [$website->public_id, $page->slug]))->assertOk()->getContent();
            $this->assertSame(1, preg_match('/name="submission_token" value="([0-9a-f-]{36})"/', $html, $m), 'the rendered form must carry a token');
            $tokens[] = $m[1];
        }
        $this->assertNotSame($tokens[0], $tokens[1]);
        $this->assertNotNull($page);
    }

    // ---------------------------------------------------------------
    // 6. Record, event, rollback, timeline
    // ---------------------------------------------------------------

    public function test_the_submission_record_carries_location_source_and_resolution(): void
    {
        [, , , $website, $form, $page, $location] = $this->liveForm();

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $this->assertSame((int) $location->id, (int) $submission->location_id);
        $this->assertSame($page->uid, $submission->page_uid);
        $this->assertSame('quote1', $submission->page_slug);
        $this->assertSame((int) $website->published_revision_id, (int) $submission->source_revision_id);
        $this->assertSame('created', $submission->contact_resolution);
        $this->assertNull($submission->dedupe_key);
        $this->assertSame('Jamie Rivera', $submission->data['name']);
    }

    public function test_the_event_carries_stable_tenant_location_contact_form_and_opportunity_identity(): void
    {
        [, $business, , $website, $form, $page, $location] = $this->liveForm();
        app(CrmPipelineService::class)->setUpStandardPipeline($business);
        $events = [];
        Event::listen(FormSubmissionRecorded::class, function (FormSubmissionRecorded $e) use (&$events): void {
            $events[] = $e;
        });

        $this->post($this->route($website, $form, $page), $this->payload())->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertSame('form_submitted', $e->name());
        $this->assertSame((int) $business->id, $e->businessId);
        $this->assertSame((int) $location->id, $e->locationId);
        $this->assertSame((int) $form->id, $e->formId);
        $this->assertSame($form->uid, $e->formUid);
        $this->assertSame((int) $submission->id, $e->submissionId);
        $this->assertSame($submission->uid, $e->submissionUid);
        $this->assertSame((int) $submission->contact_id, $e->contactId);
        $this->assertSame((int) $submission->crm_opportunity_id, $e->opportunityId);
        $this->assertSame('created', $e->contactResolution);
        $this->assertSame('form_submitted:'.$submission->id, $e->occurrenceKey);
    }

    public function test_spam_is_recorded_but_raises_no_event_and_creates_no_contact(): void
    {
        [, $business, , $website, $form, $page] = $this->liveForm();
        $events = 0;
        Event::listen(FormSubmissionRecorded::class, function () use (&$events): void {
            $events++;
        });

        $this->post($this->route($website, $form, $page), $this->payload([WebsiteFormSubmissionService::HONEYPOT_FIELD => 'bot']))->assertSessionHasNoErrors();

        $this->assertTrue(WebsiteFormSubmission::sole()->is_spam);
        $this->assertSame(0, $events);
        $this->assertSame(0, Contacts::where('business_id', $business->id)->count());
    }

    public function test_a_rolled_back_submission_emits_no_event_and_a_committed_one_emits_exactly_one(): void
    {
        [, , , , $form, $page] = $this->liveForm();
        $events = 0;
        Event::listen(FormSubmissionRecorded::class, function () use (&$events): void {
            $events++;
        });
        $service = app(WebsiteFormSubmissionService::class);
        $fields = WebsiteFormPresets::photoBoothQuoteRequest();

        DB::beginTransaction();
        $service->submit($form, $fields, 'Quote', $this->payload(), 'quote', '127.0.0.1');
        $this->assertSame(0, $events, 'the event must wait for the commit');
        DB::rollBack();

        $this->assertSame(0, $events);
        $this->assertSame(0, WebsiteFormSubmission::count());
        $this->assertSame(0, Contacts::count());

        DB::beginTransaction();
        $service->submit($form, $fields, 'Quote', $this->payload(), 'quote', '127.0.0.1');
        DB::commit();

        $this->assertSame(1, $events);
        $this->assertNotNull($page);
    }

    public function test_the_contact_timeline_shows_a_linked_submission_but_never_spam_or_another_contacts(): void
    {
        [, $business, , $website, $form, $page] = $this->liveForm();
        $url = $this->route($website, $form, $page);
        $this->post($url, $this->payload());
        $this->post($url, $this->payload(['phone' => '5559998888', WebsiteFormSubmissionService::HONEYPOT_FIELD => 'bot']));
        $this->post($url, $this->payload(['name' => 'Other Person', 'phone' => '5557776666']));

        $contact = Contacts::findOrFail(WebsiteFormSubmission::orderBy('id')->first()->contact_id);
        $subject = new TimelineSubject($business, '5551234567', null, $contact);
        $items = (new FormSubmissionActivitySource())->recent($subject, 50);

        $this->assertCount(1, $items);
        $this->assertSame('Submitted Photo Booth Quote Request', $items[0]->title);

        // Another Business's subject sees nothing.
        [, $otherBusiness] = $this->entitledTenant();
        $this->assertSame([], (new FormSubmissionActivitySource())->recent(new TimelineSubject($otherBusiness, '5551234567', null, $contact), 50));
    }

    // ---------------------------------------------------------------
    // 7. Validation and security
    // ---------------------------------------------------------------

    public function test_only_the_published_fields_are_stored_and_unknown_fields_never_reach_contact_or_business(): void
    {
        [, $business, , $website, $form, $page] = $this->liveForm();

        $this->post($this->route($website, $form, $page), $this->payload([
            'business_id' => 999, 'customer_id' => 999, 'group_id' => 999, 'status' => 'subscribe',
            'location_id' => 999, 'crm_opportunity_id' => 999, 'is_spam' => '1', 'unknown_field' => 'x',
        ]))->assertSessionHasNoErrors();

        $submission = WebsiteFormSubmission::sole();
        $this->assertEqualsCanonicalizing(['name', 'phone', 'email', 'event_date', 'event_type', 'message'], array_keys($submission->data));
        $this->assertFalse($submission->is_spam);
        $contact = Contacts::findOrFail($submission->contact_id);
        $this->assertSame((int) $business->id, (int) $contact->business_id);
        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->status);
        $this->assertNotSame(999, (int) $contact->group_id);
    }

    public function test_required_and_typed_fields_are_enforced(): void
    {
        [, , , $website, $form, $page] = $this->liveForm();
        $url = $this->route($website, $form, $page);

        $this->post($url, ['phone' => '5551234567'])->assertSessionHasErrors('name');
        $this->post($url, ['name' => 'X'])->assertSessionHasErrors('phone');
        $this->post($url, $this->payload(['phone' => 'abcdefghij']))->assertSessionHasErrors('phone');
        $this->post($url, $this->payload(['phone' => '123']))->assertSessionHasErrors('phone');
        $this->post($url, $this->payload(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->post($url, $this->payload(['event_date' => 'someday']))->assertSessionHasErrors('event_date');
        $this->post($url, $this->payload(['message' => str_repeat('a', 2001)]))->assertSessionHasErrors('message');
        $this->post($url, $this->payload(['name' => str_repeat('a', 161)]))->assertSessionHasErrors('name');
        $this->post($url, $this->payload(['name' => ['array']]))->assertSessionHasErrors('name');

        $this->assertSame(0, WebsiteFormSubmission::count());

        $this->post($url, $this->payload(['phone' => '+1 (555) 123-4567', 'email' => 'a@b.test', 'event_date' => '2027-06-01']))->assertSessionHasNoErrors();
        $this->assertSame(1, WebsiteFormSubmission::count());
    }

    public function test_the_whole_payload_is_bounded_whatever_the_fields(): void
    {
        [, , , $website, $form, $page] = $this->liveForm();

        $this->post($this->route($website, $form, $page), $this->payload(['junk' => str_repeat('z', 40000)]))->assertSessionHasErrors('form');

        $this->assertSame(0, WebsiteFormSubmission::count());
    }

    public function test_an_uploaded_file_is_not_accepted_v1_forms_have_no_file_fields(): void
    {
        [, , , $website, $form, $page] = $this->liveForm();

        $this->post($this->route($website, $form, $page), $this->payload([
            'message' => \Illuminate\Http\UploadedFile::fake()->create('evil.php', 4, 'application/x-php'),
            'attachment' => \Illuminate\Http\UploadedFile::fake()->image('a.png'),
        ]))->assertSessionHasErrors('message');

        $this->assertSame(0, WebsiteFormSubmission::count());
    }

    public function test_the_public_route_is_rate_limited_and_csrf_protected(): void
    {
        $route = app('router')->getRoutes()->getByName('public.website.form.submit');

        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('public/websites/*', (new class(app(), app('encrypter')) extends \App\Http\Middleware\VerifyCsrfToken {
            public function exceptList(): array
            {
                return $this->except;
            }
        })->exceptList());
    }

    // ---------------------------------------------------------------
    // 8. Viewing submissions
    // ---------------------------------------------------------------

    public function test_submissions_are_visible_only_for_locations_the_viewer_may_reach_and_are_query_bounded(): void
    {
        [$customer, $business, $workspace, $website, $form, $page, $locationA] = $this->liveForm();
        $locationB = $this->location($business);
        $formB = $this->quoteForm($website, $locationB);
        [$website, $pageB] = $this->publish($website, $formB);

        $this->post($this->route($website, $form, $page), $this->payload(['name' => 'Visitor At A']));
        $this->post($this->route($website, $formB, $pageB), $this->payload(['name' => 'Visitor At B', 'phone' => '5557654321']));
        // A legacy row with no Location, and rows of both forms.
        WebsiteFormSubmission::create(['website_form_id' => $form->id, 'data' => ['name' => 'Legacy Visitor'], 'status' => 'new']);

        $owner = route('customer.workspaces.businesses.website.forms.submissions', [$workspace->uid, $business->uid, $form->uid]);

        // The owner reaches every Location: sees A's rows and the legacy row.
        $this->authenticateAsCustomer($customer);
        $this->get($owner)->assertOk()->assertSee('Visitor At A')->assertSee('Legacy Visitor');
        $this->get($owner.'?location='.$locationB->uid)->assertOk()->assertDontSee('Visitor At A');

        // Staff granted ONLY Location B must not see Location A's submissions.
        $staff = $this->createCustomer();
        $membership = $this->addMember($workspace, $staff->user, WorkspaceMembershipRole::Staff);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locationB);
        $this->authenticateAsUser($staff->user);

        $this->get($owner)->assertOk()->assertDontSee('Visitor At A')->assertDontSee('Legacy Visitor');
        $this->get(route('customer.workspaces.businesses.website.forms.submissions', [$workspace->uid, $business->uid, $formB->uid]))
            ->assertOk()->assertSee('Visitor At B');
        $this->assertNotNull($locationA);
    }

    public function test_the_submissions_page_is_paginated_and_its_query_count_does_not_grow_with_rows(): void
    {
        [$customer, $business, $workspace, , $form, , $location] = $this->liveForm();
        $this->authenticateAsCustomer($customer);
        $url = route('customer.workspaces.businesses.website.forms.submissions', [$workspace->uid, $business->uid, $form->uid]);

        $make = function (int $n) use ($form, $location): void {
            for ($i = 0; $i < $n; $i++) {
                WebsiteFormSubmission::create(['website_form_id' => $form->id, 'location_id' => $location->id, 'data' => ['name' => 'Row '.$i], 'status' => 'new']);
            }
        };
        $count = function () use ($url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $make(3);
        $this->get($url);
        $few = $count();
        $make(40);
        $many = $count();

        $this->assertSame($few, $many, 'queries must not grow with the number of submissions');
        $html = $this->get($url)->getContent();
        $this->assertSame(25, substr_count($html, 'Row '), 'the list is bounded to one page');
    }

    public function test_a_foreign_business_cannot_view_another_businesss_submissions(): void
    {
        [, $business, $workspace, , $form] = $this->liveForm();
        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($otherCustomer);

        $this->get(route('customer.workspaces.businesses.website.forms.submissions', [$workspace->uid, $business->uid, $form->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.website.forms.submissions', [$otherWorkspace->uid, $otherBusiness->uid, $form->uid]))->assertNotFound();
    }

    public function test_validation_exceptions_from_the_service_leave_nothing_behind(): void
    {
        [, , , , $form] = $this->liveForm();

        try {
            app(WebsiteFormSubmissionService::class)->submit($form, WebsiteFormPresets::photoBoothQuoteRequest(), 'Quote', ['name' => 'X'], null, null);
            $this->fail('expected a validation failure');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }

        $this->assertSame(0, WebsiteFormSubmission::count());
    }
}
