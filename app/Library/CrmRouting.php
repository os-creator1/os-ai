<?php

namespace App\Library;

/**
 * B1 Pass 2 — CRM Business-addressability.
 *
 * Blade views for contact groups/contacts call
 * CrmRouting::route('contacts.show', $uid) instead of
 * route('customer.contacts.show', $uid) directly. When the current
 * request is bound to a Business (i.e. it was dispatched through
 * /workspaces/{workspaceUid}/businesses/{businessUid}/contacts/...), this
 * builds the equivalent customer.workspaces.businesses.<name> URL with
 * the Business context prepended. Otherwise it falls back to the
 * unmodified legacy customer.<name> route, so pages reached through the
 * legacy, non-Business-addressable Contacts routes keep working exactly
 * as before.
 */
class CrmRouting
{
    public static function route(string $name, mixed $parameters = []): string
    {
        $workspaceUid = request()->route('workspaceUid');
        $businessUid  = request()->route('businessUid');

        if ($workspaceUid === null || $businessUid === null) {
            return route('customer.' . $name, $parameters);
        }

        $parameters = is_array($parameters) ? $parameters : [$parameters];

        return route('customer.workspaces.businesses.' . $name, array_merge([
            'workspaceUid' => $workspaceUid,
            'businessUid'  => $businessUid,
        ], $parameters));
    }

    public static function redirectRoute(string $name, mixed $parameters = []): \Illuminate\Http\RedirectResponse
    {
        return redirect(self::route($name, $parameters));
    }
}
