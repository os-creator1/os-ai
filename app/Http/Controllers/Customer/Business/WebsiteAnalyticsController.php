<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Website\Design\WebsiteCtaResolver;
use App\Library\Website\Forms\WebsiteLeadFormResolver;
use App\Library\WebsiteAnalytics\AnalyticsProperties;
use App\Library\WebsiteAnalytics\AnalyticsReport;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Growth → Website → Analytics: the funnel of THIS Business's own published website — visitors, visits, page views and the
 * site's real conversion goal (Check availability → confirmed form inquiry, and/or the booking calendar when there is one).
 *
 * Same tenancy/entitlement chain as every other Website screen. The measured property comes from the resolved Business's
 * own Website, never from the request, so another tenant's key in the query string can show nothing.
 */
class WebsiteAnalyticsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly AnalyticsProperties $properties,
        private readonly AnalyticsReport $reports,
        private readonly WebsiteLeadFormResolver $leadForm,
        private readonly WebsiteCtaResolver $cta,
    ) {}

    public function home(Request $request, string $workspaceUid, string $businessUid): View
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
        $website = Website::where('business_id', $business->id)->first();
        abort_unless($website !== null, 404);

        $property = $this->properties->findForWebsite($website);
        $lead = $this->leadForm->inspect($business);
        $ctaKind = $this->cta->resolve($business, [])['kind'] ?? null;

        $report = $property === null ? null : $this->reports->build(
            $property,
            AnalyticsReport::normalizeRange($request->query('range')),
            (string) ($business->timezone ?: config('app.timezone', 'UTC')),
        );

        return view('customer.business.website.analytics', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'report' => $report,
            // The site's real conversion goal: a lead form that is live, and/or a booking calendar. Both can exist.
            'hasForm' => ($lead['state'] ?? null) === WebsiteLeadFormResolver::STATE_READY,
            'hasBooking' => $ctaKind === 'booking',
            'filterAction' => route('customer.workspaces.businesses.website.analytics.index', [$workspaceUid, $businessUid]),
            'mode' => 'growth',
        ]);
    }
}
