<?php

namespace App\Library\Dashboard;

use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\MenuEntitlements;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Customer Experience Slice 4 §10 — the ONE rule every dashboard link, band
 * action and attention remediation passes before it renders. The same four
 * conditions Slice 2A's CustomerMenuBuilder::item() applies to the menu:
 *
 *  1. the route is registered and every parameter is present;
 *  2. the actor holds a permission that reaches it (the same Gate strings
 *     the routes enforce);
 *  3. the context allows it — while viewing as a client, only a route the
 *     viewed Business can reach (ViewAsRouteClassification::allowsMenuEntry());
 *  4. the feature is entitled, answered by the request's one MenuEntitlements
 *     snapshot. No second entitlement decision and no query of its own.
 *
 * Hiding is never authorization: every destination controller stays
 * independently fail-closed. This only stops the dashboard offering a link
 * that would 404.
 *
 * The permission lists mirror CustomerMenuBuilder's private constants
 * (Navigation code is outside this slice), so a Dashboard link and the menu
 * entry for the same page are offered to exactly the same actors.
 */
final class DashboardLinkGate
{
    public const OUTREACH_PERMISSIONS = ['sms_quick_send', 'sms_campaign_builder', 'mms_quick_send', 'mms_campaign_builder'];

    public const CONTACT_PERMISSIONS = [
        'view_contact_group', 'create_contact_group', 'update_contact_group', 'delete_contact_group',
        'view_contact', 'create_contact', 'update_contact', 'delete_contact',
    ];

    public function __construct(private readonly ViewAsRouteClassification $viewAsRoutes)
    {
    }

    /**
     * The URL, or null when any of the four conditions fails.
     *
     * @param  array<int, string>  $permissions  any-of
     * @param  array<int, string|null>  $parameters
     */
    public function url(
        CustomerContext $context,
        User $user,
        MenuEntitlements $entitlements,
        string $routeName,
        array $parameters,
        array $permissions,
        ?string $featureKey = null,
    ): ?string {
        if (! Route::has($routeName)) {
            return null;
        }

        foreach ($parameters as $parameter) {
            if ($parameter === null || $parameter === '') {
                return null;
            }
        }

        if ($permissions !== [] && ! Gate::forUser($user)->any($permissions)) {
            return null;
        }

        if ($context->isViewingAsClient() && ! $this->viewAsRoutes->allowsMenuEntry($routeName)) {
            return null;
        }

        if ($featureKey !== null && ! $entitlements->allows($featureKey)) {
            return null;
        }

        return route($routeName, $parameters);
    }
}
