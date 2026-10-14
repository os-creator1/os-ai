<?php

namespace App\Library\Assistant\Tools;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Dashboard\DashboardLinkGate;

/** Automation executions over the last 30 days — how many ran, succeeded and failed. */
final class AutomationsStatusTool implements ReadTool
{
    public function __construct(private readonly BusinessAnalyticsQueries $queries, private readonly DashboardLinkGate $links)
    {
    }

    public function key(): string
    {
        return 'automations_status';
    }

    public function description(): string
    {
        return 'Automations: runs in the last 30 days and how many succeeded, failed or are pending. No args.';
    }

    public function rules(): array
    {
        return [];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->entitled('automations') && $context->can('automations');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $context->timezone());
        $kpis = $this->queries->automationKpis($context->business, $range);
        $url = $this->links->url($context->customer, $context->user, $context->entitlements, 'customer.workspaces.businesses.automations.index', $context->scoped, ['automations'], 'automations');
        $links = $url === null ? [] : [['label' => 'Open automations', 'url' => $url]];

        if ($kpis === null) {
            return new ToolResult($this->key(), ['runs' => null], [], $links, ['Automation run data is not available for this business.']);
        }

        return new ToolResult(
            $this->key(),
            ['period' => $range->label(), 'runs' => $kpis->executionsInRange, 'succeeded' => $kpis->succeeded(), 'failed' => $kpis->failed(), 'skipped' => $kpis->skipped(), 'pending' => $kpis->pending()],
            [['label' => 'Automation runs · ' . $range->label(), 'value' => $kpis->executionsInRange . ' (' . $kpis->failed() . ' failed)']],
            $links,
            $kpis->executionsInRange === 0 ? ['No automation has run in this period.'] : [],
        );
    }
}
