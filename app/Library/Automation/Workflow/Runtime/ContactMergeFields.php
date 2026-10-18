<?php

namespace App\Library\Automation\Workflow\Runtime;

use App\Library\Merge\MergeContext;
use App\Library\Merge\MergeFieldResolver;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\Contacts;

/**
 * COMPATIBILITY ADAPTER over the canonical merge engine
 * (App\Library\Merge\MergeFieldResolver). It owns no substitution logic of its
 * own beyond the two LEGACY syntaxes that already-saved configurations contain:
 *
 *   {TAG}          B4's uppercase group-field tag (`{FIRST_NAME}`), resolved from
 *                  the Contact's own group fields exactly as before. An unknown
 *                  tag is left as written (legacy behaviour).
 *   {first_name}   the lowercase chips the V2 builder used to insert
 *   {last_name}    (`{first_name}`, `{last_name}`, `{company}`, `{business_name}`).
 *   {company}      They never resolved at runtime; they now map onto the
 *   {business_name} canonical `{{contact.*}}` / `{{business.name}}` tokens so
 *                  existing workflows heal instead of sending raw braces.
 *
 * Everything else — `{{group.key}}` — goes through the canonical resolver. New
 * development writes ONLY the canonical syntax.
 */
final class ContactMergeFields
{
    private const LEGACY_ALIASES = [
        'first_name' => '{{contact.first_name}}',
        'last_name' => '{{contact.last_name}}',
        'company' => '{{contact.company}}',
        'business_name' => '{{business.name}}',
    ];

    /**
     * Render for a Contact on its own (no trigger facts): the Business and the
     * Contact's own Location are the whole context.
     */
    public static function render(string $text, Contacts $contact, ?MergeContext $context = null): string
    {
        $text = self::legacyGroupTags($text, $contact);
        $text = self::legacyAliases($text);

        $context ??= MergeContext::forContact($contact);

        if ($context === null || ! str_contains($text, '{{')) {
            // No resolvable Business: canonical tokens cannot render, and a raw
            // `{{...}}` must still never reach a customer.
            return $context === null ? self::stripCanonical($text) : $text;
        }

        return app(MergeFieldResolver::class)->render($text, $context);
    }

    /** Render inside an automation run, with the enrollment's trigger facts. */
    public static function renderForEnrollment(string $text, Contacts $contact, AutomationEnrollment $enrollment, Business $business): string
    {
        return self::render($text, $contact, app(AutomationMergeContextFactory::class)->for($enrollment, $business, $contact));
    }

    private static function legacyGroupTags(string $text, Contacts $contact): string
    {
        $group = $contact->contactGroup;

        if ($group === null || ! str_contains($text, '{')) {
            return $text;
        }

        $replacements = [];

        foreach ($group->getFields()->get() as $field) {
            $replacements['{' . $field->tag . '}'] = (string) $contact->getValueByField($field);
        }

        return strtr($text, $replacements);
    }

    private static function legacyAliases(string $text): string
    {
        foreach (self::LEGACY_ALIASES as $alias => $canonical) {
            // Single braces only: `{{first_name}}` must not be half-eaten.
            $text = preg_replace('/(?<!\{)\{' . $alias . '\}(?!\})/', $canonical, $text) ?? $text;
        }

        return $text;
    }

    private static function stripCanonical(string $text): string
    {
        return preg_replace('/\{\{[^{}]{1,80}\}\}/', '', $text) ?? $text;
    }
}
