<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Dashboard\RecentWorkReader;
use App\Library\Dashboard\DashboardLinkGate;

/** What MotionGrove has actually recorded doing: published website versions, completed recommendations, listing work. Nothing inferred. */
final class RecentWorkTool implements ReadTool
{
    public function __construct(private readonly RecentWorkReader $work, private readonly DashboardLinkGate $links)
    {
    }

    public function key(): string
    {
        return 'recent_work';
    }

    public function description(): string
    {
        return "MotionGrove's recorded work for this business, newest first (website versions published, recommendations completed, Google listing work). No args.";
    }

    public function rules(): array
    {
        return [];
    }

    public function available(AssistantContext $context): bool
    {
        return true;
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        $items = $this->work->recent($context->business, 8);
        $out = [];
        $links = [];

        foreach ($items as $item) {
            if ($item->permissions !== [] && ! collect($item->permissions)->contains(fn (string $p): bool => $context->can($p))) {
                continue;
            }

            if ($item->featureKey !== null && ! $context->entitled($item->featureKey)) {
                continue;
            }

            $out[] = ['what' => $item->text, 'at' => $item->at->toDateString()];
        }

        $out = array_slice($out, 0, 6);

        return new ToolResult(
            $this->key(),
            ['recorded_work' => $out],
            [['label' => 'Recorded work items', 'value' => (string) count($out)]],
            [],
            $out === [] ? ['Nothing has been recorded yet, so there is no completed work to report.'] : [],
        );
    }
}
