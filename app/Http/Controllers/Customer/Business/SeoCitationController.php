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
 * ENTITLEMENT. SeoModule is Available (Sub-slice H flipped it), so the
 * entitlement decision is made per Business: a Business whose plan does not
 * include SeoModule gets the same 404 on every route here, and one that does
 * reaches them. The decision is still made by EntitlementManager, never here.
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

    public function citations(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveCitationTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        $sections = $this->citations->page($workspace, $business, Auth::user());

        // One Location at a time: NAP from different Locations is never
        // combined on a page. `?location=<uid>` picks among the sections the
        // manager ALREADY filtered to the actor's accessible Locations; a uid
        // that is not one of them (foreign, inaccessible, malformed) is the
        // same 404 as everywhere else. No Location picked -> the first.
        $selected = $sections[0] ?? null;
        $requested = $request->query('location');

        if ($requested !== null) {
            $selected = collect($sections)->first(
                fn ($section) => is_string($requested) && (string) $section->location->uid === $requested,
            );

            abort_if($selected === null, 404);
        }

        return view('customer.business.seo.citations', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'sections' => $sections,
            'section' => $selected,
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
            'listed_website' => ['nullable', 'string', 'max:2048'],
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

    public function setApplicability(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $directoryKey): RedirectResponse
    {
        [, $business] = $this->resolveCitationTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $request->validate(['applicable' => ['required', 'boolean']]);

        try {
            $this->citations->setApplicability((int) Auth::id(), $business, $locationUid, $directoryKey, (bool) $input['applicable']);
        } catch (SeoCitationNotFoundException) {
            abort(404);
        } catch (SeoCitationRefusedException $e) {
            return back()->withErrors([$e->field => $e->getMessage()], 'citation_' . $locationUid . '_' . $directoryKey);
        }

        return back()->with(['status' => 'success', 'message' => $input['applicable'] ? 'Directory restored.' : 'Marked as not applicable.']);
    }

    public function storeCustom(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveCitationTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $request->validate(array_merge($this->citationRules(), [
            'name' => ['required', 'string', 'max:120'],
            'claim_url' => ['nullable', 'string', 'max:2048'],
            'location_scope' => ['nullable', Rule::in(['all', 'this'])],
        ]));

        try {
            $this->citations->createCustomDirectory((int) Auth::id(), $business, $locationUid, $input);
        } catch (SeoCitationNotFoundException) {
            abort(404);
        } catch (SeoCitationRefusedException $e) {
            return back()
                ->withInput($request->except('listed_address'))
                ->withErrors([$e->field => $e->getMessage()], 'citation_' . $locationUid . '_new_custom');
        }

        return back()->with(['status' => 'success', 'message' => 'Custom directory added.']);
    }

    public function updateCustom(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $directoryKey): RedirectResponse
    {
        [, $business] = $this->resolveCitationTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'claim_url' => ['nullable', 'string', 'max:2048'],
        ]);

        try {
            $this->citations->updateCustomDirectory((int) Auth::id(), $business, $locationUid, $directoryKey, $input);
        } catch (SeoCitationNotFoundException) {
            abort(404);
        } catch (SeoCitationRefusedException $e) {
            return back()->withErrors([$e->field => $e->getMessage()], 'citation_' . $locationUid . '_' . $directoryKey);
        }

        return back()->with(['status' => 'success', 'message' => 'Custom directory updated.']);
    }

    public function archiveCustom(string $workspaceUid, string $businessUid, string $locationUid, string $directoryKey): RedirectResponse
    {
        [, $business] = $this->resolveCitationTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        try {
            $this->citations->archiveCustomDirectory((int) Auth::id(), $business, $locationUid, $directoryKey);
        } catch (SeoCitationNotFoundException) {
            abort(404);
        } catch (SeoCitationRefusedException $e) {
            return back()->withErrors([$e->field => $e->getMessage()], 'citation_' . $locationUid . '_' . $directoryKey);
        }

        return back()->with(['status' => 'success', 'message' => 'Custom directory archived. Its history is kept.']);
    }

    /**
     * Shape rules shared by the citation form and the custom-directory form.
     * Everything that is a rule (https-only, the private-address refusal,
     * Archived Location) is re-decided by the manager.
     *
     * @return array<string, array<int, mixed>>
     */
    private function citationRules(): array
    {
        return [
            'status' => ['required', Rule::in(array_map(fn (SeoCitationStatus $status) => $status->value, SeoCitationStatus::cases()))],
            'listing_url' => ['nullable', 'string', 'max:2048'],
            'listed_name' => ['nullable', 'string', 'max:191'],
            'listed_phone' => ['nullable', 'string', 'max:50'],
            'listed_address' => ['nullable', 'string', 'max:255'],
            'listed_website' => ['nullable', 'string', 'max:2048'],
            'last_verified_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
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
