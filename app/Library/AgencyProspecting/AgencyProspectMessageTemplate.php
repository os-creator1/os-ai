<?php

namespace App\Library\AgencyProspecting;

use App\Library\AgencyOutreach\OutreachCompanyName;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectingSetting;

/**
 * Runtime pass — a bounded, safe placeholder substitution for the
 * explicit, user-authored opening message. Plain string replacement only
 * — never Blade, never PHP evaluation, never arbitrary template logic.
 */
final class AgencyProspectMessageTemplate
{
    public static function render(string $template, AgencyProspect $prospect, ?AgencyProspectingSetting $settings): string
    {
        // `{{company_name}}` and `{{contact.company}}` are the PROSPECT's company, with a safe
        // fallback, resolved exactly as the Outreach renderer does.
        $template = OutreachCompanyName::fill($template, OutreachCompanyName::for($prospect));

        return strtr($template, [
            '{{contact_name}}' => $prospect->contact_name ?? '',
            '{{agency_name}}' => $settings?->agency_name ?? '',
        ]);
    }
}
