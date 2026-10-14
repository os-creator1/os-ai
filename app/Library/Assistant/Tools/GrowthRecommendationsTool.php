<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Growth\GrowthHomeBand;

/** MotionGrove's open recommendations, what is working (recent wins) and the growth score — from the Growth engine's own read, Location-ACL scoped. */
final class GrowthRecommendationsTool implements ReadTool
{
    public function __construct(private readonly GrowthHomeBand $growth)
    {
    }

    public function key(): string
    {
        return 'growth_recommendations';
    }

    public function description(): string
    {
        return 'What to focus on next: open recommendations with their evidence, what is working (recent wins), and the business health score. No args.';
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
        $band = $this->growth->build($context->business, (int) $context->user->id, $workspaceUid, $businessUid);

        if (! $band['has_evaluation']) {
            return new ToolResult($this->key(), ['evaluated' => false], [], [], ['MotionGrove has not finished evaluating this business yet, so there are no recommendations to report.']);
        }

        $items = [];
        $links = [];

        foreach (array_slice($band['items'], 0, 5) as $item) {
            $items[] = ['title' => $item['title'], 'headline' => $item['headline'], 'category' => $item['category_label'], 'impact' => $item['impact']];
            $links[] = ['label' => $item['title'], 'url' => $item['detail_url']];
        }

        return new ToolResult(
            $this->key(),
            ['open_recommendations' => $band['open_count'], 'top' => $items, 'working_well' => array_slice($band['positives'], 0, 4), 'health_score' => $band['score'], 'score_change' => $band['delta'], 'score_change_days' => $band['days']],
            array_values(array_filter([
                ['label' => 'Open recommendations', 'value' => (string) $band['open_count']],
                $band['score'] !== null ? ['label' => 'Business health score', 'value' => (string) $band['score'] . ($band['delta'] !== null ? ' (' . ($band['delta'] >= 0 ? '+' : '') . $band['delta'] . ')' : '')] : null,
            ])),
            $links,
        );
    }
}
