<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Website\Forms\WebsiteLeadFormResolver;
use App\Library\Website\Tracking\WebsiteTrackingSettings;
use App\Models\Website;
use App\Models\WebsiteTrackingSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Website → Tracking. The same tenancy/entitlement chain as every other Website screen (PlatformFeature::WebsiteGeneration);
 * every write goes through WebsiteTrackingSettings, the single validating writer. Tracking never loads on this screen or any
 * other dashboard surface.
 */
class WebsiteTrackingController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly WebsiteTrackingSettings $tracking,
        private readonly WebsiteLeadFormResolver $leadForm,
    ) {}

    public function home(string $workspaceUid, string $businessUid): View
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
        $website = $this->resolveWebsite($business);

        $all = $this->tracking->all($website);

        return view('customer.business.website.tracking', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'website' => $website,
            'global' => $all->get(WebsiteTrackingSetting::GLOBAL_SCOPE),
            'pageSettings' => $all->except(WebsiteTrackingSetting::GLOBAL_SCOPE),
            'scopes' => $this->pageScopes($website),
            'leadForm' => $this->leadForm->inspect($business),
        ]);
    }

    public function save(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
        $website = $this->resolveWebsite($business);

        $scope = $this->scopeOrAbort($website, (string) $request->input('scope', WebsiteTrackingSetting::GLOBAL_SCOPE));

        $saved = $this->tracking->save($website, $scope, [
            'is_enabled' => $request->boolean('is_enabled'),
            'require_consent' => $request->boolean('require_consent'),
            'google_tag_id' => $request->input('google_tag_id'),
            'google_ads_id' => $request->input('google_ads_id'),
            'google_ads_lead_label' => $request->input('google_ads_lead_label'),
            'meta_pixel_id' => $request->input('meta_pixel_id'),
            'head_snippet' => $request->input('head_snippet'),
        ]);

        return redirect()->route('customer.workspaces.businesses.website.tracking.index', [$workspaceUid, $businessUid])->with([
            'status' => 'success',
            'message' => $saved === null ? 'Tracking removed.' : 'Tracking saved. It applies to your published pages (never to previews).',
        ]);
    }

    public function destroy(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $this->authorize('website');
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::WebsiteGeneration->value);
        $website = $this->resolveWebsite($business);

        $this->tracking->remove($website, $this->scopeOrAbort($website, (string) $request->input('scope')));

        return redirect()->route('customer.workspaces.businesses.website.tracking.index', [$workspaceUid, $businessUid])->with(['status' => 'success', 'message' => 'Tracking removed.']);
    }

    /**
     * A scope is the whole site, or a page/blog identity of THIS website; anything else is refused so no stray rows accumulate.
     *
     * @return array<string, string> scope_key => label
     */
    private function pageScopes(Website $website): array
    {
        $scopes = [];

        foreach ($website->pages()->orderBy('sort_order')->get(['title', 'slug', 'is_home']) as $page) {
            $scopes[WebsiteTrackingSettings::scopeKeyForPage((bool) $page->is_home, $page->slug)] = (string) $page->title;
        }

        $scopes[WebsiteTrackingSettings::BLOG_SCOPE] = 'Blog (index and articles)';

        return array_filter($scopes, fn ($label, $key) => $key !== '', ARRAY_FILTER_USE_BOTH);
    }

    private function scopeOrAbort(Website $website, string $scope): string
    {
        abort_unless($scope === WebsiteTrackingSetting::GLOBAL_SCOPE || array_key_exists($scope, $this->pageScopes($website)), 404);

        return $scope;
    }

    private function resolveWebsite(\App\Models\Business $business): Website
    {
        $website = Website::where('business_id', $business->id)->first();

        abort_unless($website !== null, 404);

        return $website;
    }
}
