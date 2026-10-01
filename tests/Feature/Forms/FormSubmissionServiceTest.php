<?php

namespace Tests\Feature\Forms;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Forms\FormContactResolution;
use App\Events\Forms\FormSubmissionRecorded;
use App\Library\Forms\Exceptions\FormUnavailableException;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\Workspace;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms V1 — the canonical submission: validation, Location resolution from the
 * deployment, Contact resolution, the Opportunity, idempotency, immutability
 * and the after-commit event.
 */
class FormSubmissionServiceTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private Form $form;

    private FormDeployment $deployment;

    private FormSubmissionService $service;

    /** @var list<FormSubmissionRecorded> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business, $this->workspace] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->formsPipeline($this->business);
        [$this->form, $this->deployment] = $this->liveForm($this->business, $this->downtown);
        $this->service = app(FormSubmissionService::class);

        Event::listen(FormSubmissionRecorded::class, function (FormSubmissionRecorded $event): void {
            $this->events[] = $event;
        });
    }

    private function submit(array $answers = [], ?FormDeployment $deployment = null, ?string $token = null)
    {
        $deployment ??= $this->deployment;

        return $this->service->submit($deployment->uid, $this->submitInput($deployment, $answers, $token));
    }

    private function counts(): array
    {
        return [
            'submissions' => FormSubmission::count(),
            'contacts' => Contacts::count(),
            'opportunities' => CrmOpportunity::count(),
            'events' => count($this->events),
        ];
    }

    private function existingContact(BusinessLocation $location, string $phone = '14155551234', ?Business $business = null): Contacts
    {
        $business ??= $this->business;
        $group = ContactGroups::query()->where('business_id', $business->id)->first()
            ?? ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Contacts', 'status' => true]);

        return Contacts::create([
            'customer_id' => $group->customer_id,
            'business_id' => $business->id,
            'location_id' => $location->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
    }

    // ---------------------------------------------------------------- record

    public function test_a_submission_records_business_location_version_source_and_normalized_values(): void
    {
        $result = $this->submit();
        $submission = $result->submission->fresh();

        $this->assertFalse($result->replayed);
        $this->assertSame((int) $this->business->id, (int) $submission->business_id);
        $this->assertSame((int) $this->downtown->id, (int) $submission->business_location_id, 'the Location is the deployment\'s');
        $this->assertSame((int) $this->form->id, (int) $submission->form_id);
        $this->assertSame((int) $this->form->currentVersion()->id, (int) $submission->form_version_id);
        $this->assertSame((int) $this->deployment->id, (int) $submission->form_deployment_id);
        $this->assertSame('direct_link', $submission->source);
        $this->assertSame('form_submission:'.$submission->uid, $submission->occurrence_key);

        // MySQL JSON does not preserve object key order: compare as a map.
        $this->assertEquals([
            'your_name' => 'Ada Lovelace',
            'phone' => '14155551234',
            'email' => 'ada@example.test',
            'event_date' => '2027-06-01',
            'event_type' => 'Wedding',
            'message' => 'Looking for a quote.',
        ], $submission->values);
    }

    public function test_unknown_posted_keys_are_ignored_and_never_stored(): void
    {
        $submission = $this->submit(['business_id' => 999, 'location_id' => 999, 'is_admin' => 1])->submission->fresh();

        $keys = array_keys($submission->values);
        sort($keys);
        $this->assertSame(['email', 'event_date', 'event_type', 'message', 'phone', 'your_name'], $keys);
        $this->assertSame((int) $this->business->id, (int) $submission->business_id);
        $this->assertSame((int) $this->downtown->id, (int) $submission->business_location_id);
    }

    public function test_validation_refuses_bad_answers_writes_nothing_and_does_not_consume_the_token(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $before = $this->counts();

        $bad = [
            'required name missing' => ['your_name' => ''],
            'required phone missing' => ['phone' => ''],
            'phone too short' => ['phone' => '12345'],
            'phone with letters' => ['phone' => 'call me maybe'],
            'invalid email' => ['email' => 'not-an-email'],
            'select outside the options' => ['event_type' => 'Funeral'],
            'invalid date' => ['event_date' => '06/01/2027'],
            'text too long' => ['message' => str_repeat('x', 2001)],
        ];

        foreach ($bad as $label => $answers) {
            try {
                $this->submit($answers, null, $token);
                $this->fail("Expected a validation refusal: {$label}");
            } catch (ValidationException) {
                $this->assertSame($before, $this->counts(), $label.' left rows behind');
            }
        }

        // The same token, now with good answers, still works.
        $this->assertFalse($this->submit([], null, $token)->replayed);
        $this->assertSame(1, FormSubmission::count());
    }

    public function test_optional_blank_answers_are_stored_as_null_and_a_required_checkbox_must_be_ticked(): void
    {
        $form = app(FormManager::class)->create($this->business, $this->leadFormInput([
            'name' => 'Consent form',
            'fields' => [
                ['label' => 'Phone', 'type' => 'phone', 'required' => false],
                ['label' => 'I agree', 'type' => 'checkbox', 'required' => true],
                ['label' => 'Newsletter', 'type' => 'checkbox', 'required' => false],
            ],
        ]));
        app(FormManager::class)->activate($this->business, $form);
        $deployment = $this->deploy($this->business, $form, $this->downtown);

        try {
            $this->service->submit($deployment->uid, ['operation_token' => FormOperationToken::issue($deployment)]);
            $this->fail('an unticked required checkbox must be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('i_agree', $exception->errors());
        }

        $submission = $this->service->submit($deployment->uid, ['i_agree' => '1', 'operation_token' => FormOperationToken::issue($deployment)])->submission->fresh();

        $this->assertSame(['phone' => null, 'i_agree' => true, 'newsletter' => false], $submission->values);
        $this->assertSame(FormContactResolution::None, $submission->contact_resolution);
        $this->assertNull($submission->contact_id);
    }

    // ------------------------------------------------------------- availability

    public function test_an_inactive_or_draft_form_accepts_nothing(): void
    {
        $manager = app(FormManager::class);

        $manager->deactivate($this->business, $this->form);
        $this->assertUnavailable('form_not_active');

        $draft = $this->makeForm($this->business, ['name' => 'Draft one']);
        $draftDeployment = $this->deploy($this->business, $draft, $this->downtown);
        $this->assertUnavailable('form_not_active', $draftDeployment);

        $manager->activate($this->business, $this->form);
        $this->assertFalse($this->submit()->replayed, 'switching it back on makes it accept again');
        $this->assertSame(1, FormSubmission::count());
    }

    public function test_a_disabled_deployment_an_unknown_link_and_a_malformed_uid_accept_nothing(): void
    {
        app(FormManager::class)->setDeployment($this->business, $this->form, $this->downtown, false);
        $this->assertUnavailable('deployment_disabled');

        foreach (['not-a-uuid', '00000000-0000-4000-8000-000000000000', ''] as $uid) {
            try {
                $this->service->submit($uid, []);
                $this->fail('expected an unavailable form for '.$uid);
            } catch (FormUnavailableException $exception) {
                $this->assertSame('unknown_link', $exception->reason);
            }
        }

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_an_archived_location_or_a_foreign_one_accepts_nothing(): void
    {
        $this->downtown->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived, 'archived_at' => now()])->save();
        $this->assertUnavailable('location_archived');
        $this->downtown->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Active, 'archived_at' => null])->save();

        // A deployment row pointing at ANOTHER Business's Location (corrupt data,
        // or an attack on the FK domain): refused, never attributed.
        [, $other] = $this->formsTenant(name: 'Other Studio');
        $foreign = $this->formsLocation($other, 'Elsewhere');
        DB::table('form_deployments')->where('id', $this->deployment->id)->update(['business_location_id' => $foreign->id]);
        $this->assertUnavailable('location_not_in_business');

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_an_inactive_account_or_a_missing_forms_entitlement_accepts_nothing(): void
    {
        $this->denyFeature($this->workspace, PlatformFeature::Forms);
        $this->assertUnavailable('not_entitled');

        $this->workspace->forceFill(['is_active' => false])->save();
        $this->assertUnavailable('account_inactive');

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_forms_is_authorized_by_the_forms_feature_not_by_website_generation(): void
    {
        // Denying the WEBSITE feature leaves Forms working...
        $this->denyFeature($this->workspace, PlatformFeature::WebsiteGeneration);
        $this->assertFalse($this->submit()->replayed);
        $this->assertSame(1, FormSubmission::count());

        // ...and denying FORMS stops it, regardless of Website.
        $this->denyFeature($this->workspace, PlatformFeature::Forms);
        $this->assertUnavailable('not_entitled');
    }

    private function assertUnavailable(string $reason, ?FormDeployment $deployment = null): void
    {
        $deployment ??= $this->deployment;

        try {
            $this->service->submit($deployment->uid, $this->submitInput($deployment));
            $this->fail('expected the form to be unavailable ('.$reason.')');
        } catch (FormUnavailableException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    // ----------------------------------------------------------------- location

    public function test_the_location_comes_only_from_the_deployment_never_from_the_request(): void
    {
        $second = $this->deploy($this->business, $this->form, $this->uptown);

        $a = $this->submit([], $this->deployment)->submission;
        $b = $this->submit([], $second)->submission;

        $this->assertSame((int) $this->downtown->id, (int) $a->business_location_id);
        $this->assertSame((int) $this->uptown->id, (int) $b->business_location_id);
        $this->assertSame(1, Form::where('business_id', $this->business->id)->count(), 'one definition, two Locations');
    }

    public function test_a_posted_location_may_only_restate_the_deployments_location(): void
    {
        $restated = $this->submit([FormSubmissionService::LOCATION_FIELD => $this->downtown->uid]);
        $this->assertSame((int) $this->downtown->id, (int) $restated->submission->business_location_id);

        [, $other] = $this->formsTenant(name: 'Other Studio');
        $foreign = $this->formsLocation($other, 'Elsewhere');

        foreach ([$this->uptown->uid, $foreign->uid, 'garbage'] as $forged) {
            try {
                $this->submit([FormSubmissionService::LOCATION_FIELD => $forged]);
                $this->fail('a forged location must be refused, not ignored');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(FormSubmissionService::LOCATION_FIELD, $exception->errors());
            }
        }

        $this->assertSame(1, FormSubmission::count());
    }

    // ------------------------------------------------------------------ contact

    public function test_a_new_contact_is_created_at_the_submissions_location_without_consent(): void
    {
        $submission = $this->submit()->submission->fresh();
        $contact = Contacts::findOrFail($submission->contact_id);

        $this->assertSame(FormContactResolution::Created, $submission->contact_resolution);
        $this->assertSame((int) $this->business->id, (int) $contact->business_id);
        $this->assertSame((int) $this->downtown->id, (int) $contact->location_id);
        $this->assertEquals('14155551234', $contact->phone);
        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->status, 'an inquiry is never messaging consent');
        $this->assertDatabaseHas('contacts_custom_field', ['contact_id' => $contact->id, 'value' => 'Ada']);
        $this->assertDatabaseHas('contacts_custom_field', ['contact_id' => $contact->id, 'value' => 'Lovelace']);
    }

    public function test_an_existing_contact_at_the_same_location_is_linked_and_never_modified(): void
    {
        $existing = $this->existingContact($this->downtown);
        $status = $existing->status;

        $submission = $this->submit()->submission->fresh();

        $this->assertSame(FormContactResolution::Matched, $submission->contact_resolution);
        $this->assertSame((int) $existing->id, (int) $submission->contact_id);
        $this->assertSame(1, Contacts::count());
        $this->assertSame($status, $existing->fresh()->status);
        $this->assertDatabaseMissing('contacts_custom_field', ['contact_id' => $existing->id, 'value' => 'Ada']);
    }

    public function test_an_ambiguous_match_fails_closed_records_the_lead_and_creates_nothing_else(): void
    {
        $this->existingContact($this->downtown);
        $this->existingContact($this->downtown);
        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput(['create_opportunity' => true]));

        $result = $this->submit();
        $submission = $result->submission->fresh();

        $this->assertSame(FormContactResolution::Ambiguous, $submission->contact_resolution);
        $this->assertNull($submission->contact_id, 'no Contact is picked arbitrarily');
        $this->assertNull($submission->crm_opportunity_id, 'no person, no deal');
        $this->assertSame(2, Contacts::count(), 'no third Contact is created');
        $this->assertSame(0, CrmOpportunity::count());
        $this->assertSame(1, FormSubmission::count(), 'the lead itself is kept');

        $this->assertCount(1, $this->events);
        $this->assertNull($this->events[0]->contactId);
        $this->assertSame('ambiguous', $this->events[0]->contactResolution);
    }

    public function test_identity_is_never_merged_across_locations(): void
    {
        $atUptown = $this->existingContact($this->uptown);
        $second = $this->deploy($this->business, $this->form, $this->uptown);

        $downtown = $this->submit([], $this->deployment)->submission->fresh();

        $this->assertSame(FormContactResolution::Created, $downtown->contact_resolution);
        $this->assertNotSame((int) $atUptown->id, (int) $downtown->contact_id, 'the Uptown person is not reused at Downtown');
        $this->assertSame(2, Contacts::where('phone', '14155551234')->count());

        $uptown = $this->submit([], $second)->submission->fresh();
        $this->assertSame(FormContactResolution::Matched, $uptown->contact_resolution);
        $this->assertSame((int) $atUptown->id, (int) $uptown->contact_id);
    }

    public function test_identity_is_never_merged_across_businesses_or_into_a_location_less_legacy_contact(): void
    {
        [, $other] = $this->formsTenant(name: 'Other Studio');
        $otherLocation = $this->formsLocation($other, 'Elsewhere');
        $theirs = $this->existingContact($otherLocation, '14155551234', $other);

        $legacy = $this->existingContact($this->downtown);
        $legacy->forceFill(['location_id' => null])->save();

        $submission = $this->submit()->submission->fresh();

        $this->assertSame(FormContactResolution::Created, $submission->contact_resolution);
        $this->assertNotContains((int) $submission->contact_id, [(int) $theirs->id, (int) $legacy->id]);
        $this->assertSame((int) $this->business->id, (int) Contacts::find($submission->contact_id)->business_id);
    }

    // -------------------------------------------------------------- opportunity

    public function test_an_opportunity_is_created_at_the_submission_location_when_configured(): void
    {
        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput(['create_opportunity' => true]));

        $submission = $this->submit()->submission->fresh();
        $opportunity = CrmOpportunity::findOrFail($submission->crm_opportunity_id);

        $this->assertSame(1, CrmOpportunity::count());
        $this->assertSame((int) $this->business->id, (int) $opportunity->business_id);
        $this->assertSame((int) $this->downtown->id, (int) $opportunity->location_id);
        $this->assertSame((int) $submission->contact_id, (int) $opportunity->contact_id);
        $this->assertSame(CrmOpportunity::SOURCE_FORM, $opportunity->source);
        $this->assertSame('Ada Lovelace — Quote request', $opportunity->title);
    }

    public function test_no_opportunity_unless_configured_or_without_a_phone(): void
    {
        $this->assertNull($this->submit()->submission->fresh()->crm_opportunity_id);

        $form = app(FormManager::class)->create($this->business, $this->leadFormInput([
            'name' => 'Optional phone',
            'create_opportunity' => true,
            'fields' => [['label' => 'Phone', 'type' => 'phone', 'required' => false], ['label' => 'Note', 'type' => 'text']],
        ]));
        app(FormManager::class)->activate($this->business, $form);
        $deployment = $this->deploy($this->business, $form, $this->downtown);

        $submission = $this->service->submit($deployment->uid, ['note' => 'hi', 'operation_token' => FormOperationToken::issue($deployment)])->submission->fresh();

        $this->assertSame(FormContactResolution::None, $submission->contact_resolution);
        $this->assertNull($submission->crm_opportunity_id);
        $this->assertSame(0, CrmOpportunity::count());
    }

    public function test_a_stale_pipeline_keeps_the_lead_and_skips_the_deal(): void
    {
        $pipeline = $this->formsPipeline($this->business);
        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput([
            'create_opportunity' => true,
            'opportunity_pipeline_id' => $pipeline->id,
        ]));
        DB::table('crm_pipelines')->where('id', $pipeline->id)->update(['archived_at' => now()]);

        $submission = $this->submit()->submission->fresh();

        $this->assertNull($submission->crm_opportunity_id);
        $this->assertSame(0, CrmOpportunity::count());
        $this->assertNotNull($submission->contact_id, 'the lead and its Contact are kept');
    }

    // ---------------------------------------------------------------- idempotency

    public function test_a_retry_with_the_same_token_converges_on_one_of_everything(): void
    {
        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput(['create_opportunity' => true]));
        $token = FormOperationToken::issue($this->deployment);

        $first = $this->submit([], null, $token);
        $afterFirst = $this->counts();
        $second = $this->submit([], null, $token);
        $third = $this->submit([], null, $token);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertTrue($third->replayed);
        $this->assertSame($first->submission->id, $second->submission->id);
        $this->assertSame($first->submission->id, $third->submission->id);
        $this->assertSame($afterFirst, $this->counts(), 'a replay creates and emits nothing');
        $this->assertSame(['submissions' => 1, 'contacts' => 1, 'opportunities' => 1, 'events' => 1], $afterFirst);
    }

    public function test_two_genuine_submissions_with_identical_bodies_stay_separate(): void
    {
        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput(['create_opportunity' => true]));

        $a = $this->submit();
        $b = $this->submit();

        $this->assertNotSame($a->submission->id, $b->submission->id);
        $this->assertSame($a->submission->payload_hash, $b->submission->payload_hash, 'identical bodies');
        $this->assertSame(['submissions' => 2, 'contacts' => 1, 'opportunities' => 2, 'events' => 2], $this->counts());
        $this->assertSame(FormContactResolution::Created, $a->submission->fresh()->contact_resolution);
        $this->assertSame(FormContactResolution::Matched, $b->submission->fresh()->contact_resolution);
    }

    public function test_the_same_token_with_a_different_body_is_refused_not_answered_with_the_first(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $first = $this->submit([], null, $token)->submission;

        try {
            $this->submit(['message' => 'A different message'], null, $token);
            $this->fail('a replay with a different body must be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('form', $exception->errors());
        }

        $this->assertSame(1, FormSubmission::count());
        $this->assertSame('Looking for a quote.', $first->fresh()->values['message']);
    }

    public function test_a_missing_forged_or_foreign_token_is_refused(): void
    {
        $other = $this->deploy($this->business, $this->form, $this->uptown);
        $valid = FormOperationToken::issue($this->deployment);
        [$nonce, $versionId, $signature] = explode('.', $valid);

        $tokens = [
            'missing' => null,
            'empty' => '',
            'garbage' => 'garbage',
            'no signature' => $nonce.'.'.$versionId.'.',
            'wrong signature' => $nonce.'.'.$versionId.'.'.str_repeat('0', 64),
            'invented nonce' => str_repeat('a', 32).'.'.$versionId.'.'.$signature,
            'old two-part shape' => $nonce.'.'.$signature,
            'issued for another deployment' => FormOperationToken::issue($other),
        ];

        foreach ($tokens as $label => $token) {
            $input = $this->submitInput($this->deployment);
            $input[FormSubmissionService::TOKEN_FIELD] = $token;
            try {
                $this->service->submit($this->deployment->uid, $input);
                $this->fail("expected the token to be refused: {$label}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('form', $exception->errors(), $label);
            }
        }

        $this->assertSame(0, FormSubmission::count());
    }

    public function test_the_database_itself_refuses_a_second_row_for_one_token_and_one_occurrence(): void
    {
        $submission = $this->submit()->submission;
        $row = collect($submission->getAttributes())->except('id')->all();
        $row['values'] = json_encode($submission->values);

        // Same (deployment, nonce): the unique index is the concurrency backstop.
        try {
            DB::table('form_submissions')->insert(array_merge($row, ['uid' => (string) \Illuminate\Support\Str::uuid(), 'occurrence_key' => 'form_submission:other']));
            $this->fail('the unique (deployment, nonce) index must refuse a duplicate claim');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, FormSubmission::count());
        }

        // Same occurrence key under a different nonce.
        try {
            DB::table('form_submissions')->insert(array_merge($row, ['uid' => (string) \Illuminate\Support\Str::uuid(), 'operation_nonce' => str_repeat('b', 32)]));
            $this->fail('the unique occurrence_key index must refuse a duplicate occurrence');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, FormSubmission::count());
        }
    }

    // ------------------------------------------------------------------- history

    public function test_a_historical_submission_stays_intelligible_after_the_definition_is_edited(): void
    {
        $old = $this->submit()->submission;
        $oldVersionId = $old->form_version_id;

        // The editor round-trips each question's stable key (a hidden input), so
        // relabelling keeps the key; dropping a row removes the question.
        $fields = $this->form->currentVersion()->fields;
        $fields[5]['label'] = 'Anything else?';
        unset($fields[3]); // drop "Event date"
        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput(['name' => 'Renamed form', 'fields' => array_values($fields)]));

        $new = $this->submit()->submission->fresh();
        $old = $old->fresh();

        $this->assertSame((int) $oldVersionId, (int) $old->form_version_id, 'an old submission keeps its own version');
        $this->assertNotSame((int) $oldVersionId, (int) $new->form_version_id);

        $oldFields = collect($old->version->fields)->pluck('label', 'key')->all();
        $this->assertSame('Message', $oldFields['message']);
        $this->assertSame('Event date', $oldFields['event_date']);
        $this->assertSame('2027-06-01', $old->values['event_date'], 'the dropped question\'s answer is still there and labelled');

        $newFields = collect($new->version->fields)->pluck('label', 'key')->all();
        $this->assertSame('Anything else?', $newFields['message']);
        $this->assertArrayNotHasKey('event_date', $newFields);
        $this->assertArrayNotHasKey('event_date', $new->values);
    }

    public function test_a_submission_is_immutable(): void
    {
        $submission = $this->submit()->submission->fresh();

        foreach ([
            fn () => $submission->update(['values' => ['x' => 'y']]),
            fn () => $submission->forceFill(['business_location_id' => $this->uptown->id])->save(),
            fn () => $submission->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a submission must refuse update and delete');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame((int) $this->downtown->id, (int) $submission->fresh()->business_location_id);
    }

    // --------------------------------------------------------------------- event

    public function test_exactly_one_stable_after_commit_event_carrying_ids_only(): void
    {
        $this->assertTrue(is_subclass_of(FormSubmissionRecorded::class, ShouldDispatchAfterCommit::class));

        app(FormManager::class)->update($this->business, $this->form, $this->leadFormInput(['create_opportunity' => true]));
        $submission = $this->submit()->submission->fresh();

        $this->assertCount(1, $this->events);
        $event = $this->events[0];

        $this->assertSame((int) $this->business->id, $event->businessId);
        $this->assertSame((int) $this->downtown->id, $event->locationId);
        $this->assertSame((int) $this->form->id, $event->formId);
        $this->assertSame((int) $submission->form_version_id, $event->formVersionId);
        $this->assertSame((int) $submission->id, $event->submissionId);
        $this->assertSame((int) $submission->contact_id, $event->contactId);
        $this->assertSame((int) $submission->crm_opportunity_id, $event->opportunityId);
        $this->assertSame('created', $event->contactResolution);
        $this->assertSame('form_submission:'.$submission->uid, $event->occurrenceKey);
        $this->assertSame($event->occurrenceKey, FormSubmissionRecorded::occurrenceKeyFor($submission->uid));

        // Ids and scalars only — never a model, a value or a personal detail.
        foreach ((new \ReflectionClass(FormSubmissionRecorded::class))->getConstructor()->getParameters() as $parameter) {
            $this->assertContains((string) $parameter->getType(), ['int', '?int', 'string'], $parameter->getName());
        }
    }

    public function test_no_event_for_a_replay(): void
    {
        $token = FormOperationToken::issue($this->deployment);
        $this->submit([], null, $token);
        $this->submit([], null, $token);

        $this->assertCount(1, $this->events);
    }

    public function test_a_rolled_back_submission_leaves_no_rows_and_no_event_and_keeps_the_token_usable(): void
    {
        Blacklists::create([
            'user_id' => $this->business->customer_id,
            'business_id' => $this->business->id,
            'number' => '14155551234',
            'reason' => 'Fixture',
        ]);
        $token = FormOperationToken::issue($this->deployment);

        try {
            $this->submit([], null, $token);
            $this->fail('a blacklisted phone must be refused');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }

        $this->assertSame(['submissions' => 0, 'contacts' => 0, 'opportunities' => 0, 'events' => 0], $this->counts());

        Blacklists::query()->delete();
        $this->assertFalse($this->submit([], null, $token)->replayed, 'the failed attempt did not consume the token');
        $this->assertSame(['submissions' => 1, 'contacts' => 1, 'opportunities' => 0, 'events' => 1], $this->counts());
    }

    public function test_an_event_is_not_delivered_while_an_enclosing_transaction_can_still_roll_back(): void
    {
        DB::beginTransaction();
        $this->submit();
        $this->assertCount(0, $this->events, 'after-commit: nothing is delivered before the outer commit');
        DB::rollBack();

        $this->assertCount(0, $this->events);
        $this->assertSame(0, FormSubmission::count());
    }
}
