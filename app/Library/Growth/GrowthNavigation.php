<?php

declare(strict_types=1);

namespace App\Library\Growth;

/**
 * Resolves a rule's symbolic action target to the owning module's own
 * Business-scoped screen. Growth never performs the fix itself and never
 * duplicates a module write: an action is "take the owner to the right place
 * with context", and that module's screen — with its own service, permission,
 * entitlement, Location ACL and confirmation — does the work.
 *
 * A closed map, so a rule can never link somewhere that was not reviewed here.
 */
final class GrowthNavigation
{
    private const ROUTES = [
        'crm.board' => 'customer.workspaces.businesses.crm.board',
        'conversations' => 'customer.workspaces.businesses.conversations.index',
        'calendar' => 'customer.workspaces.businesses.calendar.index',
        'booking_types' => 'customer.workspaces.businesses.booking-types.index',
        'website' => 'customer.workspaces.businesses.website.index',
        'ads' => 'customer.workspaces.businesses.ads.index',
        'forms' => 'customer.workspaces.businesses.forms.index',
        'seo.keywords' => 'customer.workspaces.businesses.seo.keywords.index',
        'seo.content' => 'customer.workspaces.businesses.seo.content.plan',
        'seo.audit' => 'customer.workspaces.businesses.seo.audit.index',
        'seo.reviews' => 'customer.workspaces.businesses.seo.reviews.index',
        'seo.citations' => 'customer.workspaces.businesses.seo.citations.index',
        'documents' => 'customer.workspaces.businesses.documents.index',
        'automations' => 'customer.workspaces.businesses.automations.workflows.index',
    ];

    public static function url(string $target, string $workspaceUid, string $businessUid): ?string
    {
        $name = self::ROUTES[$target] ?? null;

        if ($name === null || ! \Illuminate\Support\Facades\Route::has($name)) {
            return null;
        }

        return route($name, [$workspaceUid, $businessUid]);
    }

    /** @return array<int, string> */
    public static function targets(): array
    {
        return array_keys(self::ROUTES);
    }
}
