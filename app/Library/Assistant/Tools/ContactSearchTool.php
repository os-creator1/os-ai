<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\AssistantHandles;
use App\Library\Assistant\ToolResult;
use App\Library\Contacts\ContactDirectory;
use App\Library\Dashboard\DashboardLinkGate;

/** Find people in this Business's contacts by name or phone, through the directory's own Location-aware search. */
final class ContactSearchTool implements ReadTool
{
    public function __construct(private readonly ContactDirectory $directory, private readonly DashboardLinkGate $links)
    {
    }

    public function key(): string
    {
        return 'find_contact';
    }

    public function description(): string
    {
        return 'Check whether a contact/lead exists by name or phone: returns how many match and a short label for each (no phone numbers). The customer sees the full details. args: query (string, 2-60 chars).';
    }

    public function rules(): array
    {
        return ['query' => ['required', 'string', 'min:2', 'max:60']];
    }

    public function available(AssistantContext $context): bool
    {
        return $context->can('view_contact');
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        $found = $this->directory->search($context->business, (string) $args['query'], 5, (int) $context->user->id);
        [$workspaceUid, $businessUid] = $context->scoped;
        $links = [];
        $facts = [['label' => 'Matching contacts', 'value' => (string) count($found)]];
        $display = [];

        foreach ($found as $c) {
            $links[] = ['label' => $c['name'] ?: $c['phone'], 'url' => route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $c['uid']])];
            // Full details are for the customer's eyes: facts/links/display never go to the model.
            $facts[] = ['label' => 'Match', 'value' => trim(($c['name'] ?? '') . ' ' . $c['phone'])];
            $display[] = ['name' => $c['name'], 'phone' => $c['phone']];
        }

        return new ToolResult(
            $this->key(),
            ['match_count' => count($found), 'matches' => array_map(fn (array $c): array => ['handle' => AssistantHandles::for($context, 'contact', (string) $c['uid']), 'label' => AssistantHandles::shortLabel($c['name'] ?? null, $c['phone'])], $found)],
            array_slice($facts, 0, 8),
            $links,
            $found === [] ? ['No contact matched that search among the contacts you can see.'] : [],
            true,
            $display,
        );
    }
}
