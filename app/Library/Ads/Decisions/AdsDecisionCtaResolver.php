<?php

namespace App\Library\Ads\Decisions;

use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\WebsitePage;
use Illuminate\Support\Facades\Route;

/**
 * Acquisition Purpose + Ads Decisioning V1 — turns a decision's call-to-action
 * KIND into an INTERNAL MotionGrove destination, or into nothing.
 *
 * A destination is returned only when the page exists AND the viewer's plan can
 * open it. When it cannot (a Core plan has no campaign pages), the result is
 * `url = null` and the decision text is the instruction: there is never a fake
 * button, and never a link to a route that would 404.
 *
 * Landing-page review is the one case that may leave the platform, and only
 * because the owner themselves entered that external address as the goal's
 * destination; it is flagged `external` so the view opens it safely.
 */
final class AdsDecisionCtaResolver
{
    /**
     * @return array{label: string, url: ?string, external: bool}|null
     */
    public function resolve(AdsDecision $decision, string $provider, Business $business, bool $hasFullModule): ?array
    {
        if ($decision->ctaKind === null) {
            return null;
        }

        $workspaceUid = (string) $business->workspace->uid;
        $params = [$workspaceUid, (string) $business->uid];
        $label = (string) $decision->ctaLabel;
        $purpose = $decision->purposeUid === null ? null : AcquisitionPurpose::query()->where('business_id', $business->id)->where('uid', $decision->purposeUid)->first();
        $prefix = 'customer.workspaces.businesses.ads.';
        $meta = $provider === 'meta';

        $url = match ($decision->ctaKind) {
            AdsDecisionCtaKind::OpenCampaign => $hasFullModule && $decision->focusCampaignUid !== null
                ? $this->route($prefix . ($meta ? 'meta.campaigns.show' : 'campaigns.show'), [...$params, $decision->focusCampaignUid])
                : null,
            AdsDecisionCtaKind::ReviewSearchTerms => $hasFullModule && ! $meta ? $this->route($prefix . 'search-terms.index', $params) : null,
            AdsDecisionCtaKind::ReviewAd => $hasFullModule && $meta ? $this->route($prefix . 'meta.ads.index', $params) : null,
            AdsDecisionCtaKind::OpenPipeline => $this->pipelineUrl($params, $purpose),
            AdsDecisionCtaKind::FixTracking => $hasFullModule ? $this->route($prefix . ($meta ? 'meta.leads.index' : 'leads.index'), $params) : $this->route($prefix . 'goals', $params),
            AdsDecisionCtaKind::AssignGoal, AdsDecisionCtaKind::SetTargets, AdsDecisionCtaKind::FinishGoalSetup => $this->route($prefix . 'goals', $params),
            AdsDecisionCtaKind::ReviewLandingPage => null,
        };

        if ($decision->ctaKind === AdsDecisionCtaKind::ReviewLandingPage) {
            return $this->landing($purpose, $business, $params, $label);
        }

        return ['label' => $label, 'url' => $url, 'external' => false];
    }

    /** @param list<string> $params */
    private function pipelineUrl(array $params, ?AcquisitionPurpose $purpose): ?string
    {
        $pipelineUid = $purpose?->pipeline?->uid;

        return $this->route('customer.workspaces.businesses.crm.board', $params, $pipelineUid === null ? [] : ['pipeline' => (string) $pipelineUid]);
    }

    /**
     * @param  list<string>  $params
     * @return array{label: string, url: ?string, external: bool}
     */
    private function landing(?AcquisitionPurpose $purpose, Business $business, array $params, string $label): array
    {
        if ($purpose?->destination_type === AcquisitionPurpose::DESTINATION_EXTERNAL_URL && filled($purpose->destination_url)) {
            return ['label' => $label, 'url' => (string) $purpose->destination_url, 'external' => true];
        }

        if ($purpose?->destination_type === AcquisitionPurpose::DESTINATION_HOSTED_PAGE && $purpose->destination_page_id !== null) {
            $page = WebsitePage::query()->whereKey($purpose->destination_page_id)->first(['uid']);

            if ($page !== null) {
                return ['label' => $label, 'url' => $this->route('customer.workspaces.businesses.website.pages.edit', [...$params, (string) $page->uid]), 'external' => false];
            }
        }

        // No destination chosen yet: the goal's setup is where one is chosen.
        return ['label' => $label, 'url' => $this->route('customer.workspaces.businesses.ads.goals', $params), 'external' => false];
    }

    /**
     * @param  array<int, string>  $params
     * @param  array<string, string>  $query
     */
    private function route(string $name, array $params, array $query = []): ?string
    {
        return Route::has($name) ? route($name, $params + $query) : null;
    }
}
