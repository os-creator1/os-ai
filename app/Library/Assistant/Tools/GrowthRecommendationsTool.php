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
        // The SAME head selection Business Home uses (Growth plan: SEO-first), so "my top recommendation" is the one Home shows.
        $seoFirst = $context->customer->frameWorkspace()?->tier === \App\Enums\Entitlement\WorkspacePlanTier::Growth;
        $band = $this->growth->build($context->business, (int) $context->user->id, $workspaceUid, $businessUid, $seoFirst);

        if (! $band['has_evaluation']) {
            return new ToolResult($this->key(), ['evaluated' => false], [], [], ['MotionGrove has not finished evaluating this business yet, so there are no recommendations to report.']);
        }

        $items = [];
        $links = [];

        // The engine's chosen head first (it may be the promoted SEO candidate, which is not in the listed rows), flagged as primary.
        $headUid = $band['head']?->uid;
        $listed = $band['items'];
        $primary = null;

        foreach (array_merge($listed, is_array($band['promoted'] ?? null) ? [$band['promoted']] : []) as $candidate) {
            if ($headUid !== null && $candidate['uid'] === $headUid) {
                $primary = $candidate;

                break;
            }
        }

        $ordered = $primary === null ? $listed : array_merge([$primary], array_values(array_filter($listed, fn (array $i): bool => $i['uid'] !== $headUid)));

        foreach (array_slice($ordered, 0, 5) as $i => $item) {
            $isPrimary = $primary !== null && $i === 0;
            $row = ['title' => $item['title'], 'headline' => $item['headline'], 'category' => $item['category_label'], 'impact' => $item['impact'], 'is_primary' => $isPrimary];

            if ($isPrimary) {
                // Only what the engine persisted for this recommendation; absent stays absent (never filled in by the model).
                $row += array_filter([
                    'why_it_matters' => $item['why'] ?? null,
                    'evidence' => $item['evidence_summary'] ?? null,
                    'expected_outcome' => $item['expected'] ?? null,
                    'confidence' => $item['confidence'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            }

            $items[] = $row;
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
