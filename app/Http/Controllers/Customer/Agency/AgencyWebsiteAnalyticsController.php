<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Agency\Concerns\AuthorizesAgencyWorkspace;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\WebsiteAnalytics\AnalyticsProperties;
use App\Library\WebsiteAnalytics\AnalyticsReport;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Agency → White Label → Analytics: the funnel of the Agency's OWN published landing page (e.g. /photobooth):
 * visitors → calendar opens → confirmed bookings, with the SMS campaign filter.
 *
 * Read-only. The tenant comes from the signed-in Agency team member's workspace (the same authority as every White Label tab,
 * answered 404), and the measured property from that workspace's own site — never from anything in the query string.
 */
class AgencyWebsiteAnalyticsController extends Controller
{
    use AuthorizesAgencyWorkspace;

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly AgencyWhiteLabelManager $whiteLabel,
        private readonly AgencySiteManager $sites,
        private readonly AgencySiteBookingService $booking,
        private readonly AnalyticsProperties $properties,
        private readonly AnalyticsReport $reports,
    ) {}

    public function show(Request $request, string $workspaceUid): View
    {
        $agency = $this->authorizedAgency($workspaceUid);
        $site = $this->sites->find($agency);
        $property = $site === null ? null : $this->properties->findForAgencySite($site);
        $business = $this->booking->business($agency);
        $timezone = (string) ($business?->timezone ?: config('app.timezone', 'UTC'));

        $report = $property === null ? null : $this->reports->build(
            $property,
            AnalyticsReport::normalizeRange($request->query('range')),
            $timezone,
            is_string($request->query('landing')) ? $request->query('landing') : null,
            is_string($request->query('campaign')) ? $request->query('campaign') : null,
        );

        return view('customer.agency.white-label.analytics', [
            'agencyWorkspace' => $agency,
            'tab' => 'analytics',
            'entitled' => $this->whiteLabel->isEntitled($agency),
            'site' => $site,
            'report' => $report,
            'landingLabel' => $site === null ? null : $this->properties->agencyLanding($site),
            'landingUrl' => $site === null ? null : (\App\Library\AgencySite\OfficialDomainPaths::urlFor($agency) ?? route('agency.site.home', [$site->public_id])),
            'campaignOptions' => $property === null ? [] : $this->reports->campaignOptions($property),
            'filterAction' => route('customer.workspaces.agency.white-label.analytics', [$agency->uid]),
            'mode' => 'agency',
        ]);
    }
}
