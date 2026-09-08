<?php

namespace App\Library\ViewAs;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * The closed inventory that decides what a request may do while a
 * View-as-client session is active (contract §5.5 "Authorization": every
 * check stays constrained to the viewed Business; view-as only narrows).
 *
 * Every authenticated customer route is placed in exactly one class:
 *
 *  1. Prohibited — a §5.5 prohibited action (ViewAsProhibitedActions):
 *     refused with a plain message and audited.
 *  2. Safe — an explicitly listed account-independent route (the actor's
 *     own profile, notifications, announcements, verification, the
 *     landing page, the Exit control). Nothing here reads or writes a
 *     Business.
 *  3. RedirectToViewed — a bare module entry chooser; redirected into the
 *     viewed Business's canonical page rather than allowed to choose.
 *  4. BusinessScoped — carries `businessUid`; allowed only when the
 *     Workspace/Business pair is the viewed one (checked by the middleware).
 *  5. Denied — every other route: legacy user-scoped surfaces (contacts,
 *     conversations, campaigns, templates, blacklists…), Workspace-frame
 *     pages, Business-resolving global pages (Advisor, Business details,
 *     onboarding) and provider surfaces. 404 while viewing.
 *  6. Unclassified — not in any list. The boundary test
 *     (tests/Feature/Security/ViewAsRouteBoundaryTest.php) fails on it, so
 *     a new Business-capable route must be classified deliberately.
 *
 * Safety is never inferred from the absence of a Business parameter:
 * Safe is an explicit, closed list.
 */
final class ViewAsRouteClassification
{
    /** Exact route names that are safe, account-independent behaviour. */
    public const SAFE = [
        'user.home',
        'user.account',
        'user.avatar',
        'user.remove_avatar',
        'user.account.update',
        'user.account.update_information',
        'user.account.change.password',
        'user.account.twofactor.auth',
        'user.account.twofactor.generate_code',
        'user.account.notifications',
        'user.account.notifications.batch_action',
        'user.account.notifications.delete',
        'user.account.notifications.toggle',
        'user.account.announcement',
        'user.account.announcement.view',
        'user.account.announcement.search',
        'user.account.announcement.mark-as-read',
        'user.account.announcement.mark-all-as-read',
        'user.account.announcement.batch_action',
        'user.account.pricing',
        'user.account.pricing-view',
        'user.account.get.units',
        'user.account.dlt-entity-id',
        'user.account.dlt-telemarketer-id',
        'verification.notice',
        'verification.send',
        'verification.verify',
        'pusher.auth',
        'customer.view-as.exit',
        // Ending the authenticated session ends the view (Logout listener);
        // it never addresses a Business.
        'logout',
    ];

    /** Controller actions of safe routes registered without a name. */
    public const SAFE_ACTIONS = [
        'App\Http\Controllers\User\AccountController@updateAvatar',
        'App\Http\Controllers\User\AccountController@updateTwoFactorAuthentication',
        'App\Http\Controllers\User\AccountController@searchPricing',
    ];

    /** Bare module entries → the viewed Business's canonical page. */
    public const REDIRECT_TO_VIEWED = [
        'customer.analytics.entry' => 'customer.workspaces.businesses.analytics.overview',
        'customer.website.index' => 'customer.workspaces.businesses.website.show',
        'customer.gbp.index' => 'customer.workspaces.businesses.gbp.index',
        'customer.automations.index' => 'customer.workspaces.businesses.automations.index',
        'customer.outreach.index' => 'customer.workspaces.businesses.outreach.index',
        'customer.outreach.campaigns.entry' => 'customer.workspaces.businesses.outreach.campaigns',
    ];

