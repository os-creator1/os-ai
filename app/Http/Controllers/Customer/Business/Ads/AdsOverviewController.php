<?php

namespace App\Http\Controllers\Customer\Business\Ads;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesAdsBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesMetaAdsBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Ads\AdsChannelOverviewReader;
use App\Library\Ads\AdsFeatureAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Meta Ads Module V1 (contract 24 §9) — the cross-channel Ads Overview: one
 * compact, never-blended block per connected channel plus a merged "What needs
 * attention" list. GET only, cached data only, no provider call.
 *
 * Tenancy: Workspace -> Business -> active Business -> ads_basic_visibility OR
 * the full Ads module. A provider block is shown only when the viewer holds
 * that provider's read capability (`view_google_ads` / `view_meta_ads`); the
 * page itself needs at least one of them. Every figure is the viewed
 * Business's own: nothing crosses Businesses.
 */
class AdsOverviewController extends CustomerBaseController
{
    use ResolvesAdsBusinessTenancy;
    use ResolvesBusinessTenancy;
    use ResolvesMetaAdsBusinessTenancy;

    public function __construct(private readonly AdsChannelOverviewReader $reader)
    {
    }

    public function overview(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveAdsTenancy($workspaceUid, $businessUid);

        $viewGoogle = Gate::allows('view_google_ads');
        $viewMeta = Gate::allows('view_meta_ads');

        // Missing capability is the application-wide authorization failure, after tenancy.
        if (! $viewGoogle && ! $viewMeta) {
            $this->authorize('view_google_ads');
        }

        $hasModule = app(AdsFeatureAccess::class)->hasFullModule($workspace, $business, (int) Auth::id());

        $googleAccount = $viewGoogle ? $this->resolveAdsAccount($business) : null;
        $googleConnection = $viewGoogle ? $this->adsConnectionFor($business) : null;
        $metaAccount = $viewMeta ? $this->resolveMetaAdsAccount($business) : null;
        $metaConnection = $viewMeta ? $this->metaAdsConnectionFor($business) : null;
        $metaExpired = $metaConnection !== null && in_array($metaConnection->state->value, ['expired', 'revoked'], true);

        $overview = $this->reader->read(
            (string) $workspace->uid,
            (string) $business->uid,
            [
                'visible' => $viewGoogle,
                'state' => $googleAccount !== null ? 'ready' : ($googleConnection !== null && $googleConnection->isActive() ? 'no_account' : 'not_connected'),
                'account' => $googleAccount,
            ],
            [
                'visible' => $viewMeta,
                'state' => $metaAccount !== null ? 'ready' : ($metaExpired ? 'expired' : ($metaConnection !== null && $metaConnection->isActive() ? 'no_account' : 'not_connected')),
                'account' => $metaAccount,
            ],
            $hasModule,
        );

        return view('customer.business.ads.channels', [
            'workspaceUid' => (string) $workspace->uid,
            'businessUid' => (string) $business->uid,
            'business' => $business,
            'account' => null,
            'freshness' => null,
            'adsNav' => [],
            'adsProviders' => $this->adsProviderSwitcher((string) $workspace->uid, (string) $business->uid),
            'provider' => 'overview',
            'channels' => $overview['channels'],
            'total' => $overview['total'],
            'mixedCurrencies' => $overview['mixedCurrencies'],
            'attention' => $overview['attention'],
        ]);
    }
}
