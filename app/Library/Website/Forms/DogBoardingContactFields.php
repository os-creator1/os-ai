<?php

namespace App\Library\Website\Forms;

use App\Enums\CustomFields\CustomFieldType;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;

/**
 * Copies the public Request-a-Stay answers onto the contact's custom fields (the ones the Dog Boarding Blueprint installed),
 * through the existing CustomFieldValueService, which proves every definition and contact belong to this Business, skips
 * blank or invalid answers and never throws past the submission.
 *
 * Privacy and safety:
 *  - only the fixed, non-sensitive answers below are copied. The free-text notes are never mapped; they stay in the
 *    submission, which is private to the Business;
 *  - a contact that already existed (matched by phone) only has BLANK fields filled. A repeat visitor, perhaps with a second
 *    dog, never overwrites what the owner already knows;
 *  - a field the Business removed or archived is simply skipped.
 */
final class DogBoardingContactFields
{
    /** website form field key => installed custom field label */
    public const MAP = [
        'dog_name' => 'Dog name',
        'dog_breed_size' => 'Dog breed',
        'dog_count' => 'Number of dogs',
        'arrival_date' => 'Requested arrival date',
        'departure_date' => 'Requested departure date',
        'services_requested' => 'Requested services',
        'preferred_contact' => 'Preferred contact method',
    ];

    public function __construct(
        private readonly CustomFieldDefinitionManager $definitions,
        private readonly CustomFieldValueService $values,
    ) {
    }

    /** @param array<string, mixed> $data the validated submission answers by field key */
    public function apply(Business $business, Contacts $contact, array $data, bool $contactExisted): void
    {
        $definitions = $this->definitions->forBusiness($business)->keyBy(fn (CustomFieldDefinition $d) => mb_strtolower((string) $d->label));

        foreach (self::MAP as $key => $label) {
            $answer = $data[$key] ?? null;
            $definition = $definitions->get(mb_strtolower($label));

            if ($definition === null || $answer === null || trim((string) $answer) === '') {
                continue;
            }

            if ($contactExisted && CustomFieldValue::query()->where('definition_id', $definition->id)->where('contact_id', $contact->id)->exists()) {
                continue;
            }

            $this->values->applyAnswer($business, $contact, $definition, $this->shaped($definition, (string) $answer));
        }
    }

    /** Free text is matched (case-insensitively) to a choice field's options; anything else is passed on to be validated. */
    private function shaped(CustomFieldDefinition $definition, string $answer): mixed
    {
        $type = $definition->fieldType();

        if (! in_array($type, [CustomFieldType::Select, CustomFieldType::MultiSelect], true)) {
            return trim($answer);
        }

        // Options are {id, label}; the codec accepts a label, so each token is matched to a label ignoring case.
        $options = [];
        foreach ($definition->optionList() as $option) {
            $label = is_array($option) ? (string) ($option['label'] ?? '') : (string) $option;
            $options[mb_strtolower(trim($label))] = $label;
        }
        $picked = [];

        foreach (preg_split('/\s*[,;]\s*/', $answer) ?: [] as $token) {
            $match = $options[mb_strtolower(trim($token))] ?? null;

            if ($match !== null) {
                $picked[] = $match;
            }
        }

        if ($picked === []) {
            return '';
        }

        return $type === CustomFieldType::Select ? $picked[0] : array_values(array_unique($picked));
    }
}
