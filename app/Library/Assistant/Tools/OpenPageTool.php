<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Dashboard\DashboardLinkGate;

/**
 * Navigation. Returns a link the actor's own menu would offer (DashboardLinkGate's four-way rule) — no data is read
 * and nothing is written, so it needs no approval and costs nothing.
 */
final class OpenPageTool implements ReadTool
{
    /** @var array<string, array{0: string, 1: array<int, string>, 2: ?string, 3: string}> */
    private const PAGES = [
        'website' => ['customer.workspaces.businesses.website.show', ['website'], 'website_generation', 'Open your website'],
        'add_contact' => ['customer.workspaces.businesses.people.add', DashboardLinkGate::CONTACT_PERMISSIONS, null, 'Add a contact'],
        'contacts' => ['customer.workspaces.businesses.people.index', DashboardLinkGate::CONTACT_PERMISSIONS, null, 'Open contacts'],
        'conversations' => ['customer.workspaces.businesses.conversations.index', ['chat_box'], 'conversations', 'Open conversations'],
        'seo' => ['customer.workspaces.businesses.seo.index', ['view_seo'], 'seo_basic_visibility', 'Open SEO'],
        'growth' => ['customer.workspaces.businesses.growth.opportunities.index', ['business_advisor'], 'ai_coo_basic', 'Open recommendations'],
        'automations' => ['customer.workspaces.businesses.automations.index', ['automations'], 'automations', 'Open automations'],
        'calendar' => ['customer.workspaces.businesses.calendar.index', ['access_backend'], 'calendar', 'Open calendar'],
        'ads' => ['customer.workspaces.businesses.ads.overview', ['view_google_ads', 'view_meta_ads'], 'ads_basic_visibility', 'Open ads'],
    ];

    public function __construct(private readonly DashboardLinkGate $links)
    {
    }

    public function key(): string
    {
        return 'open_page';
    }

    public function description(): string
    {
        return 'Give the customer a link to a part of MotionGrove. args: page = one of ' . implode(', ', array_keys(self::PAGES)) . '. Use for "take me to…", "add a contact" (contacts are added on that page), "edit my website" (website changes are made in the website editor).';
    }

    public function rules(): array
    {
        return ['page' => ['required', 'string', 'in:' . implode(',', array_keys(self::PAGES))]];
    }

    public function available(AssistantContext $context): bool
    {
        return true;
    }

    public function read(AssistantContext $context, array $args): ToolResult
    {
        [$route, $permissions, $feature, $label] = self::PAGES[$args['page']];
        $url = $this->links->url($context->customer, $context->user, $context->entitlements, $route, $context->scoped, $permissions, $feature);

        if ($url === null) {
            return ToolResult::unavailable($this->key(), 'That page is not available to you (your plan, your role or this view does not include it).');
        }

        return new ToolResult($this->key(), ['page' => $args['page'], 'link_ready' => true], [], [['label' => $label, 'url' => $url]]);
    }
}
