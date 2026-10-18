<?php

namespace App\Library\CustomFields;

use App\Models\Business;
use App\Models\Contacts;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use Illuminate\Support\Collection;

/**
 * The one canonical writer (and reader) of Contact Custom Field values.
 *
 * Three write verbs, each with a defined meaning:
 *
 *   set()          set or REPLACE the value; a blank value is refused (use clear())
 *   clear()        remove the value
 *   applyAnswer()  a Form / Questionnaire answer: a blank or unusable answer is a
 *                  NO-OP — an empty answer must never destroy a value that was
 *                  already there. Clearing from a Form is not a supported intent.
 *
 * TENANCY. Every verb re-proves that the Contact and the definition belong to the
 * SAME Business, and that the definition is a Contact-scoped one. A definition of
 * another Business is "not found", never an error that confirms it exists.
 *
 * The caller is responsible for authorizing the Contact itself (Location ACL);
 * this service never loads a Contact by id on its own.
 *
 * ARCHIVE. An archived definition keeps its values and still resolves for reads,
 * but refuses NEW writes (set / applyAnswer).
 */
class CustomFieldValueService
{
    public const APPLIED = 'applied';

    public const SKIPPED_BLANK = 'skipped_blank';

    public const SKIPPED_INVALID = 'skipped_invalid';

    public function set(Business $business, Contacts $contact, CustomFieldDefinition $definition, mixed $raw): CustomFieldValue
    {
        $this->assertScope($business, $contact, $definition);

        if ($definition->isArchived()) {
            throw new CustomFieldRuleException(sprintf('%s is archived and can no longer be edited.', $definition->label));
        }

        $canonical = CustomFieldValueCodec::normalize($definition, $raw);

        return CustomFieldValue::query()->updateOrCreate(
            ['definition_id' => (int) $definition->id, 'contact_id' => (int) $contact->id],
            ['business_id' => (int) $business->id]
                + CustomFieldValueCodec::toColumns($definition->fieldType(), $canonical),
        );
    }

    public function clear(Business $business, Contacts $contact, CustomFieldDefinition $definition): void
    {
        $this->assertScope($business, $contact, $definition);

        CustomFieldValue::query()
            ->where('definition_id', (int) $definition->id)
            ->where('contact_id', (int) $contact->id)
            ->delete();
    }

    /**
     * @return self::APPLIED|self::SKIPPED_BLANK|self::SKIPPED_INVALID
     */
    public function applyAnswer(Business $business, Contacts $contact, CustomFieldDefinition $definition, mixed $answer): string
    {
        $this->assertScope($business, $contact, $definition);

        if ($definition->isArchived()) {
            // Archived fields cannot be newly written, including from a Form that
            // was mapped before the archive. The answer stays in the submission.
            return self::SKIPPED_INVALID;
        }

        if (CustomFieldValueCodec::isBlank($answer)) {
            return self::SKIPPED_BLANK;
        }

        try {
            $this->set($business, $contact, $definition, $answer);
        } catch (CustomFieldRuleException) {
            return self::SKIPPED_INVALID;
        }

        return self::APPLIED;
    }

    /**
     * Save the Contact-details form: for every ACTIVE definition named in
     * `$input` (keyed by definition uid), a blank value clears and anything else
     * is validated and set. Unknown / foreign uids are refused outright.
     *
     * @param array<string, mixed> $input definition uid => raw value
     *
     * @throws CustomFieldRuleException with the first problem found; nothing is written then
     */
    public function saveForContact(Business $business, Contacts $contact, array $input): void
    {
        $definitions = CustomFieldDefinition::query()
            ->where('business_id', (int) $business->id)
            ->where('entity', CustomFieldDefinition::ENTITY_CONTACT)
            ->whereNull('archived_at')
            ->get()
            ->keyBy('uid');

        $plan = [];

        foreach ($input as $uid => $raw) {
            $definition = $definitions->get((string) $uid) ?? throw new CustomFieldRuleException('That field could not be found.');

            $plan[] = CustomFieldValueCodec::isBlank($raw)
                ? [$definition, null, true]
                : [$definition, CustomFieldValueCodec::normalize($definition, $raw), false];
        }

        foreach ($plan as [$definition, $canonical, $clear]) {
            $clear ? $this->clear($business, $contact, $definition) : $this->set($business, $contact, $definition, $canonical);
        }
    }

    /**
     * The Contact's stored values with their definitions, in field order.
     *
     * @return Collection<int, array{definition: CustomFieldDefinition, value: mixed}> only fields that HAVE a value
     */
    public function valuesFor(Business $business, Contacts $contact, bool $includeArchived = true): Collection
    {
        if ((int) $contact->business_id !== (int) $business->id) {
            return collect();
        }

        $rows = CustomFieldValue::query()
            ->where('contact_id', (int) $contact->id)
            ->where('business_id', (int) $business->id)
            ->get()
            ->keyBy('definition_id');

        if ($rows->isEmpty()) {
            return collect();
        }

        return CustomFieldDefinition::query()
            ->where('business_id', (int) $business->id)
            ->whereIn('id', $rows->keys()->all())
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomFieldDefinition $definition): array => [
                'definition' => $definition,
                'value' => CustomFieldValueCodec::fromRow($definition->fieldType(), $rows[$definition->id]),
            ])
            ->filter(fn (array $entry): bool => $entry['value'] !== null)
            ->values();
    }

    /**
     * What the Contact-details "Custom fields" section renders: every ACTIVE
     * field (editable, with a control-ready `input` value) and, read-only, any
     * ARCHIVED field that still holds a value for this Contact.
     *
     * @return array{editable: list<array{definition: CustomFieldDefinition, input: mixed}>, archived: list<array{definition: CustomFieldDefinition, display: string}>}
     */
    public function sectionFor(Business $business, Contacts $contact): array
    {
        $values = $this->valuesFor($business, $contact)->keyBy(fn (array $entry): int => (int) $entry['definition']->id);
        $section = ['editable' => [], 'archived' => []];

        $definitions = CustomFieldDefinition::query()
            ->where('business_id', (int) $business->id)
            ->where('entity', CustomFieldDefinition::ENTITY_CONTACT)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($definitions as $definition) {
            $value = $values->get($definition->id)['value'] ?? null;

            if ($definition->isArchived()) {
                if ($value !== null) {
                    $section['archived'][] = ['definition' => $definition, 'display' => CustomFieldValueCodec::display($definition, $value, $business)];
                }

                continue;
            }

            $section['editable'][] = ['definition' => $definition, 'input' => CustomFieldValueCodec::toInput($definition, $value)];
        }

        return $section;
    }

    private function assertScope(Business $business, Contacts $contact, CustomFieldDefinition $definition): void
    {
        if ((int) $definition->business_id !== (int) $business->id
            || $definition->entity !== CustomFieldDefinition::ENTITY_CONTACT
            || (int) $contact->business_id !== (int) $business->id) {
            throw new CustomFieldRuleException('That field could not be found.');
        }
    }
}
