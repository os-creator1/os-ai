<?php

namespace App\Library\Website\Setup;

/**
 * One service area per list entry — never a delimited string. Normalizes an
 * owner-entered list: trims and collapses whitespace, drops blanks and
 * case-insensitive duplicates, bounds length and count, and PRESERVES the
 * entered order (the order is the owner's priority).
 */
final class ServiceAreaList
{
    private function __construct()
    {
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<int, string>
     */
    public static function normalize(array $entries): array
    {
        $seen = [];
        $result = [];

        foreach ($entries as $entry) {
            if (! is_scalar($entry)) {
                continue;
            }

            $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $entry));
            if ($clean === '') {
                continue;
            }

            $clean = mb_substr($clean, 0, QuestionnaireAnswerValidator::MAX_LIST_ITEM_LENGTH);
            $fingerprint = mb_strtolower($clean);

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $result[] = $clean;

            if (count($result) >= QuestionnaireAnswerValidator::MAX_LIST_ITEMS) {
                break;
            }
        }

        return $result;
    }
}