    /** Route-name prefixes that are denied (404) while viewing. */
    public const DENIED_PREFIXES = [
        'customer.contacts.',
        'customer.contact.',
        'customer.chatbox.',
        'customer.sms.',
        'customer.mms.',
        'customer.voice.',
        'customer.whatsapp.',
        'customer.viber.',
        'customer.otp.',
        'customer.templates.',
        'customer.tags.',
        'customer.blacklists.',
        'customer.sender-ids.',
        'customer.invoices.',
        'customer.developer.',
        'customer.onboarding.',
        'customer.opportunities.',
        'customer.business.',
        'customer.openai.',
        'customer.prospecting.',
        'customer.workspaces.',
        'customer.channels.',
        'customer.subscriptions.',
        'customer.numbers.',
        'customer.senderid.',
        'customer.keywords.',
        'customer.sub_accounts.',
        'customer.top_up.',
        'customer.payment.',
        'customer.callback.',
        'customer.gbp.oauth.',
        'user.callback.',
        'user.registers.',
    ];

    /** Controller actions of denied routes registered without a name. */
    public const DENIED_ACTIONS = [
        'App\Http\Controllers\Customer\ContactsController@storeImportContact',
    ];

    public function __construct(private readonly ViewAsProhibitedActions $prohibited)
    {
    }

    public function classify(Route $route, string $method = 'GET'): ViewAsRouteClass
    {
        $name = $route->getName() ?? '';
        $action = $route->getAction('uses');
        $action = is_string($action) ? ltrim($action, '\\') : '';

        if ($this->prohibited->isProhibitedRoute($route, $method)) {
            return ViewAsRouteClass::Prohibited;
        }

        if (in_array($name, self::SAFE, true) || ($action !== '' && in_array($action, self::SAFE_ACTIONS, true))) {
            return ViewAsRouteClass::Safe;
        }

        if (array_key_exists($name, self::REDIRECT_TO_VIEWED)) {
            return ViewAsRouteClass::RedirectToViewed;
        }

        if (in_array('businessUid', $route->parameterNames(), true)) {
            return ViewAsRouteClass::BusinessScoped;
        }

        foreach (self::DENIED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return ViewAsRouteClass::Denied;
            }
        }

        if ($action !== '' && in_array($action, self::DENIED_ACTIONS, true)) {
            return ViewAsRouteClass::Denied;
        }

        return ViewAsRouteClass::Unclassified;
    }

    public function classifyByName(string $routeName, string $method = 'GET'): ViewAsRouteClass
    {
        $route = RouteFacade::getRoutes()->getByName($routeName);

        return $route === null ? ViewAsRouteClass::Unclassified : $this->classify($route, $method);
    }

    public function redirectTargetFor(string $routeName): ?string
    {
        return self::REDIRECT_TO_VIEWED[$routeName] ?? null;
    }

    /**
     * A menu entry may be rendered while viewing only when its route is
     * reachable inside the viewed Business.
     */
    public function allowsMenuEntry(string $routeName): bool
    {
        return in_array($this->classifyByName($routeName), [
            ViewAsRouteClass::BusinessScoped,
            ViewAsRouteClass::Safe,
            ViewAsRouteClass::RedirectToViewed,
        ], true);
    }

    /**
     * The routes this inventory must cover: every authenticated customer
     * route — named `customer.*` / `user.*` / `verification.*` /
     * `pusher.auth`, or carrying the `auth` middleware — excluding the admin
     * portal. Shared with the boundary test so the universe cannot drift.
     *
     * @return array<int, Route>
     */
    public static function universe(?RouteCollectionInterface $routes = null): array
    {
        $routes ??= RouteFacade::getRoutes();
        $universe = [];

        foreach ($routes as $route) {
            $name = $route->getName() ?? '';

            if (str_starts_with($name, 'admin.')) {
                continue;
            }

            $middleware = $route->middleware();

            if (str_starts_with($name, 'customer.')
                || str_starts_with($name, 'user.')
                || str_starts_with($name, 'verification.')
                || $name === 'pusher.auth'
                || $name === 'logout'
                || in_array('auth', $middleware, true)) {
                $universe[] = $route;
            }
        }

        return $universe;
    }
}
