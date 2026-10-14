<?php

namespace App\Library\Assistant\Tools;

use App\Library\Assistant\AssistantContext;
use App\Library\Assistant\ToolResult;
use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Dashboard\DashboardStatusReader;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileStatusReader;

/** Website status and the Google Business Profile connection/health per Location the actor may reach. Review text is not read here. */
final class WebsiteAndListingsTool implements ReadTool
{
    public function __construct(
        private readonly DashboardStatusReader $status,
        private readonly GoogleBusinessProfileStatusReader $google,
        private readonly DashboardLinkGate $links,
    ) {
    }

    public function key(): string
    {
        return 'website_and_listings';
    }

    public function description(): string
    {
        return 'Website status (none/draft/live) and Google Business Profile connection and health per location. No args.';
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
        $row = $this->status->forBusiness((int) $context->business->id);
        $data = ['website_status' => $row->websiteStatus ?? 'none', 'google_connection' => $row->googleConnectionState ?? 'not_connected'];
        $facts = [['label' => 'Website', 'value' => $row->websiteStatus === null ? 'Not created yet' : ucfirst(str_replace('_', ' ', $row->websiteStatus))]];
        $limitations = [];
        $links = [];

        $site = $context->action('website');

        if ($site !== null && $site['fullUrl'] !== null) {
            $links[] = ['label' => 'Open your website', 'url' => $site['fullUrl']];
        }

        $locations = $this->google->forBusiness($context->workspace, $context->business, $context->user);

        if ($locations === null) {
            $limitations[] = 'Google Business Profile details are not available to you or not part of your plan.';
        } else {
            $data['google_locations'] = array_map(fn ($l): array => [
                'location' => $l->locationName,
                'connected_listing' => $l->bound,
                'health' => $l->health,
                'details_mismatch_count' => $l->napMismatchCount,
                'out_of_date' => $l->healthIsStale,
            ], array_slice($locations, 0, 6));
            $facts[] = ['label' => 'Google listings connected', 'value' => (string) count(array_filter($locations, fn ($l): bool => $l->bound)) . ' of ' . count($locations)];
            $limitations[] = 'Review text and ratings are not part of this view.';
        }

        return new ToolResult($this->key(), $data, $facts, $links, $limitations);
    }
}
