<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Seo\Rank\SeoRankDashboardReader;
use App\Library\Seo\Rank\SeoRankEntitlement;
use App\Library\Seo\Rank\SeoRankHistoryReader;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Seo\SeoLocationScope;
use App\Library\Seo\SeoOverviewReader;
use App\Models\SeoKeyword;

/**
 * SEO as MotionGrove measures it: published-page coverage, indexability and tracked-keyword positions with their
 * recorded movement. Search Console is not wired into this reader, and the tool says so rather than implying it.
 */
final class SeoStatusTool implements ReadTool
{
    public function __construct(
        private readonly SeoOverviewReader $overview,
        private readonly SeoLocationScope $locationScope,
        private readonly SeoKeywordManager $keywords,
        private readonly SeoRankEntitlement $rankEntitlement,
        private readonly SeoRankDashboardReader $rankDashboard,
        private readonly DashboardLinkGate $linkGate,
    ) {
    }

    public function key(): string
    {
        return 'seo_status';
    }

    public function description(): string
    {
        return 'SEO: tracked keywords with current position and recorded movement (improved/declined), how many website pages have titles/descriptions, whether the site can be indexed. No args.';
    }

    public function rules(): array
    {
        return [];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->can('view_seo') && $context->entitled('seo_basic_visibility');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        $actorId = (int) $context->user->id;
        $overview = $this->overview->read($context->workspace, $context->business, $context->user);
        $accessibleIds = $this->locationScope->accessibleLocations($actorId, $context->business)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $active = $this->keywords->listVisible($actorId, $context->business, $accessibleIds)->filter(fn (SeoKeyword $k) => $k->isActive())->values();

        $plan = $this->rankEntitlement->planFor($context->business);
        $rank = $this->rankDashboard->build($context->business, $active, $plan);

        $rows = [];
        $improved = [];
        $declined = [];

        foreach ($rank['rows'] as $row) {
            $organic = $row['organic'] ?? null;
            $change = $row['change'] ?? ['kind' => 'none', 'amount' => null];
            $entry = [
                'keyword' => (string) $row['keyword']->phrase,
                'state' => $row['state'],
                'position' => $organic !== null && $organic->isFound() ? (int) $organic->position : null,
                'change' => $change['kind'],
                'change_amount' => $change['amount'],
                'last_checked' => ($row['last_checked_at'] ?? null)?->toDateString(),
                'may_be_out_of_date' => ($row['stale_days'] ?? null) !== null,
            ];
            $rows[] = $entry;

            if ($change['kind'] === SeoRankHistoryReader::CHANGE_UP || $change['kind'] === SeoRankHistoryReader::CHANGE_ENTERED) {
                $improved[] = $entry;
            } elseif ($change['kind'] === SeoRankHistoryReader::CHANGE_DOWN || $change['kind'] === SeoRankHistoryReader::CHANGE_DROPPED) {
                $declined[] = $entry;
            }
        }

        usort($improved, fn (array $a, array $b): int => ((int) $b['change_amount']) <=> ((int) $a['change_amount']));
        usort($declined, fn (array $a, array $b): int => ((int) $b['change_amount']) <=> ((int) $a['change_amount']));

        $limitations = ['Google Search Console data is not part of this view, so clicks and impressions are not available here.'];

        if ($plan === null) {
            $limitations[] = 'Keyword position tracking is not part of this plan, so positions and movement are not measured.';
        } elseif (! array_filter($rank['rows'], fn (array $r): bool => $r['target'] !== null)) {
            $limitations[] = 'No keyword is being tracked yet, so there are no positions or movement to report.';
        }

        $stale = array_values(array_filter($rows, fn (array $r): bool => $r['may_be_out_of_date']));

        if ($stale !== []) {
            $limitations[] = count($stale) . ' tracked keyword position(s) are older than the freshness window and may be out of date.';
        }

        $data = [
            'tracked_keywords' => array_slice($rows, 0, 12),
            'keywords_total' => count($rows),
            'improved' => array_slice($improved, 0, 5),
            'declined' => array_slice($declined, 0, 5),
            'summary' => array_intersect_key($rank['summary'], array_flip(['average_organic', 'top10', 'improved', 'local_top3'])),
            'website_pages' => $overview->content,
            'site_search_visibility' => $overview->indexability->state->value,
        ];

        $facts = [['label' => 'Keywords you track', 'value' => (string) count($rows)]];

        if ($rank['summary']['improved'] !== null) {
            $facts[] = ['label' => 'Keywords that improved', 'value' => (string) $rank['summary']['improved']];
        }

        if ($rank['summary']['top10'] !== null) {
            $facts[] = ['label' => 'Keywords in the top 10', 'value' => (string) $rank['summary']['top10']];
        }

        if ($overview->content !== null) {
            $facts[] = ['label' => 'Website pages with a description', 'value' => $overview->content['with_meta_description'] . ' of ' . $overview->content['pages']];
        }

        $url = $this->linkGate->url($context->customer, $context->user, $context->entitlements, 'customer.workspaces.businesses.seo.keywords.index', $context->scoped, ['view_seo'], 'seo_basic_visibility');
        $links = $url === null ? [] : [['label' => 'Open SEO keywords', 'url' => $url]];

        return new ToolResult($this->key(), $data, $facts, $links, $limitations);
    }
}
