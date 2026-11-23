<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspect;
use App\Models\AgencyProspectGroupLink;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place that decides what a prospect's business/company name is for message
 * personalisation, shared by the Outreach script renderer (editor preview and every
 * outbound message, opener and follow-ups) and the legacy channel-campaign template.
 *
 * `{{company_name}}`, `{{contact.company}}` and `{{prospect.company}}` are aliases of the
 * same fact: the PROSPECT's company. It is never the sender's own Business name.
 *
 * Resolution order:
 *   1. the prospect's own `company_name`, unless it is only an identity stand-in. Imports and
 *      the Contact Group bridge must store *something* in that required column, so they fall
 *      back to the phone number or the person's name when no company was supplied;
 *   2. the COMPANY field of the CRM Contact the prospect was materialised from (reached through
 *      the Contact Group link), only for Contacts of this Workspace's own Business;
 *   3. nothing: the caller substitutes a safe fallback via {@see fill()}.
 */
final class OutreachCompanyName
{
    /** Every spelling of the company token, so legacy and Outreach text resolve identically. */
    public const TOKEN = '/\{\{\s*(?:company_name|contact\.company|prospect\.company)\s*\}\}/i';

    private const GREETING = '/\b(hi|hello|hey|hiya|dear|good\s+(?:morning|afternoon|evening))(\s+)\{\{\s*(?:company_name|contact\.company|prospect\.company)\s*\}\}(?:\s+(?:team|crew|folks))?/i';

    private const POSSESSIVE = '/\{\{\s*(?:company_name|contact\.company|prospect\.company)\s*\}\}[\'’]s\b/iu';

    public const FALLBACK = 'your business';

    public static function for(AgencyProspect $prospect): ?string
    {
        $own = self::clean((string) $prospect->company_name);

        if ($own !== null && ! self::isStandIn($own, $prospect)) {
            return $own;
        }

        if (! $prospect->exists) {
            return null;
        }

        return self::fromCrmContact($prospect);
    }

    /**
     * Substitutes every company token in `$text`. With no name a safe fallback is written instead
     * of a gap or a raw placeholder: "Hi {{company_name}}," becomes "Hi there,", "{{company_name}}'s"
     * becomes "your", and any other position reads "your business".
     */
    public static function fill(string $text, ?string $name): string
    {
        if (preg_match(self::TOKEN, $text) !== 1) {
            return $text;
        }

        $name = $name === null ? null : self::clean($name);

        if ($name !== null) {
            return preg_replace_callback(self::TOKEN, static fn (): string => $name, $text) ?? $text;
        }

        $text = preg_replace(self::GREETING, '$1$2there', $text) ?? $text;
        $text = preg_replace(self::POSSESSIVE, 'your', $text) ?? $text;

        return preg_replace(self::TOKEN, self::FALLBACK, $text) ?? $text;
    }

    /** Trimmed, single-spaced, and never able to inject a merge token. */
    private static function clean(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', str_replace(['{', '}'], '', $value)) ?? '');

        return $value === '' ? null : $value;
    }

    /** The stored name is just the phone number, or just the person's own name, not a company. */
    private static function isStandIn(string $name, AgencyProspect $prospect): bool
    {
        if (preg_match('/^[+\d\s().\-]+$/', $name) === 1) {
            return true;
        }

        $person = self::clean((string) $prospect->contact_name);

        return $person !== null && mb_strtolower($person) === mb_strtolower($name);
    }

    private static function fromCrmContact(AgencyProspect $prospect): ?string
    {
        $businessIds = Business::query()->where('workspace_id', (int) $prospect->workspace_id)->pluck('id')->all();

        if ($businessIds === []) {
            return null;
        }

        $contactIds = AgencyProspectGroupLink::query()
            ->where('workspace_id', (int) $prospect->workspace_id)
            ->where('prospect_id', (int) $prospect->id)
            ->whereNotNull('contact_id')
            ->orderBy('id')
            ->pluck('contact_id')
            ->all();

        if ($contactIds === []) {
            return null;
        }

        $values = DB::table('contacts_custom_field as v')
            ->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->join('contacts as c', 'c.id', '=', 'v.contact_id')
            ->whereIn('v.contact_id', $contactIds)
            ->whereIn('c.business_id', $businessIds)
            ->where('f.tag', 'COMPANY')
            ->orderBy('v.contact_id')
            ->orderBy('v.id')
            ->pluck('v.value');

        foreach ($values as $value) {
            $clean = self::clean((string) $value);

            if ($clean !== null && ! self::isStandIn($clean, $prospect)) {
                return $clean;
            }
        }

        return null;
    }
}
