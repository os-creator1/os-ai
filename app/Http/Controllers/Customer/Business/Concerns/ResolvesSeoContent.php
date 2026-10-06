<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteArticle;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * SEO Content Engine V1 — the one place the two Content controllers run the entitlement chain.
 *
 * Two packages, both existing platform features (no new feature, no plan-name check):
 *   - the Content area, Articles, the editor, preview and the analysis checklist ride SeoBasicVisibility
 *     (Core + Growth + Agency) — resolveSeoTenancy();
 *   - Opportunities, Content Plan clusters, AI drafting and the freshness / rank panels ride SeoModule
 *     (Growth + Agency) — resolveContentModuleTenancy(): anything less is a 404, like every other
 *     SeoModule route.
 *
 * Requires ResolvesBusinessTenancy and ResolvesSeoBusinessTenancy on the using controller.
 * Reading an article is ALWAYS done through ArticleManager::find(), scoped to the Business, so another
 * Business's uid is indistinguishable from an unknown one.
 */
trait ResolvesSeoContent
{
    /**
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveContentModuleTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::SeoModule->value);
    }

    /** Does this Business have the SeoModule package? Never throws; used to SHOW or HIDE Growth panels. */
    protected function hasContentModule(Workspace $workspace, Business $business): bool
    {
        return app(EntitlementManager::class)
            ->decide($workspace, $business, PlatformFeature::SeoModule->value, (int) Auth::id())->allowed;
    }

    protected function websiteOf(Business $business): ?Website
    {
        return Website::query()->where('business_id', $business->id)->first();
    }

    /** A Business with no Website yet: a friendly "create your website first" page, never an error. */
    protected function noWebsite(string $workspaceUid, string $businessUid, Business $business): \Illuminate\Contracts\View\View
    {
        return view('customer.business.seo.content.no-website', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
        ]);
    }

    protected function articleOr404(Business $business, string $articleUid): WebsiteArticle
    {
        $article = app(\App\Library\Seo\Content\ArticleManager::class)->find($business, $articleUid);

        abort_if($article === null, 404);

        return $article;
    }
}
