<?php

namespace App\Library\AgencyOutreach;

/**
 * Rewrites the vocabulary an Agency owner naturally types into the canonical
 * merge tokens (contract §3). It runs when a script is SAVED, so stored text and
 * rendering are purely canonical (`{{group.key}}`) and the one merge engine never
 * has to know product aliases exist.
 *
 * Tolerant of inner spaces and case (`{{ Agency_Name }}`), and deliberately
 * limited to the three aliases the contract names: any other token is left for
 * the engine, which renders an unknown one blank and the editor flags.
 */
final class OutreachScriptTokens
{
    private const ALIASES = [
        'agency_name' => '{{agency.name}}',
        'website' => '{{agency.website}}',
        'calendar_link' => '{{agency.calendar_link}}',
    ];

    public static function canonicalise(string $text): string
    {
        return preg_replace_callback(
            '/\{\{\s*(agency_name|website|calendar_link)\s*\}\}/i',
            static fn (array $m): string => self::ALIASES[strtolower($m[1])],
            $text,
        ) ?? $text;
    }
}
