<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Crm\CrmBoard;
use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsiteStarterDraftService;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The business side of the Forms foundation: create the Photo Booth
 * quote-request form for a Location, configure it (name, button, Location,
 * Opportunity behaviour), activate or deactivate it, and see who has
 * submitted it. Runs the exact same tenancy/entitlement chain
 * Business\WebsiteController does (PlatformFeature::WebsiteGeneration) —
 * Forms are a Website capability, not a separately entitled product.
 *
 * DEFINITIONS ARE BUSINESS-WIDE, SUBMISSIONS ARE LOCATION-BOUND (§16): the
 * definitions list shows every form of the Business; the inquiries list
 * shows only the submissions of Locations the viewer may reach
 * (LocationAccessGuard — the one Location ACL), and a form can only be
 * bound to a Location of THIS Business that the editor can reach.
 */
class WebsiteFormsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const SUBMISSIONS_PER_PAGE = 25;

    public function __construct(private readonly LocationAccessGuard $locationAccess)
    {
    }

    public function home(string $workspaceUid, string $businessUid): View
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        return view('customer.business.website.forms.index', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'isPhotoBooth' => WebsiteStarterDraftService::isPhotoBooth($business),
            'forms' => $website->forms()->with('location:id,uid,name')->withCount('submissions')->orderBy('id')->get(),
            'locations' => $this->activeLocations($business),
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        // The Photo Booth quote-request preset is the only one this slice
        // ships; a niche it was never written for must never be offered
        // it, whether through the view or a direct request to this action.
        if (! WebsiteStarterDraftService::isPhotoBooth($business)) {
            throw ValidationException::withMessages([
                'form' => ['This form preset is only available for Photo Booth businesses.'],
            ]);
        }

        $location = $this->locationForWrite($business, $request->input('location_uid'));

        $existing = $website->forms()->where('type', WebsiteForm::TYPE_QUOTE_REQUEST)->where('location_id', $location->id)->exists();
        if ($existing) {
            return redirect()->route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]);
        }

        $website->forms()->create([
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Request a quote',
            'location_id' => $location->id,
        ]);

        return redirect()->route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Quote request form created. Add a Form section to a page to publish it.',
        ]);
    }

    public function edit(string $workspaceUid, string $businessUid, string $formUid): View
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);

        return view('customer.business.website.forms.edit', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'form' => $this->resolveForm($website, $formUid),
            'locations' => $this->activeLocations($business),
            'pipelines' => app(CrmBoard::class)->pipelines($business),
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $formUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $form = $this->resolveForm($website, $formUid);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'submit_label' => ['required', 'string', 'max:40'],
            'location_uid' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'create_opportunity' => ['nullable', 'boolean'],
            'crm_pipeline_uid' => ['nullable', 'string'],
        ]);

        // The Location is re-derived inside THIS Business and re-checked
        // against the editor's own reach — an id or uid of another
        // Business's Location simply does not resolve.
        $location = $this->locationForWrite($business, $validated['location_uid']);

        $duplicate = $website->forms()
            ->where('type', $form->type)
            ->where('location_id', $location->id)
            ->whereKeyNot($form->id)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['location_uid' => ['This Location already has a form of this type.']]);
        }

        $pipelineId = null;
        if (! empty($validated['crm_pipeline_uid'])) {
            $pipelineId = app(CrmBoard::class)->pipelines($business)->firstWhere('uid', $validated['crm_pipeline_uid'])?->id;

            if ($pipelineId === null) {
                throw ValidationException::withMessages(['crm_pipeline_uid' => ['That pipeline is not available.']]);
            }
        }

        $form->update([
            'name' => $validated['name'],
            'submit_label' => $validated['submit_label'],
            'location_id' => $location->id,
            'is_active' => $request->boolean('is_active'),
            'create_opportunity' => $request->boolean('create_opportunity'),
            'crm_pipeline_id' => $pipelineId,
        ]);

        return redirect()->route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => 'Form saved. Name and button label changes reach visitors the next time you publish your website.',
        ]);
    }

    public function submissions(Request $request, string $workspaceUid, string $businessUid, string $formUid): View
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusiness($workspaceUid, $businessUid);
        $website = $this->resolveWebsite($business);
        $form = $this->resolveForm($website, $formUid);

        $reachable = $this->locationAccess->accessibleLocationIdsForBusiness((int) auth()->id(), $business);
        $everyLocationId = BusinessLocation::query()->where('business_id', $business->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $reachesAll = count(array_diff($everyLocationId, $reachable)) === 0;

        $query = $form->submissions()
            ->with(['crmOpportunity:id,uid', 'location:id,uid,name'])
            ->where(function ($q) use ($reachable, $reachesAll) {
                $q->whereIn('location_id', $reachable);

                // Rows that predate Location attribution belong to no
                // Location; only a viewer who reaches every Location sees them.
                if ($reachesAll) {
                    $q->orWhereNull('location_id');
                }
            });

        $filter = (string) $request->query('location', '');
        if ($filter !== '') {
            $filterId = BusinessLocation::query()->where('business_id', $business->id)->where('uid', $filter)->value('id');
            $query->where('location_id', $filterId ?? 0);
        }

        return view('customer.business.website.forms.submissions', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'form' => $form,
            'submissions' => $query->latest('id')->paginate(self::SUBMISSIONS_PER_PAGE)->withQueryString(),
            'locations' => BusinessLocation::query()->where('business_id', $business->id)->whereIn('id', $reachable)->orderBy('id')->get(['id', 'uid', 'name']),
            'locationFilter' => $filter,
        ]);
    }

    /**
     * @return array{0: Workspace, 1: Business}
     */
    private function resolveEntitledBusiness(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
    }

    private function resolveWebsite(Business $business): Website
    {
        $website = Website::where('business_id', $business->id)->first();

        abort_unless($website !== null, 404);

        return $website;
    }

    private function resolveForm(Website $website, string $formUid): WebsiteForm
    {
        $form = $website->forms()->where('uid', $formUid)->first();

        abort_unless($form !== null, 404);

        return $form;
    }

    /**
     * @return \Illuminate\Support\Collection<int, BusinessLocation>
     */
    private function activeLocations(Business $business)
    {
        return BusinessLocation::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->orderBy('id')
            ->get(['id', 'uid', 'name']);
    }

    /**
     * The Location a form is being bound to: an ACTIVE Location of this
     * Business that the editor can reach. A Business with exactly one
     * active Location needs none named (it is the only possible answer); a
     * Business with several must name one — never defaulted to "the first".
     */
    private function locationForWrite(Business $business, mixed $locationUid): BusinessLocation
    {
        $active = $this->activeLocations($business);
        $uid = is_string($locationUid) ? trim($locationUid) : '';

        $location = $uid === ''
            ? ($active->count() === 1 ? $active->first() : null)
            : $active->firstWhere('uid', $uid);

        if ($location === null || ! $this->locationAccess->userCanAccessLocation((int) auth()->id(), $location)) {
            throw ValidationException::withMessages(['location_uid' => ['Choose one of your active locations.']]);
        }

        return $location;
    }
}
