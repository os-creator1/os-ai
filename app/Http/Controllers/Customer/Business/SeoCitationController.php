<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Seo\SeoCitationStatus;
use App\Exceptions\Seo\SeoCitationNotFoundException;
use App\Exceptions\Seo\SeoCitationRefusedException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Seo\SeoCitationManager;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Contract 18 Sub-slice 18E — Citations: the Business's own, user-asserted
 * directory listings per Location, beside a read-time NAP comparison.
 *
 * Thin by design: every rule that matters (Location ACL, the private-address
 * write refusal, link safety, the Archived-Location write rule, "never change
 * a status because NAP differs", the Google row via the GBP read model) lives
 * in SeoCitationManager. This controller only runs the mandatory chain and
 * translates the manager's outcomes.
 *
 * The chain (Contract 18 §10.1, mirroring GBP §15.1): Workspace by uid ->
 * Business inside it -> userCanAccessBusiness() -> Business Active -> the
 * entitlement decision for PlatformFeature::SeoModule -> the capability
 * gate. Every tenancy or entitlement failure is `abort(404)`; a Location or
 * directory that is unknown, foreign or inaccessible is the same 404. No
 * implicit route-model binding: every uid arrives as a string and is
 * resolved through the Business.
 *
 * FAIL-CLOSED WHILE `Planned`. SeoModule is registered Planned until
 * Sub-slice H flips it (RFC-004: a Planned feature is never
 * customer-executable), so EntitlementManager denies it for every tier and
 * every route here answers 404 today — unreachable by design, not omission.
 *
 * NAMING. `customer.workspaces.businesses.seo.citations.*`; nothing begins
 * with `customer.keywords.`.
 */
class SeoCitationController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(private readonly SeoCitationManager $citations)
    {
    }

    public function citations(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveCitationTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        return view('customer.business.seo.citations', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'sections' => $this->citations->page($workspace, $business, Auth::user()),
            'statuses' => SeoCitationStatus::cases(),
            'canManage' => Auth::user()->can('manage_seo'),
        ]);
    }

    public function saveCitation(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $directoryKey): RedirectResponse
    {
        [, $business] = $this->resolveCitationTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        // Shape only. Everything that is a rule (https-only, the private
        // address refusal, Archived Location) is re-decided by the manager.
        $input = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (SeoCitationStatus $status) => $status->value, SeoCitationStatus::cases()))],
            'listing_url' => ['nullable', 'string', 'max:2048'],
            'listed_name' => ['nullable', 'string', 'max:191'],
            'listed_phone' => ['nullable', 'string', 'max:50'],
            'listed_address' => ['nullable', 'string', 'max:255'],
            'last_verified_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->citations->save((int) Auth::id(), $business, $locationUid, $directoryKey, $input);
        } catch (SeoCitationNotFoundException) {
            abort(404);
        } catch (SeoCitationRefusedException $e) {
            // The submitted address is never flashed back: a refused
            // listed_address must not be echoed into the session or the page.
            return back()
                ->withInput($request->except('listed_address'))
                ->withErrors([$e->field => $e->getMessage()], 'citation_' . $locationUid . '_' . $directoryKey);
        }

        return back()->with(['status' => 'success', 'message' => 'Citation saved.']);
    }

    /**
     * The chain through entitlement. Its own method so the single place that
     * decides "is Citations reachable for this Business" is not duplicated.
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveCitationTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::SeoModule->value);
    }
}
