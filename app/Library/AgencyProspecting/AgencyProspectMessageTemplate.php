<?php

namespace App\Library\AgencyProspecting;

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
        return strtr($template, [
            '{{company_name}}' => $prospect->company_name,
            '{{contact_name}}' => $prospect->contact_name ?? '',
            '{{agency_name}}' => $settings?->agency_name ?? '',
        ]);
    }
}
