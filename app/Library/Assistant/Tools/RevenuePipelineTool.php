<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantHandles;
use App\Library\Assistant\ToolResult;
use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Dashboard\Home\HomeRevenueReader;

/**
 * The same revenue pipeline Business Home shows — one reader, one priority order, so the assistant can never tell the owner
 * something Home contradicts. Read-only; Location access is applied inside the reader per actor.
 */
final class RevenuePipelineTool implements ReadTool
{
    public const KIND = 'deal';

    public function __construct(private readonly HomeRevenueReader $revenue, private readonly DashboardLinkGate $links)
    {
    }

    public function key(): string
    {
        return 'revenue_pipeline';
    }

    public function description(): string
    {
        return 'Open deals in priority order with their real state: paid deals needing the owner to review and mark won, signed but unpaid, proposals sent but unsigned, expired or draft proposals, deals with no booking or proposal yet, and booked ones. No args. Never estimates outcomes.';
    }

    public function rules(): array
    {
        return [];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->entitled('crm') && $context->can('view_contact');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        $data = $this->revenue->read($context->business, (int) $context->user->id, null, 10);

        if ($data['rows'] === []) {
            return new ToolResult($this->key(), ['open_deals' => $data['openCount'], 'deals' => []], [], [], ['There are no open deals you can see right now.']);
        }

        $deals = [];
        $links = [];

        foreach ($data['rows'] as $row) {
            $deals[] = [
                'deal' => AssistantHandles::for($context, self::KIND, $row['opportunityUid']),
                'title' => $row['title'],
                'value_minor' => $row['valueMinor'],
                'currency' => $row['currency'],
                'state' => $row['stateLabel'],
                'next_step' => $row['nextStep'],
            ];
            $url = $this->links->url($context->customer, $context->user, $context->entitlements, 'customer.workspaces.businesses.crm.opportunities.show', array_merge($context->scoped, [$row['opportunityUid']]), ['view_contact'], 'crm');

            if ($url !== null) {
                $links[] = ['label' => $row['title'], 'url' => $url];
            }
        }

        $counts = $data['counts'];
        $facts = [
            ['label' => 'Open deals', 'value' => (string) $data['openCount']],
            ['label' => 'Paid, awaiting your review', 'value' => (string) ($counts['paid'] ?? 0)],
            ['label' => 'Signed, unpaid', 'value' => (string) ($counts['signed'] ?? 0)],
            ['label' => 'Proposal sent, unsigned', 'value' => (string) ($counts['proposal_sent'] ?? 0)],
            ['label' => 'No booking or proposal yet', 'value' => (string) ($counts['new'] ?? 0)],
        ];

        return new ToolResult($this->key(), ['open_deals' => $data['openCount'], 'counts_among_most_recent' => $counts, 'deals' => $deals], $facts, $links);
    }
}
