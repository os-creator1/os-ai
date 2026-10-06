<?php

namespace App\Http\Controllers\API\Concerns;

use App\Models\ContactGroups;
use App\Models\Contacts;

/**
 * The legacy /api/v3 and /api/http contact endpoints bind {group_id} / {uid} by uid with no
 * tenant scope, so a valid token for customer A could otherwise address customer B's group.
 * Every method that receives a bound group (or contact) must prove the token user owns it
 * before doing anything else; a miss is a plain 404, indistinguishable from "not found".
 */
trait AuthorizesOwnedContactGroup
{
    protected function ownedGroupOrAbort(ContactGroups $group, ?\App\Models\User $actor = null): ContactGroups
    {
        // /api/http resolves its actor from a request api_token and passes it in; /api/v3 uses Sanctum.
        $user = $actor ?? request()->user();

        abort_unless(
            $user !== null && $group->customer_id !== null && (int) $group->customer_id === (int) $user->id,
            404
        );

        return $group;
    }

    protected function ownedContactOrAbort(ContactGroups $group, Contacts $contact, ?\App\Models\User $actor = null): Contacts
    {
        $this->ownedGroupOrAbort($group, $actor);

        abort_unless((int) $contact->group_id === (int) $group->id, 404);

        return $contact;
    }
}
