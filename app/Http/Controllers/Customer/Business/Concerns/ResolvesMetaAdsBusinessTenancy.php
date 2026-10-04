<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Library\Ads\AdsFeatureAccess;
use App\Library\MetaAds\MetaAdsConnectionManager;
use App\Library\Navigation\CustomerContext;
use App\Library\MetaAds\Sync\MetaAdsFreshness;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Meta Ads Module V1 (contract 24 §8) — the ONE place every Meta Ads
 * controller runs the mandatory chain: Workspace -> Business ->
 * BusinessRouteAccess -> active Business -> the entitlement decision. Every
 * failure is a 404 (never 403). The capability (`view_meta_ads` /
 * `manage_meta_ads`) is checked by the controller AFTER this chain.
 *
 * TWO ENTITLEMENT SHAPES (feature keys only, via AdsFeatureAccess):
 *   - resolveMetaAdsTenancy():       ads_basic_visibility OR the full Ads module
 *                                    (Overview, series, Settings, connection actions).
 *   - resolveMetaAdsModuleTenancy(): the full Ads module ONLY (Core gets 404).
 *
 * REUSE CONTRACT FOR THE DATA PAGES (campaigns, ad sets, ads, recommendations,
 * leads): call resolveMetaAdsModulePage($workspaceUid, $businessUid, $activeKey)
 * — tenancy (module only) -> authorize('view_meta_ads') -> view data — and
 * render the standard empty state when `metaState` is not 'ready'.
 * resolveMetaAdsAccount($business) is the single "selected account" helper.
 *
 * Requires ResolvesBusinessTenancy on the using controller.
 */
trait ResolvesMetaAdsBusinessTenancy
{
    /**
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveMetaAdsTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveAnyEntitledBusinessTenancy($workspaceUid, $businessUid, AdsFeatureAccess::anyAdsKeys());
    }

    /**
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveMetaAdsModuleTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveAnyEntitledBusinessTenancy($workspaceUid, $businessUid, AdsFeatureAccess::fullModuleKeys());
    }

    /**
     * Full-module READ page preamble: tenancy (Core = 404), then
     * `view_meta_ads`, then the shared view data.
     *
     * @return array<string, mixed>
     */
    protected function resolveMetaAdsModulePage(string $workspaceUid, string $businessUid, string $activeKey): array
    {
        [$workspace, $business] = $this->resolveMetaAdsModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('view_meta_ads');

        return $this->metaAdsViewData($workspace, $business, $activeKey);
    }

    /**
     * Workspace -> Business -> active Business, NO entitlement step. Used only
     * by Disconnect so stored credentials are never trapped by a plan change.
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveMetaAdsAccessibleTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveBusinessTenancy($workspaceUid, $businessUid, requireActive: true);
    }

    protected function metaAdsConnections(): MetaAdsConnectionManager
    {
        return app(MetaAdsConnectionManager::class);
    }

    protected function metaAdsConnectionFor(Business $business): ?BusinessMetaConnection
    {
        return $this->metaAdsConnections()->findForBusiness($business);
    }

    /**
     * THE one account helper: the Business's selected Meta ad account, or null
     * (no connection, connection not active, or no selected account). Keyed by
     * the Business AND its own active connection.
     */
    protected function resolveMetaAdsAccount(Business $business): ?MetaAdsAccount
    {
        $connection = $this->metaAdsConnectionFor($business);

        if ($connection === null || ! $connection->isActive()) {
            return null;
        }

        return MetaAdsAccount::query()
            ->where('business_id', $business->id)
            ->where('business_meta_connection_id', $connection->id)
            ->whereNotNull('selected_at')
            ->first();
    }

