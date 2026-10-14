<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Growth\GrowthOpportunityPresenter;
use App\Library\Growth\GrowthOpportunityReader;
use App\Library\Growth\GrowthViewer;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Opportunity;

/** Google Ads / Meta Ads: the Ads Decision Engine's persisted recommendations, read as Growth Opportunities (no provider call). */
final class AdsStatusTool implements ReadTool
{
    public function __construct(
        private readonly GrowthOpportunityReader $reader,
        private readonly GrowthOpportunityPresenter $presenter,
        private readonly LocationAccessGuard $guard,
    ) {
    }

    public function key(): string
    {
        return 'ads_status';
    }

    public function description(): string
    {
        return 'Advertising (Google Ads, Meta Ads): the stored recommendations about ad performance and whether ads are connected. No args.';
    }

    public function rules(): array
    {
        return [];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->entitled('ai_coo_basic') && $context->can('business_advisor');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        [$workspaceUid, $businessUid] = $context->scoped;
        $viewer = GrowthViewer::resolve($this->guard, (int) $context->user->id, $context->business);

        $cards = $this->reader->top($context->business, $viewer, 15)
            ->filter(fn (Opportunity $o): bool => str_contains(strtolower((string) ($o->worker_key->value ?? $o->worker_key)), 'ads'))
            ->take(3)
            ->map(fn (Opportunity $o): array => $this->presenter->present($o, [], $workspaceUid, $businessUid))
            ->values()
            ->all();

        $links = [];
        $full = $context->action('ads')['fullUrl'] ?? null;

        if ($full !== null) {
            $links[] = ['label' => 'Open ads', 'url' => $full];
        }

        if ($cards === []) {
            return new ToolResult($this->key(), ['recommendations' => []], [], $links, ['No ad recommendation is stored right now. That can mean ads are not connected, there is not enough ad data yet, or nothing needs attention — MotionGrove cannot tell which from this view.']);
        }

        $data = ['recommendations' => array_map(fn (array $c): array => ['title' => $c['title'], 'headline' => $c['headline'], 'why' => $c['why'], 'impact' => $c['impact'], 'evidence' => $c['evidence_summary']], $cards)];
        $facts = [['label' => 'Ad recommendations', 'value' => (string) count($cards)]];

        foreach ($cards as $c) {
            $links[] = ['label' => $c['title'], 'url' => $c['detail_url']];
        }

        return new ToolResult($this->key(), $data, $facts, array_slice($links, 0, 4));
    }
}
