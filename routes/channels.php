<?php

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/*
| Customer Experience Redesign Slice 2B — the Conversations inbox's live
| updates, one private channel per Business.
|
| This replaces the single global `chat` channel, whose callback admitted
| any authenticated user, so every customer could listen to every other
| customer's inbound messages.
|
| A listener is admitted exactly when it could open that Business's inbox.
| The answer comes from the same authorities, in the same order, as
| ChatBoxController::resolveBusiness() — the shared BusinessRouteAccess
| decision for access, the chat_box permission, the Business's status and
| EntitlementManager for `conversations` — so there is no second access
| policy to drift. Keep the two in step.
|
| View-as: this callback runs inside the web middleware group, so the
| current view-as session is available to BusinessRouteAccess here and
| narrows exactly as it does on every Business route — admitting a session
| only for its own exact Workspace+Business target and never one Business
| wider. (ResolveCustomerContext additionally refuses the unclassified
| /broadcasting/auth route while a view is active, so through that endpoint a
| viewing actor gets no live channel at all; /pusher/auth is classified Safe
| and does reach this callback, which is why the narrowing lives here too and
| is defence in depth, never the only line.)
*/
Broadcast::channel('chat.business.{businessUid}', function (User $user, string $businessUid): bool {
    $business = Business::query()->where('uid', $businessUid)->first();
    $workspace = $business?->workspace;

    if ($business === null || $workspace === null || ! $workspace->is_active) {
        return false;
    }

    if (! app(BusinessRouteAccess::class)->actorMayUseBusinessRoute($user, $workspace, $business)) {
        return false;
    }

    if (! Gate::forUser($user)->allows('chat_box') || $business->status !== BusinessStatus::Active) {
        return false;
    }

    try {
        return app(EntitlementManager::class)
            ->decide($workspace, $business, PlatformFeature::Conversations->value, (int) $user->id)
            ->allowed;
    } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
        return false;
    }
});
