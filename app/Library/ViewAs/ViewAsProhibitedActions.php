<?php

namespace App\Library\ViewAs;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Contract §5.5 "Prohibited while viewing": changing the payer; funding
 * the wallet; purchasing or releasing a phone resource; changing plan or
 * any Business/location slot; changing Workspace staff; entering or
 * revealing any provider credential; deleting data; starting or switching
 * view-as/context. Matched on route NAME, HTTP method and — for the few
 * routes registered without a name — the controller action; never on the
 * URL text.
 *
 * Every list below is a CLOSED inventory guarded by
 * tests/Feature/Security/ViewAsRouteBoundaryTest.php: adding a route in one
 * of these capability families without listing it here fails that test.
 * The Slice 1A physical-location allocation family
 * (`customer.workspaces.businesses.locations.allocations.*`) is a plan/slot
 * mutation and is listed ahead of its arrival.
 */
final class ViewAsProhibitedActions
{
    /** Exact route names. */
    public const EXACT = [
        // starting or switching view-as / context
        'customer.view-as.start',
        'customer.context.business.switch',
        'customer.context.account.switch',
        'user.switch_view',
        'user.account.login_as',
        // Workspace / account structure, plan, slots
        'customer.workspaces.store',
        'customer.workspaces.rename',
        'customer.workspaces.deactivate',
        'customer.workspaces.reactivate',
        'customer.workspaces.ownership.transfer',
        'customer.workspaces.businesses.store',
        'customer.workspaces.businesses.reassign',
        // phone / keyword resources
        'customer.numbers.release',
        'customer.keywords.release',
        'customer.keywords.remove-mms',
        // provider credentials and provisioning (Google Business Profile)
        'customer.workspaces.businesses.gbp.connect',
        'customer.workspaces.businesses.gbp.disconnect',
        'customer.workspaces.businesses.gbp.refresh',
        'customer.workspaces.businesses.gbp.bind',
        'customer.workspaces.businesses.gbp.unbind',
        'customer.gbp.oauth.callback',
        // API credentials / sending-server configuration
        'customer.developer.generate',
        'customer.developer.server',
        'customer.developer.webhook',
        // deleting data (names that carry no .destroy/.delete suffix)
        'customer.contact.delete-contact-field',
        'customer.workspaces.businesses.contact.delete-contact-field',
        'user.account.delete',
        // funding the actor's own account
        'user.account.top_up',
        'user.account.pay',
    ];

    /** Route-name prefixes (everything under them). */
    public const PREFIXES = [
        'customer.workspaces.members.',
        'customer.workspaces.additional-business-slots.',
        'customer.workspaces.businesses.locations.allocations.',
        'customer.workspaces.businesses.usage-billing.',
        'customer.workspaces.businesses.channels.',
        'customer.workspaces.prospecting.channels.',
        'customer.channels.',
        'customer.subscriptions.',
        'customer.numbers.',
        'customer.senderid.',
        'customer.keywords.',
        'customer.sub_accounts.',
        'customer.top_up.',
        'customer.payment.',
        'customer.callback.',
        'user.callback.',
        'user.registers.',
        'customer.gbp.oauth.',
    ];

    /** GET-only read exceptions inside the prefixes above. */
    public const ALLOWED_READS = [
        'customer.workspaces.businesses.usage-billing.show',
        'customer.subscriptions.index',
        'customer.numbers.index',
        'customer.senderid.index',
        'customer.keywords.index',
    ];

    /** Controller actions of routes registered without a name of their own. */
    public const ACTIONS = [
        'App\Http\Controllers\Customer\KeywordController@payment',
        'App\Http\Controllers\Customer\NumberController@payment',
        'App\Http\Controllers\Customer\SenderIDController@payment',
        'App\Http\Controllers\Customer\SubscriptionController@changePlan',
        'App\Http\Controllers\Customer\SubscriptionController@checkoutPurchase',
        'App\Http\Controllers\Customer\SubscriptionController@renewPost',
        'App\Http\Controllers\User\AccountController@checkoutTopUp',
    ];

    /** Name suffixes that delete data (non-GET only). */
    public const DELETING_SUFFIXES = ['.destroy', '.delete', '.batch_action', '.release'];

    public function isProhibited(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        return $this->isProhibitedRoute($route, $request->getMethod());
    }

    public function isProhibitedRoute(Route $route, string $method): bool
    {
        $method = strtoupper($method);
        $name = $route->getName() ?? '';
        $action = $route->getAction('uses');
        $action = is_string($action) ? ltrim($action, '\\') : '';

        if ($method === 'DELETE') {
            return true;
        }

        if (in_array($name, self::ALLOWED_READS, true) && $method === 'GET') {
            return false;
        }

        if (in_array($name, self::EXACT, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        if ($action !== '' && in_array($action, self::ACTIONS, true)) {
            return true;
        }

        if ($method !== 'GET' && $method !== 'HEAD') {
            foreach (self::DELETING_SUFFIXES as $suffix) {
                if (str_ends_with($name, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function refusalMessage(): string
    {
        return 'That action is not available while you are viewing this client account. Exit the client view to continue.';
    }
}
