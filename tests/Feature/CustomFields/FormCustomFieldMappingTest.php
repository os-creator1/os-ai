<?php

namespace Tests\Feature\CustomFields;

use App\Enums\Forms\FormContactResolution;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Form;
use App\Models\FormDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * "Save answer to" — the explicit Form / Questionnaire question -> Contact
 * Custom Field mapping, by stable field uid.
 */
class FormCustomFieldMappingTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    private Business $business;

    private BusinessLocation $downtown;

    private CustomFieldDefinitionManager $fields;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->formsTenant();
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->formsPipeline($this->business);
        $this->fields = app(CustomFieldDefinitionManager::class);
    }

    /** A lead form whose questions are mapped: label => custom field uid. */
    private function mappedInput(array $mapping, array $overrides = []): array
    {
        $input = $this->leadFormInput($overrides);
        $input['fields'] = array_map(function (array $field) use ($mapping): array {
            return isset($mapping[$field['label']]) ? $field + ['custom_field_uid' => $mapping[$field['label']]] : $field;
        }, $input['fields']);

        return $input;
    }

    /** @return array{0: Form, 1: FormDeployment} */
    private function liveMapped(array $mapping, array $overrides = []): array
    {
        $manager = app(FormManager::class);
        $form = $manager->activate($this->business, $manager->create($this->business, $this->mappedInput($mapping, $overrides)));

        return [$form, $this->deploy($this->business, $form, $this->downtown)];
    }

    private function submit(FormDeployment $deployment, array $answers = [])
    {
        return app(FormSubmissionService::class)->submit($deployment->uid, $this->submitInput($deployment, $answers));
    }

    private function existingContact(string $phone = '14155551234'): Contacts
    {
        $group = ContactGroups::query()->where('business_id', $this->business->id)->first()
            ?? ContactGroups::create(['customer_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'name' => 'Contacts', 'status' => true]);

        return Contacts::create([
            'customer_id' => $group->customer_id, 'business_id' => $this->business->id, 'location_id' => $this->downtown->id,
            'group_id' => $group->id, 'phone' => $phone, 'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
    }

    private function valueOf(Contacts $contact, CustomFieldDefinition $field): mixed
    {
        return app(CustomFieldValueService::class)->valuesFor($this->business, $contact)
            ->firstWhere(fn (array $entry) => $entry['definition']->id === $field->id)['value'] ?? null;
    }

    public function test_the_mapping_is_stored_by_field_uid_in_the_form_version_and_unmapped_forms_are_unchanged(): void
    {
        $eventDate = $this->fields->create($this->business, 'Event Date', 'date');
        $mapped = $this->makeForm($this->business, ['name' => 'Mapped'] + $this->mappedInput(['Event date' => $eventDate->uid]));
        $plain = $this->makeForm($this->business, ['name' => 'Plain']);

        $field = collect($mapped->currentVersion()->fields)->firstWhere('key', 'event_date');
        $this->assertSame($eventDate->uid, $field['custom_field_uid']);
        $this->assertArrayNotHasKey('custom_field_uid', collect($plain->currentVersion()->fields)->firstWhere('key', 'event_date'), 'No mapping, no key: an unmapped form hashes as it always did.');
    }

    public function test_a_submission_saves_the_mapped_answer_to_a_created_contact(): void
    {
        $eventDate = $this->fields->create($this->business, 'Event Date', 'date');
        $venue = $this->fields->create($this->business, 'Venue', 'text');
        [, $deployment] = $this->liveMapped(['Event date' => $eventDate->uid, 'Message' => $venue->uid]);

        $submission = $this->submit($deployment, ['message' => 'Grand Hotel'])->submission->fresh();
        $contact = $submission->contact;

        $this->assertSame(FormContactResolution::Created, $submission->contact_resolution);
        $this->assertSame('2027-06-01', $this->valueOf($contact, $eventDate));
        $this->assertSame('Grand Hotel', $this->valueOf($contact, $venue));

        $this->assertSame('Grand Hotel', $submission->values['message'], 'The answer also stays on the write-once submission.');
    }

    public function test_a_matched_contact_gets_only_the_explicitly_mapped_field(): void
    {
        $existing = $this->existingContact();
        $status = $existing->status;
        $eventDate = $this->fields->create($this->business, 'Event Date', 'date');
        $other = $this->fields->create($this->business, 'Venue', 'text');
        app(CustomFieldValueService::class)->set($this->business, $existing, $other, 'Keep me');
        [, $deployment] = $this->liveMapped(['Event date' => $eventDate->uid]);

        $submission = $this->submit($deployment)->submission->fresh();

        $this->assertSame(FormContactResolution::Matched, $submission->contact_resolution);
        $this->assertSame('2027-06-01', $this->valueOf($existing, $eventDate), 'The explicit mapping updates the matched Contact.');
        $this->assertSame('Keep me', $this->valueOf($existing, $other), 'An unrelated custom field is untouched.');
        $this->assertSame($status, $existing->fresh()->status);
        $this->assertDatabaseMissing('contacts_custom_field', ['contact_id' => $existing->id, 'value' => 'Ada']);
        $this->assertDatabaseMissing('contacts_custom_field', ['contact_id' => $existing->id, 'value' => 'ada@example.test']);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('contact_tags')->count());
    }

    public function test_an_ambiguous_match_writes_no_custom_field(): void
    {
        $this->existingContact();
        $this->existingContact();
        $eventDate = $this->fields->create($this->business, 'Event Date', 'date');
        [, $deployment] = $this->liveMapped(['Event date' => $eventDate->uid]);

        $submission = $this->submit($deployment)->submission->fresh();

        $this->assertSame(FormContactResolution::Ambiguous, $submission->contact_resolution);
        $this->assertSame(0, CustomFieldValue::query()->count());
        $this->assertSame(1, \App\Models\FormSubmission::count(), 'The lead itself is kept.');
    }

    public function test_a_blank_answer_never_destroys_an_existing_value_and_an_invalid_one_is_skipped(): void
    {
        $existing = $this->existingContact();
        $eventDate = $this->fields->create($this->business, 'Event Date', 'date');
        $guests = $this->fields->create($this->business, 'Guest Count', 'number');
        app(CustomFieldValueService::class)->set($this->business, $existing, $eventDate, '2026-12-25');
        app(CustomFieldValueService::class)->set($this->business, $existing, $guests, '100');
        [, $deployment] = $this->liveMapped(['Event date' => $eventDate->uid, 'Your name' => $guests->uid]);

        $submission = $this->submit($deployment, ['event_date' => '', 'your_name' => 'lots of people'])->submission->fresh();

        $this->assertSame(1, \App\Models\FormSubmission::count(), 'The submission still succeeds.');
        $this->assertSame('2026-12-25', $this->valueOf($existing, $eventDate), 'Blank answer: old value kept.');
        $this->assertSame('100', $this->valueOf($existing, $guests), 'Unusable answer: old value kept.');
        $this->assertSame('lots of people', $submission->values['your_name']);
    }

    public function test_a_dropdown_question_saves_the_matching_option_by_stable_id(): void
    {
        $kind = $this->fields->create($this->business, 'Event Kind', 'select', ['Wedding', 'Corporate', 'Birthday']);
        [, $deployment] = $this->liveMapped(['Event type' => $kind->uid]);

        $contact = $this->submit($deployment, ['event_type' => 'Corporate'])->submission->fresh()->contact;

        $this->assertSame($kind->optionList()[1]['id'], $this->valueOf($contact, $kind));
    }

    public function test_a_checkbox_only_ever_sets_yes(): void
    {
        $insured = $this->fields->create($this->business, 'Insured', 'boolean');
        $input = $this->leadFormInput();
        $input['fields'][] = ['label' => 'We are insured', 'type' => 'checkbox', 'required' => false, 'custom_field_uid' => $insured->uid];
        $manager = app(FormManager::class);
        $form = $manager->activate($this->business, $manager->create($this->business, $input));
        $deployment = $this->deploy($this->business, $form, $this->downtown);
        $existing = $this->existingContact();
        app(CustomFieldValueService::class)->set($this->business, $existing, $insured, true);

        $this->submit($deployment, ['we_are_insured' => '0']);
        $this->assertTrue($this->valueOf($existing, $insured), 'An unchecked box does not overwrite.');

        app(CustomFieldValueService::class)->set($this->business, $existing, $insured, false);
        $this->submit($deployment, ['we_are_insured' => '1']);
        $this->assertTrue($this->valueOf($existing, $insured));
    }

    public function test_a_questionnaire_maps_answers_across_pages(): void
    {
        $eventDate = $this->fields->create($this->business, 'Event Date', 'date');
        $kind = $this->fields->create($this->business, 'Event Kind', 'select', ['Wedding', 'Corporate']);
        $input = $this->questionnaireInput();
        $input['fields'] = array_map(fn (array $field): array => match ($field['label']) {
            'Event date' => $field + ['custom_field_uid' => $eventDate->uid],
            'Event type' => $field + ['custom_field_uid' => $kind->uid],
            default => $field,
        }, $input['fields']);
        $manager = app(FormManager::class);
        $form = $manager->activate($this->business, $manager->create($this->business, $input));
        $deployment = $this->deploy($this->business, $form, $this->downtown);
        $token = FormOperationToken::issue($deployment);
        $service = app(FormSubmissionService::class);

        $service->submit($deployment->uid, $this->stepInput($token, 'page_1'));
        $this->assertSame(0, CustomFieldValue::query()->count(), 'Moving between pages writes nothing.');
        $service->submit($deployment->uid, $this->stepInput($token, 'page_2'));
        $final = $service->submit($deployment->uid, $this->stepInput($token, 'page_3'));

        $contact = $final->submission->fresh()->contact;
        $this->assertSame('2027-06-01', $this->valueOf($contact, $eventDate));
        $this->assertSame($kind->optionList()[0]['id'], $this->valueOf($contact, $kind));
    }

    public function test_renaming_the_custom_field_does_not_change_where_the_answer_goes(): void
    {
        $eventDate = $this->fields->create($this->business, 'Event Date', 'date');
        [, $deployment] = $this->liveMapped(['Event date' => $eventDate->uid]);
        $this->fields->update($this->business, $eventDate, 'The Big Day');

        $contact = $this->submit($deployment)->submission->fresh()->contact;

        $this->assertSame('2027-06-01', $this->valueOf($contact, $eventDate));
    }

    public function test_a_foreign_unknown_or_duplicate_mapping_is_refused_at_save(): void
    {
        [, $other] = $this->formsTenant(\App\Enums\Entitlement\WorkspacePlanTier::Core, 'Other Business');
        $foreign = $this->fields->create($other, 'Event Date', 'date');
        $mine = $this->fields->create($this->business, 'Event Date', 'date');
        $manager = app(FormManager::class);

        foreach ([$foreign->uid, '00000000-0000-0000-0000-000000000000'] as $uid) {
            try {
                $manager->create($this->business, $this->mappedInput(['Event date' => $uid]));
                $this->fail('A foreign / unknown uid must fail closed.');
            } catch (FormRuleException $exception) {
                $this->assertStringContainsString('does not exist', $exception->getMessage());
            }
        }

        $this->expectException(FormRuleException::class);
        $manager->create($this->business, $this->mappedInput(['Event date' => $mine->uid, 'Message' => $mine->uid]));
    }

    public function test_an_incompatible_type_or_option_is_refused_at_save(): void
    {
        $number = $this->fields->create($this->business, 'Guest Count', 'number');
        $kind = $this->fields->create($this->business, 'Kind', 'select', ['Wedding', 'Corporate']);
        $manager = app(FormManager::class);

        try {
            $manager->create($this->business, $this->mappedInput(['Event date' => $number->uid]));
            $this->fail('A date question cannot feed a number field.');
        } catch (FormRuleException $exception) {
            $this->assertStringContainsString('cannot be saved to', $exception->getMessage());
        }

        $this->expectException(FormRuleException::class);
        // The form offers Birthday, which the custom dropdown does not.
        $manager->create($this->business, $this->mappedInput(['Event type' => $kind->uid]));
    }

    public function test_an_archived_field_cannot_be_newly_mapped_but_an_existing_mapping_survives_and_stops_writing(): void
    {
        $eventDate = $this->fields->create($this->business, 'Event Date', 'date');
        $other = $this->fields->create($this->business, 'Other Date', 'date');
        [$form, $deployment] = $this->liveMapped(['Event date' => $eventDate->uid]);
        $this->fields->archive($this->business, $eventDate);
        $this->fields->archive($this->business, $other);
        $manager = app(FormManager::class);

        // Re-saving the form with the mapping it already had is allowed...
        $manager->update($this->business, $form, $this->mappedInput(['Event date' => $eventDate->uid], ['name' => 'Renamed']));

        // ...but pointing a question at an archived field it was not mapped to is not.
        try {
            $manager->update($this->business, $form, $this->mappedInput(['Event date' => $eventDate->uid, 'Message' => $other->uid]));
            $this->fail('A new mapping to an archived field must be refused.');
        } catch (FormRuleException $exception) {
            $this->assertStringContainsString('archived', $exception->getMessage());
        }

        // The archived field is no longer written, and nothing breaks.
        $contact = $this->submit($deployment)->submission->fresh()->contact;
        $this->assertNull($this->valueOf($contact, $eventDate));
        $this->assertSame(1, \App\Models\FormSubmission::count());
    }
}