    /**
     * The view data every Meta page shares. `metaState` is one of
     * not_connected | expired | no_account | ready ("expired" also covers a
     * revoked connection: the owner must re-authorise).
     *
     * @return array<string, mixed>
     */
    protected function metaAdsViewData(Workspace $workspace, Business $business, string $activeKey): array
    {
        $connection = $this->metaAdsConnectionFor($business);
        $active = $connection !== null && $connection->isActive();
        $account = $active ? $this->resolveMetaAdsAccount($business) : null;
        $hasModule = app(AdsFeatureAccess::class)->hasFullModule($workspace, $business, (int) Auth::id());

        $expired = $connection !== null && in_array($connection->state->value, ['expired', 'revoked'], true);

        $state = $expired
            ? 'expired'
            : (! $active ? 'not_connected' : ($account === null ? 'no_account' : 'ready'));

        // While an Agency views a client, every state-changing control is withheld: the routes
        // are View-As prohibited anyway, so showing the buttons would only bounce the click.
        $context = request()->attributes->get('customerContext');
        $viewingAsClient = $context instanceof CustomerContext && $context->isViewingAsClient();
        $canAct = ! $viewingAsClient && Gate::allows('manage_meta_ads');

        $canManage = $canAct
            && $connection !== null
            && $this->metaAdsConnections()->canManage($connection);

        return [
            'workspaceUid' => (string) $workspace->uid,
            'businessUid' => (string) $business->uid,
            'business' => $business,
            'connection' => $connection,
            'account' => $account,
            'metaState' => $state,
            'metaHasModule' => $hasModule,
            'metaCanManage' => $canManage,
            // Connecting / re-authorising needs only the capability, not a live ads_management grant.
            'metaCanConnect' => $canAct,
            'metaCanAct' => $canAct,
            'metaViewingAsClient' => $viewingAsClient,
            'tokenStatus' => $connection === null ? null : $this->metaAdsConnections()->tokenStatus($connection),
            'freshness' => $account === null ? null : app(MetaAdsFreshness::class)->for($account),
            'provider' => 'meta',
            'adsProviders' => $this->metaAdsProviderSwitcher((string) $workspace->uid, (string) $business->uid),
            'metaNav' => $this->metaAdsNavigation((string) $workspace->uid, (string) $business->uid, $hasModule, $activeKey),
            'metaActive' => $activeKey,
            'metaFlash' => $this->metaFlash(),
        ];
    }

    /**
     * Provider sub-navigation: Overview, Campaigns, Ad sets, Ads,
     * Recommendations, Leads, Settings. Everything but Overview and Settings
     * needs the full module and is hidden for Core.
     *
     * @return list<array{key: string, label: string, url: string, active: bool}>
     */
    protected function metaAdsNavigation(string $workspaceUid, string $businessUid, bool $hasModule, string $activeKey): array
    {
        $prefix = 'customer.workspaces.businesses.ads.meta.';

        $definition = [
            'overview' => ['Overview', $prefix . 'index', false],
            'campaigns' => ['Campaigns', $prefix . 'campaigns.index', true],
            'ad-sets' => ['Ad sets', $prefix . 'ad-sets.index', true],
            'ads' => ['Ads', $prefix . 'ads.index', true],
            'recommendations' => ['Recommendations', $prefix . 'recommendations.index', true],
            'leads' => ['Leads', $prefix . 'leads.index', true],
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
     * Provider switcher row: Overview (cross-channel) | Google | Meta.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    protected function metaAdsProviderSwitcher(string $workspaceUid, string $businessUid): array
    {
        $prefix = 'customer.workspaces.businesses.ads.';

        $definition = [
            'overview' => ['Overview', $prefix . 'overview'],
            'google' => ['Google', $prefix . 'index'],
            'meta' => ['Meta', $prefix . 'meta.index'],
        ];

        $items = [];

        foreach ($definition as $key => [$label, $route]) {
            if (Route::has($route)) {
                $items[] = ['key' => $key, 'label' => $label, 'url' => route($route, [$workspaceUid, $businessUid])];
            }
        }

        return $items;
    }

    /**
     * Status/message payload for a redirect (<x-flash-alert> shape). Fixed copy only.
     *
     * @return array{status: string, message: string}|null the CURRENT flash, if any
     */
    protected function metaFlash(): ?array
    {
        $message = session('message');

        return is_string($message) && $message !== ''
            ? ['status' => (string) session('status', 'info'), 'message' => $message]
            : null;
    }

    /**
     * @return array{status: string, message: string}
     */
    protected function metaAdsFlash(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }
}
