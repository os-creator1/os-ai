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
    public static function render(string $template, AgencyProspect $prospect, ?AgencyProspectingSetting $settings, ?\App\Models\AgencyProspectCampaign $campaign = null): string
    {
        // `{{company_name}}` and `{{contact.company}}` are the PROSPECT's company, with a safe
        // fallback, resolved exactly as the Outreach renderer does.
        $template = OutreachCompanyName::fill($template, OutreachCompanyName::for($prospect));

        $text = strtr($template, [
            '{{contact_name}}' => $prospect->contact_name ?? '',
            '{{agency_name}}' => $settings?->agency_name ?? '',
        ]);

        // FINAL step: tag an eligible MotionGrove landing link with the campaign's opaque attribution value.
        try {
            $agency = \App\Models\Workspace::query()->find($prospect->workspace_id);
            $links = app(\App\Library\WebsiteAnalytics\AnalyticsCampaignLinks::class);

            return $agency === null ? $text : $links->tag($text, $agency, $campaign ?? $links->campaignForProspect($prospect, $agency));
        } catch (\Throwable) {
            return $text;
        }
    }
}
