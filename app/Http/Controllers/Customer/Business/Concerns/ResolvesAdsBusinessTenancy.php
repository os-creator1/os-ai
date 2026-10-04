<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleAds\GoogleAdsConnectionManager;
use App\Library\GoogleAds\Sync\GoogleAdsFreshness;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;
use App\Models\Workspace;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Google Ads Module V1 (contract 23 §7) — the ONE place every Ads controller
 * runs the mandatory chain: Workspace -> Business -> BusinessRouteAccess ->
 * active Business -> the entitlement decision. Every failure is a 404 (never
 * 403), so a foreign or unentitled Business is indistinguishable from a
 * missing one. The capability (`view_google_ads` / `manage_google_ads`) is
 * checked by the controller AFTER this chain, so a caller outside the tenancy
 * never learns whether they would have been permitted.
 *
 * TWO ENTITLEMENT SHAPES (no plan names anywhere — only feature keys):
 *   - resolveAdsTenancy():       ads_basic_visibility OR google_ads_module.
 *                                Overview, Settings and the connection
 *                                actions: Core connects and reads.
 *   - resolveAdsModuleTenancy(): google_ads_module ONLY. Budget and every
 *                                page of the full module (Core gets 404).
 *
 * REUSE CONTRACT FOR LATER PAGES. A page that needs the selected account
 * calls resolveAdsAccount($business) — the single helper (Business ->
 * connection active -> account row) — and renders the standard empty state
 * when it returns null; adsViewData() builds everything the shared header,
 * sub-navigation and freshness partials need.
 *
 * Requires ResolvesBusinessTenancy on the using controller.
 */
trait ResolvesAdsBusinessTenancy
{
    /**
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveAdsTenancy(string $workspaceUid, string $businessUid): array
    {
        // ads_basic_visibility is packaged into every plan that carries the
        // module, so the first attempt answers for everyone except an
        // override that grants only the module key.
        try {
            return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::AdsBasicVisibility->value);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }
        }

        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::GoogleAdsModule->value);
    }

    /**
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveAdsModuleTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::GoogleAdsModule->value);
    }

    /**
     * The whole preamble of a full-module READ page in one call: tenancy
     * (google_ads_module only, so Core is a 404) THEN `view_google_ads`, then
     * the shared view data. The page renders the empty state when the
     * returned `adsState` is not 'ready'.
     *
     * @return array<string, mixed>
     */
    protected function resolveAdsModulePage(string $workspaceUid, string $businessUid, string $activeKey): array
    {
        [$workspace, $business] = $this->resolveAdsModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('view_google_ads');

        return $this->adsViewData($workspace, $business, $activeKey);
    }

    /**
     * Workspace -> Business -> active Business, with NO entitlement step.
     * Used only by Disconnect, mirroring the GBP precedent (contract §39.4):
     * stored credentials must never be trapped by a plan change.
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveAdsAccessibleTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveBusinessTenancy($workspaceUid, $businessUid, requireActive: true);
    }

    /**
     * Whether ONE already-resolved, actor-accessible Business is entitled to
     * the Ads surface (either feature). A tenancy/entitlement mismatch is
     * "not entitled", never an error the caller can distinguish.
     */
    protected function adsEntitlementAllows(Workspace $workspace, Business $business, int $userId): bool
    {
        foreach ([PlatformFeature::AdsBasicVisibility, PlatformFeature::GoogleAdsModule] as $feature) {
            if ($this->adsFeatureAllows($workspace, $business, $feature, $userId)) {
                return true;
            }
        }

        return false;
    }

    protected function adsFeatureAllows(Workspace $workspace, Business $business, PlatformFeature $feature, int $userId): bool
    {
        try {
            return app(EntitlementManager::class)->decide($workspace, $business, $feature->value, $userId)->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }
    }

    protected function adsConnections(): GoogleAdsConnectionManager
    {
        return app(GoogleAdsConnectionManager::class);
    }

