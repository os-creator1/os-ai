<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Models\Contacts;

/**
 * B4's `{TAG}` substitution, shared by every automation step that writes words to
 * a contact (Send SMS, Send email).
 *
 * Plain replacement from the contact's OWN group fields — never template
 * evaluation, never arbitrary code, and a tag the group does not define is left
 * as written rather than guessed at. Lifted out of SendSmsNodeExecutor unchanged
 * so a second message step does not carry a second copy.
 */
final class ContactMergeFields
{
    public static function render(string $text, Contacts $contact): string
    {
        $group = $contact->contactGroup;

        if ($group === null) {
            return $text;
        }

        $replacements = [];

        foreach ($group->getFields()->get() as $field) {
            $replacements['{' . $field->tag . '}'] = (string) $contact->getValueByField($field);
        }

        return strtr($text, $replacements);
    }
}
