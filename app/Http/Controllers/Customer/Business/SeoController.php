<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Seo\SeoOverviewReader;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Contract 18 Sub-slice 18A — the Business-scoped SEO Overview.
 *
 * READ-ONLY. There is no write action anywhere in this controller: it does
 * not write Website, Business, Location or Google data, calls no provider,
 * and calls no AI. The Overview it serves is computed from platform-owned
 * tables only (SeoOverviewReader).
 *
 * Every Business-scoped action runs the mandatory chain (Contract 18 §10.1,
 * mirroring GBP §15.1): Workspace by uid → Business inside it →
 * userCanAccessBusiness() → Business Active → the entitlement decision for
 * PlatformFeature::SeoBasicVisibility → the `view_seo` capability. Every
 * failure of the tenancy or entitlement steps is `abort(404)`, never 403, so
 * a foreign or unentitled resource is indistinguishable from a missing one.
 * `Auth::id()` is the capability subject and audit actor only, never tenancy.
 *
 * FAIL-CLOSED WHILE `Planned`. SeoBasicVisibility is registered Planned
 * until Sub-slice H flips it (RFC-004: a Planned feature is never
 * customer-executable). EntitlementManager therefore denies it for every
 * tier, so every route here answers 404 today — the controller is
 * unreachable by design, not by omission.
 *
 * NAMING. Everything SEO is under `customer.workspaces.businesses.seo.*` and
 * `customer.seo.*`; nothing begins with `customer.keywords.` (the legacy
 * inbound-SMS keyword product's namespace) and no legacy Keywords permission
 * or model is used.
 */
class SeoController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesSeoBusinessTenancy;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly WorkspaceManager $workspaceManager,
        private readonly EntitlementManager $entitlementManager,
        private readonly SeoOverviewReader $overviewReader,
    ) {
    }

    /**
     * The bare /seo entry. NEVER guesses a Business: zero accessible shows an
     * empty state, exactly one redirects through, several show a chooser.
     * "Accessible" includes entitlement, so a Business that is not entitled
     * never appears.
     *
     * ORDER IS LOAD-BEARING. The implementation-availability floor comes
     * FIRST, before the capability check and before any Business is touched:
     * while SeoBasicVisibility is Planned the whole surface is 404 for every
     * caller, so neither a 200 empty state nor a different (401) response for
     * a caller who lacks `view_seo` can reveal that the SEO surface exists
     * (Contract 18 §14.1). The bare route has no Business yet, so
     * EntitlementManager cannot decide anything per Business here; the
     * registry is the one implementation-availability authority and is asked
     * directly. Only once the feature is Available do the capability check
     * and the 0 / 1 / many selector run.
     */
    public function entry(): View|RedirectResponse
    {
        abort_unless($this->seoIsImplementedAndAvailable(), 404);

        $this->authorize('view_seo');

        $accessible = $this->entitledBusinesses();

        if (count($accessible) === 1) {
            [$workspace, $business] = $accessible[0];

            return redirect()->route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]);
        }

        return view('customer.business.seo.entry', ['accessible' => $accessible]);
    }

    public function overview(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveSeoTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        return view('customer.business.seo.overview', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'overview' => $this->overviewReader->read($workspace, $business, Auth::user()),
        ]);
    }

    /**
     * The implementation-availability floor for the bare entry: is
     * SeoBasicVisibility Available in PlatformFeatureRegistry? Its own
     * method, exactly like the entitlement step below, so tests can reach the
     * post-floor selector logic without flipping the registry (Sub-slice H
     * owns the flip). Production never overrides it.
     */
    protected function seoIsImplementedAndAvailable(): bool
    {
        return PlatformFeatureRegistry::isAvailable(PlatformFeature::SeoBasicVisibility->value);
    }

    /**
     * Whether SEO is entitled for one already-resolved, actor-accessible
     * Business. A tenancy/entitlement mismatch is "not entitled", never an
     * error the caller can distinguish.
     */
    protected function seoEntitlementAllows(Workspace $workspace, Business $business, int $userId): bool
    {
        try {
            return $this->entitlementManager
                ->decide($workspace, $business, PlatformFeature::SeoBasicVisibility->value, $userId)
                ->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }
    }

    /**
     * @return array<int, array{0: Workspace, 1: Business}>
     */
    private function entitledBusinesses(): array
    {
        $userId = (int) Auth::id();
        $accessible = [];

        foreach ($this->workspaceRepository->allForUser($userId) as $workspace) {
            if (! $workspace->is_active) {
                continue;
            }

            foreach ($this->workspaceRepository->businessesForWorkspace($workspace) as $business) {
                if (! $this->workspaceManager->userCanAccessBusiness($userId, $business)) {
                    continue;
                }

                if ($business->status !== BusinessStatus::Active) {
                    continue;
                }

                if ($this->seoEntitlementAllows($workspace, $business, $userId)) {
                    $accessible[] = [$workspace, $business];
                }
            }
        }

        return $accessible;
    }
}
