<?php

namespace Tests\Feature\CustomFields;

use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldRuleException;
use App\Library\CustomFields\CustomFieldValueService;
use App\Models\CustomFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Business-wide Custom Fields — definitions (stable keys, archive, ordering),
 * typed value validation and the canonical writer's tenancy and blank rules.
 */
class CustomFieldDefinitionAndValueTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private function manager(): CustomFieldDefinitionManager
    {
        return app(CustomFieldDefinitionManager::class);
    }

    private function values(): CustomFieldValueService
    {
        return app(CustomFieldValueService::class);
    }

    public function test_a_field_is_created_with_a_key_derived_from_its_label_and_a_merge_token(): void
    {
        [, $business] = $this->crmTenant();

        $field = $this->manager()->create($business, 'Event Date', 'date');

        $this->assertSame('event_date', $field->key);
        $this->assertSame('contact', $field->entity);
        $this->assertSame('{{contact.event_date}}', $field->token());
        $this->assertNotNull($field->uid);
    }

    public function test_editing_the_label_never_changes_the_key_or_type(): void
    {
        [, $business] = $this->crmTenant();
        $field = $this->manager()->create($business, 'Event Date', 'date');

        $this->manager()->update($business, $field, 'When is the event?');
        $field->refresh();

        $this->assertSame('When is the event?', $field->label);
        $this->assertSame('event_date', $field->key);
        $this->assertSame('date', $field->type);
        $this->assertSame('{{contact.event_date}}', $field->token());
    }

    public function test_a_key_collision_gets_a_numeric_suffix_and_a_built_in_key_is_never_shadowed(): void
    {
        [, $business] = $this->crmTenant();
        $first = $this->manager()->create($business, 'Venue', 'text');
        $this->manager()->archive($business, $first);
        // An archived label no longer blocks the name, but its KEY stays taken.
        $second = $this->manager()->create($business, 'Venue', 'text');
        $shadow = $this->manager()->create($business, 'First Name', 'text');
        $odd = $this->manager()->create($business, '2024 Plan!', 'text');

        $this->assertSame('venue', $first->key);
        $this->assertSame('venue_2', $second->key);
        $this->assertSame('first_name_2', $shadow->key);
        $this->assertSame('field_2024_plan', $odd->key);
    }

    public function test_two_active_fields_cannot_share_a_label(): void
    {
        [, $business] = $this->crmTenant();
        $this->manager()->create($business, 'Venue', 'text');

        $this->expectException(CustomFieldRuleException::class);
        $this->manager()->create($business, ' venue ', 'text');
    }

    public function test_definitions_are_isolated_between_businesses(): void
    {
        [, $a] = $this->crmTenant('Business A', 'Workspace A');
        [, $b] = $this->crmTenant('Business B', 'Workspace B');

        $fieldA = $this->manager()->create($a, 'Venue', 'text');
        $fieldB = $this->manager()->create($b, 'Venue', 'text');

        $this->assertSame('venue', $fieldB->key, 'The same key is free in another Business.');
        $this->assertCount(1, $this->manager()->forBusiness($a));
        $this->assertNull($this->manager()->findByUid($b, $fieldA->uid), 'A foreign uid is not found.');
        $this->assertNull($this->manager()->findByKey($b, 'event_date'));

        $this->expectException(CustomFieldRuleException::class);
        $this->manager()->update($b, $fieldA, 'Hijacked');
    }

    public function test_archiving_keeps_values_and_blocks_new_writes_but_still_reads(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $field = $this->manager()->create($business, 'Venue', 'text');
        $this->values()->set($business, $contact, $field, 'Grand Hotel');

        $this->manager()->archive($business, $field);
        $field->refresh();

        $this->assertTrue($field->isArchived());
        $this->assertSame(1, CustomFieldValue::query()->count(), 'Archive never deletes values.');
        $this->assertCount(0, $this->manager()->forBusiness($business));
        $this->assertCount(1, $this->manager()->forBusiness($business, true));
        $this->assertSame('Grand Hotel', $this->values()->valuesFor($business, $contact)->first()['value']);
        $this->assertSame(CustomFieldValueService::SKIPPED_INVALID, $this->values()->applyAnswer($business, $contact, $field, 'Elsewhere'));

        $this->expectException(CustomFieldRuleException::class);
        $this->values()->set($business, $contact, $field, 'Elsewhere');
    }

    public function test_reorder_applies_the_given_order_and_refuses_foreign_uids(): void
    {
        [, $a] = $this->crmTenant('Business A', 'Workspace A');
        [, $b] = $this->crmTenant('Business B', 'Workspace B');
        $one = $this->manager()->create($a, 'One', 'text');
        $two = $this->manager()->create($a, 'Two', 'text');
        $three = $this->manager()->create($a, 'Three', 'text');
        $foreign = $this->manager()->create($b, 'Foreign', 'text');

        $this->manager()->reorder($a, [$three->uid, $one->uid]);

        $this->assertSame(['Three', 'One', 'Two'], $this->manager()->forBusiness($a)->pluck('label')->all());

        try {
            $this->manager()->reorder($a, [$foreign->uid, $one->uid]);
            $this->fail('A foreign uid must fail closed.');
        } catch (CustomFieldRuleException) {
            $this->assertSame(['Three', 'One', 'Two'], $this->manager()->forBusiness($a)->pluck('label')->all());
        }
    }

    public function test_every_type_validates_and_round_trips(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $manager = $this->manager();
        $cases = [
            ['Notes', 'text', 'Bring the arch', 'Bring the arch'],
            ['Brief', 'long_text', "Line 1\nLine 2", "Line 1\nLine 2"],
            ['Guest Count', 'number', '1,180', '1180'],
            ['Budget', 'currency', '2500.50', '2500.5'],
            ['Event Date', 'date', '2027-06-14', '2027-06-14'],
            ['Event Start', 'datetime', '2027-06-14T18:30', '2027-06-14 18:30:00'],
            ['Insured', 'boolean', 'yes', true],
            ['Planner Email', 'email', 'planner@example.com', 'planner@example.com'],
            ['Planner Phone', 'phone', '+1 (415) 555-0100', '+1 (415) 555-0100'],
        ];

        foreach ($cases as [$label, $type, $input, $expected]) {
            $field = $manager->create($business, $label, $type);
            $this->values()->set($business, $contact, $field, $input);
            $stored = $this->values()->valuesFor($business, $contact)->firstWhere(fn ($e) => $e['definition']->id === $field->id);

            $this->assertSame($expected, $stored['value'], $type);
        }

        $select = $manager->create($business, 'Event Type', 'select', ['Wedding', 'Corporate']);
        $multi = $manager->create($business, 'Extras', 'multi_select', ['Props', 'Backdrop', 'Prints']);
        $this->values()->set($business, $contact, $select, 'corporate');
        $this->values()->set($business, $contact, $multi, ['Props', $multi->optionList()[2]['id']]);

        $entries = $this->values()->valuesFor($business, $contact);
        $this->assertSame($select->optionList()[1]['id'], $entries->firstWhere(fn ($e) => $e['definition']->id === $select->id)['value']);
        $this->assertSame(
            [$multi->optionList()[0]['id'], $multi->optionList()[2]['id']],
            $entries->firstWhere(fn ($e) => $e['definition']->id === $multi->id)['value'],
        );
    }

    public function test_invalid_values_are_refused_for_each_type(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $bad = [
            ['number', 'abc'],
            ['number', '12.34567'],
            ['currency', 'ten dollars'],
            ['date', '14/06/2027'],
            ['date', '2027-02-30'],
            ['datetime', '2027-06-14'],
            ['boolean', 'maybe'],
            ['email', 'not-an-email'],
            ['phone', 'call me'],
            ['text', str_repeat('x', 256)],
        ];

        foreach ($bad as $index => [$type, $value]) {
            $field = $this->manager()->create($business, 'Field ' . $index, $type);

            try {
                $this->values()->set($business, $contact, $field, $value);
                $this->fail("{$type} accepted {$value}");
            } catch (CustomFieldRuleException) {
                $this->assertCount(0, $this->values()->valuesFor($business, $contact));
            }
        }

        $select = $this->manager()->create($business, 'Choice', 'select', ['Yes please', 'No thanks']);

        $this->expectException(CustomFieldRuleException::class);
        $this->values()->set($business, $contact, $select, 'Something else');
    }

    public function test_option_ids_are_stable_so_a_label_edit_never_corrupts_stored_values(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $field = $this->manager()->create($business, 'Event Type', 'select', ['Wedding', 'Corporate']);
        $weddingId = $field->optionList()[0]['id'];
        $this->values()->set($business, $contact, $field, $weddingId);

        $this->manager()->update($business, $field, 'Event Type', [
            ['id' => $weddingId, 'label' => 'Wedding reception'],
            ['id' => $field->optionList()[1]['id'], 'label' => 'Corporate'],
            'Birthday',
        ]);
        $field->refresh();

        $this->assertSame($weddingId, $field->optionList()[0]['id']);
        $this->assertSame('Wedding reception', $field->optionList()[0]['label']);
        $this->assertCount(3, $field->optionList());
        $this->assertSame($weddingId, $this->values()->valuesFor($business, $contact)->first()['value']);
    }

    public function test_set_replaces_clear_removes_and_applyAnswer_ignores_blank_answers(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $field = $this->manager()->create($business, 'Venue', 'text');

        $this->values()->set($business, $contact, $field, 'Grand Hotel');
        $this->values()->set($business, $contact, $field, 'Riverside Barn');
        $this->assertSame('Riverside Barn', $this->values()->valuesFor($business, $contact)->first()['value']);
        $this->assertSame(1, CustomFieldValue::query()->count());

        // A blank Form answer must NOT destroy the existing value.
        $this->assertSame(CustomFieldValueService::SKIPPED_BLANK, $this->values()->applyAnswer($business, $contact, $field, '   '));
        $this->assertSame('Riverside Barn', $this->values()->valuesFor($business, $contact)->first()['value']);

        $this->assertSame(CustomFieldValueService::APPLIED, $this->values()->applyAnswer($business, $contact, $field, 'City Hall'));
        $this->assertSame('City Hall', $this->values()->valuesFor($business, $contact)->first()['value']);

        $this->values()->clear($business, $contact, $field);
        $this->assertCount(0, $this->values()->valuesFor($business, $contact));
    }

    public function test_an_invalid_form_answer_is_skipped_and_leaves_the_old_value(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $field = $this->manager()->create($business, 'Guest Count', 'number');
        $this->values()->set($business, $contact, $field, '100');

        $this->assertSame(CustomFieldValueService::SKIPPED_INVALID, $this->values()->applyAnswer($business, $contact, $field, 'lots'));
        $this->assertSame('100', $this->values()->valuesFor($business, $contact)->first()['value']);
    }

    public function test_a_foreign_definition_or_contact_fails_closed(): void
    {
        [, $a] = $this->crmTenant('Business A', 'Workspace A');
        [, $b] = $this->crmTenant('Business B', 'Workspace B');
        $contactA = $this->crmContact($a);
        $contactB = $this->crmContact($b);
        $fieldB = $this->manager()->create($b, 'Venue', 'text');

        foreach ([[$a, $contactA, $fieldB], [$b, $contactA, $fieldB], [$a, $contactB, $this->manager()->create($a, 'Venue', 'text')]] as [$business, $contact, $field]) {
            try {
                $this->values()->set($business, $contact, $field, 'Leak');
                $this->fail('A cross-Business write must be refused.');
            } catch (CustomFieldRuleException) {
                $this->assertSame(0, CustomFieldValue::query()->count());
            }
        }
    }

    public function test_the_contact_details_save_clears_blanks_validates_and_is_all_or_nothing(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business);
        $venue = $this->manager()->create($business, 'Venue', 'text');
        $guests = $this->manager()->create($business, 'Guest Count', 'number');
        $this->values()->set($business, $contact, $venue, 'Grand Hotel');

        try {
            $this->values()->saveForContact($business, $contact, [$venue->uid => '', $guests->uid => 'many']);
            $this->fail('An invalid number must reject the whole save.');
        } catch (CustomFieldRuleException) {
            $this->assertSame('Grand Hotel', $this->values()->valuesFor($business, $contact)->first()['value']);
        }

        $this->values()->saveForContact($business, $contact, [$venue->uid => '', $guests->uid => '180']);
        $entries = $this->values()->valuesFor($business, $contact);

        $this->assertCount(1, $entries);
        $this->assertSame('180', $entries->first()['value']);

        $this->expectException(CustomFieldRuleException::class);
        $this->values()->saveForContact($business, $contact, ['00000000-0000-0000-0000-000000000000' => 'x']);
    }
}
