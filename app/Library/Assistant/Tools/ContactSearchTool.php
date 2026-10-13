<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
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
        return 'Look up a contact/lead by name or phone. args: query (string, 2-60 chars).';
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

        foreach ($found as $c) {
            $links[] = ['label' => $c['name'] ?: $c['phone'], 'url' => route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $c['uid']])];
        }

        return new ToolResult(
            $this->key(),
            ['matches' => array_map(fn (array $c): array => ['name' => $c['name'], 'phone' => $c['phone']], $found)],
            [['label' => 'Matching contacts', 'value' => (string) count($found)]],
            $links,
            $found === [] ? ['No contact matched that search among the contacts you can see.'] : [],
        );
    }
}
