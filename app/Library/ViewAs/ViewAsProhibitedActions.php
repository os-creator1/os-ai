<?php

namespace App\Library\ViewAs;

use Illuminate\Http\Request;

/**
 * Contract §5.5 "Prohibited while viewing": changing the payer; funding
 * the wallet; purchasing or releasing a phone number; changing plan or
 * slots; changing Workspace staff; entering or revealing any provider
 * credential; deleting data; starting another view-as. Matched on route
 * NAME (and, for deletions, on the HTTP method), never on the URL text.
 *
 * View-as may only narrow what the real actor could do, so this list is
 * deliberately broad: anything that spends, provisions, re-scopes staff or
 * destroys data is refused, audited, and explained in plain language.
 */
final class ViewAsProhibitedActions
{
    /** Exact route names. */
    private const EXACT = [
        'customer.view-as.start',
        'customer.context.business.switch',
        'customer.workspaces.store',
        'customer.workspaces.rename',
        'customer.workspaces.deactivate',
        'customer.workspaces.reactivate',
        'customer.workspaces.ownership.transfer',
        'customer.workspaces.businesses.store',
        'customer.workspaces.businesses.reassign',
        'customer.numbers.release',
        'customer.keywords.release',
    ];

    /** Route-name prefixes (everything under them). */
    private const PREFIXES = [
        'customer.workspaces.members.',
        'customer.workspaces.additional-business-slots.',
        'customer.workspaces.businesses.usage-billing.',
        'customer.workspaces.businesses.channels.',
        'customer.channels.',
        'customer.subscriptions.',
        'customer.numbers.',
        'customer.senderid.',
        'customer.keywords.',
        'customer.sub_accounts.',
    ];

    /** Read-only exceptions inside the prefixes above. */
    private const ALLOWED_READS = [
        'customer.workspaces.businesses.usage-billing.show',
        'customer.subscriptions.index',
        'customer.numbers.index',
        'customer.senderid.index',
        'customer.keywords.index',
    ];

    /** Name suffixes that delete data. */
    private const DELETING_SUFFIXES = ['.destroy', '.delete', '.batch_action', '.release'];

    public function isProhibited(Request $request): bool
    {
        $route = $request->route();
        $name = $route?->getName();

        if ($request->isMethod('DELETE')) {
            return true;
        }

        if ($name === null || $name === '') {
            return false;
        }

        if (in_array($name, self::ALLOWED_READS, true) && $request->isMethod('GET')) {
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

        if (! $request->isMethod('GET')) {
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
