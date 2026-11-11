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
        // Booking types are Location-scoped (calendar/locations/{location}/booking-types), and a Growth target carries no Location,
        // so booking-type problems land on the Business Calendar, which leads into the right Location's setup.
        'booking_types' => 'customer.workspaces.businesses.calendar.index',
        // The Website module's one canonical Business-scoped entry (the sidebar's own); it is state-aware: create, finish or publish.
        'website' => 'customer.workspaces.businesses.website.show',
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

    /**
     * The permission and entitlement the OWNING module's menu entry requires (mirrors CustomerMenuBuilder), so Home offers a direct
     * link only to an actor who could open that module. Hiding is never authorization: every destination re-runs its own chain.
     * `feature` null = no single feature key (Ads is checked as "any Ads entitlement" by the caller).
     */
    private const ACCESS = [
        'crm.board' => ['abilities' => ['view_contact'], 'feature' => 'crm'],
        'conversations' => ['abilities' => ['chat_box'], 'feature' => 'conversations'],
        'calendar' => ['abilities' => ['access_backend'], 'feature' => 'calendar'],
        'booking_types' => ['abilities' => ['access_backend'], 'feature' => 'calendar'],
        'website' => ['abilities' => ['website'], 'feature' => 'website_generation'],
        'ads' => ['abilities' => ['view_google_ads', 'view_meta_ads'], 'feature' => null],
        'forms' => ['abilities' => ['forms'], 'feature' => 'forms'],
        'seo.keywords' => ['abilities' => ['view_seo'], 'feature' => 'seo_basic_visibility'],
        'seo.content' => ['abilities' => ['view_seo'], 'feature' => 'seo_basic_visibility'],
        'seo.audit' => ['abilities' => ['view_seo'], 'feature' => 'seo_basic_visibility'],
        'seo.reviews' => ['abilities' => ['view_seo'], 'feature' => 'seo_module'],
        'seo.citations' => ['abilities' => ['view_seo'], 'feature' => 'seo_module'],
        'documents' => ['abilities' => ['payments_contracts'], 'feature' => 'payments_contracts'],
        'automations' => ['abilities' => ['automations'], 'feature' => 'automations'],
    ];

    /** @return array{route: string, abilities: array<int, string>, feature: ?string}|null null for an unregistered target */
    public static function access(string $target): ?array
    {
        $name = self::ROUTES[$target] ?? null;
        $access = self::ACCESS[$target] ?? null;

        if ($name === null || $access === null || ! \Illuminate\Support\Facades\Route::has($name)) {
            return null;
        }

        return ['route' => $name] + $access;
    }

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