    protected function adsConnectionFor(Business $business): ?BusinessGoogleConnection
    {
        return $this->adsConnections()->findForBusiness($business);
    }

    /**
     * THE one account helper: the Business's selected Google Ads account, or
     * null when there is none to show — no connection, a connection that is
     * not Active (pending, revoked, disconnected), or no selected account.
     * Always keyed by the Business AND its own Active connection, so an
     * account row can never be read through another Business's connection.
     * Null means "render the standard empty state".
     */
    protected function resolveAdsAccount(Business $business): ?GoogleAdsAccount
    {
        $connection = $this->adsConnectionFor($business);

        if ($connection === null || ! $connection->isActive()) {
            return null;
        }

        return GoogleAdsAccount::query()
            ->where('business_id', $business->id)
            ->where('business_google_connection_id', $connection->id)
            ->whereNotNull('selected_at') // unselected by a disconnect / revoke: choose the account again
            ->first();
    }

    /**
     * The view data every Ads page shares: identifiers, connection, the
     * resolved account (or null), a coarse `state`
     * (`not_connected` | `no_account` | `ready`), the freshness snapshot, the
     * capability flags and the sub-navigation for the shared partials.
     *
     * @return array<string, mixed>
     */
    protected function adsViewData(Workspace $workspace, Business $business, string $activeKey): array
    {
        $connection = $this->adsConnectionFor($business);
        $active = $connection !== null && $connection->isActive();
        $account = $active ? $this->resolveAdsAccount($business) : null;
        $hasModule = $this->adsFeatureAllows($workspace, $business, PlatformFeature::GoogleAdsModule, (int) Auth::id());

        return [
            'workspaceUid' => (string) $workspace->uid,
            'businessUid' => (string) $business->uid,
            'business' => $business,
            'connection' => $connection,
            'account' => $account,
            'adsState' => ! $active ? 'not_connected' : ($account === null ? 'no_account' : 'ready'),
            'adsHasModule' => $hasModule,
            'adsCanManage' => Gate::allows('manage_google_ads'),
            'freshness' => $account === null ? null : app(GoogleAdsFreshness::class)->for($account),
            'adsNav' => $this->adsNavigation((string) $workspace->uid, (string) $business->uid, $hasModule, $activeKey),
            'adsActive' => $activeKey,
        ];
    }

    /**
     * The in-page sub-navigation. A child appears only when its route exists
     * (the later pages register theirs) AND the Business is entitled to it,
     * so a Core Business is never offered a page it cannot open.
     *
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    protected function adsNavigation(string $workspaceUid, string $businessUid, bool $hasModule, string $activeKey): array
    {
        $prefix = 'customer.workspaces.businesses.ads.';

        // key => [label, route, requires google_ads_module]
        $definition = [
            'overview' => ['Overview', $prefix . 'index', false],
            'campaigns' => ['Campaigns', $prefix . 'campaigns.index', true],
            'keywords' => ['Keywords', $prefix . 'keywords.index', true],
            'search-terms' => ['Search terms', $prefix . 'search-terms.index', true],
            'leads' => ['Leads & conversions', $prefix . 'leads.index', true],
            'budget' => ['Budget', $prefix . 'budget', true],
            'recommendations' => ['Recommendations', $prefix . 'recommendations.index', true],
            'settings' => ['Settings', $prefix . 'settings', false],
        ];

        $items = [];

        foreach ($definition as $key => [$label, $route, $needsModule]) {
            if (($needsModule && ! $hasModule) || ! Route::has($route)) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => $label,
                'url' => route($route, [$workspaceUid, $businessUid]),
                'active' => $key === $activeKey,
            ];
        }

        return $items;
    }

    /**
     * A flash redirect helper's payload: status/message in the shape
     * <x-flash-alert> reads. Messages are fixed copy, never provider text.
     *
     * @return array{status: string, message: string}
     */
    protected function adsFlash(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }
}
