<?php

namespace App\Library\Website\Forms;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteDomainStatus;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\Website;
use App\Models\WebsiteDomain;

/**
 * Which web origins may frame ONE public form.
 *
 * A Website-source FormDeployment is, by definition, "this form, offered to Website pages" (Forms\Embed\FormWebsiteEmbed). The
 * only origins that may therefore frame its public link are the Active custom domains of the published Website(s)
 * of the SAME Business that owns the Form. Any other deployment (a direct link, a disabled or unknown uid, another
 * Business's site) is framed by nobody but MotionGrove itself.
 *
 * The result feeds a `Content-Security-Policy: frame-ancestors` list. It is built only from persisted, hostname-
 * validated domains: never from a request header, query string or page content, and never a wildcard.
 */
final class WebsiteFormFrameAncestors
{
    /** @return array<int, string> exact origins (https://host), de-duplicated and sorted; empty when nobody else may frame it */
    public function originsFor(string $deploymentUid): array
    {
        $deployment = FormDeployment::query()
            ->where('uid', $deploymentUid)
            ->where('source', FormDeploymentSource::Website->value)
            ->where('is_enabled', true)
            ->first();

        $businessId = $deployment === null ? null : Form::query()->whereKey($deployment->form_id)->value('business_id');

        if ($businessId === null) {
            return [];
        }

        $websiteIds = Website::query()->where('business_id', $businessId)->whereNotNull('published_revision_id')->pluck('id');

        $origins = WebsiteDomain::query()
            ->whereIn('website_id', $websiteIds)
            ->where('status', WebsiteDomainStatus::Active->value)
            ->pluck('domain')
            ->map(fn ($domain) => strtolower(trim((string) $domain)))
            ->filter(fn (string $domain) => $domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false && str_contains($domain, '.'))
            ->map(fn (string $domain) => 'https://'.$domain)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $origins;
    }

    public function policyFor(string $deploymentUid): string
    {
        return implode(' ', array_merge(["frame-ancestors 'self'"], $this->originsFor($deploymentUid)));
    }
}
