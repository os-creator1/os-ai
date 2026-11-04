<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\ActionOutcome;
use App\Library\Assistant\ActionPreview;
use App\Library\Assistant\AssistantActionRefused;
use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantHandles;
use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Dashboard\Home\HomeRevenueReader;

/**
 * Takes the owner to a deal at the step its real state calls for. A HANDOFF: it sends nothing, publishes nothing,
 * charges nothing and moves no stage. Booking, proposal, sending, payment and "mark won" stay explicit owner actions
 * on the deal page, each behind its own permission, entitlement and approval.
 */
final class AdvanceDealTool implements ActionTool
{
    public function __construct(private readonly HomeRevenueReader $revenue, private readonly DashboardLinkGate $links)
    {
    }

    public function key(): string
    {
        return 'advance_deal';
    }

    public function description(): string
    {
        return 'Open a deal at its next step so the owner can act (nothing is sent, charged or changed by this). args: deal_ref (the opaque "deal" handle from revenue_pipeline).';
    }

    public function rules(): array
    {
        return ['deal_ref' => ['required', 'string', 'max:64']];
    }

    public function mode(): string
    {
        return self::MODE_HANDOFF;
    }

    public function available(AssistantContext $context): bool
    {
        return $context->entitled('crm') && $context->can('view_contact');
    }

    public function prepare(AssistantContext $context, array $args): ActionPreview
    {
        $row = $this->deal($context, (string) $args['deal_ref']);

        return new ActionPreview(
            title: 'Open "' . $row['title'] . '"',
            lines: [
                ['label' => 'Where it stands', 'value' => $row['stateLabel']],
                ['label' => 'Next step', 'value' => $row['nextStep']],
            ],
            effects: [
                'Nothing is sent, charged or changed. Confirming opens the deal so you can take the next step yourself.',
                'Booking, proposals, payment requests and marking a deal won each need your own action there.',
            ],
            recipients: [],
            cost: null,
            resolved: ['deal_uid' => $row['opportunityUid'], 'state' => $row['state']],
            confirmLabel: 'Open the deal',
        );
    }

    public function fingerprint(AssistantContext $context, array $resolved): string
    {
        $row = $this->deal($context, (string) $resolved['deal_uid']);

        // Changes if the deal moved on (paid, signed, booked, closed) since the preview.
        return hash('sha256', json_encode([$row['opportunityUid'], $row['state']]));
    }

    public function execute(AssistantContext $context, array $resolved): ActionOutcome
    {
        $row = $this->deal($context, (string) $resolved['deal_uid']);
        $url = $this->links->url($context->customer, $context->user, $context->entitlements, 'customer.workspaces.businesses.crm.opportunities.show', array_merge($context->scoped, [$row['opportunityUid']]), ['view_contact'], 'crm');

        if ($url === null) {
            throw new AssistantActionRefused('no_longer_available', 'Deals are no longer available to you, so nothing was opened.');
        }

        return new ActionOutcome('The deal is ready. Nothing has been sent or changed — take the next step there.', $url, ['handoff' => 'deal']);
    }

    /** @return array<string, mixed> the open deal, only if THIS actor can reach it (the reader applies Location access) */
    private function deal(AssistantContext $context, string $ref): array
    {
        $data = $this->revenue->read($context->business, (int) $context->user->id, null, HomeRevenueReader::MAX_CANDIDATES);

        foreach ($data['rows'] as $row) {
            if ($row['opportunityUid'] === $ref || hash_equals(AssistantHandles::for($context, RevenuePipelineTool::KIND, $row['opportunityUid']), $ref)) {
                return $row;
            }
        }

        throw new AssistantActionRefused('deal_not_open', 'That deal is no longer open (or is not one you can see), so there is nothing to open.');
    }
}
