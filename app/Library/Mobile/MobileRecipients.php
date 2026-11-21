<?php

namespace App\Library\Mobile;

use App\Models\Business;
use App\Models\ChatBox;
use App\Models\MobileDevice;
use App\Models\User;
use App\Library\Workspace\LocationAccessGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WHO may be told about a conversation: the single authority for both live events and push.
 *
 * A user qualifies only when ALL hold, evaluated NOW (never from anything cached on the phone):
 *   - an enabled account with at least one live mobile session (token exists, not expired) and a device row;
 *   - MobileBusinessAccess::resolve passes for THIS Business: active Workspace, account not locked, membership/ownership,
 *     the Conversations module visible to them, `chat_box` permission, Business active, `conversations` entitlement;
 *   - if the conversation sits at a Location, the user may reach that Location.
 * Anyone else gets nothing, so the event/push can never cross a tenant, Location or lock boundary.
 */
final class MobileRecipients
{
    public function __construct(private readonly MobileBusinessAccess $access)
    {
    }

    /** @return Collection<int, User> */
    public function users(Business $business, ?ChatBox $box = null): Collection
    {
        $workspace = $business->workspace;

        if ($workspace === null) {
            return collect();
        }

        $userIds = DB::table('mobile_devices as d')
            ->join('personal_access_tokens as t', 't.id', '=', 'd.personal_access_token_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('u.status', true)
            ->where(fn ($q) => $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>', now()))
            ->distinct()->pluck('d.user_id');

        $out = collect();

        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            if ($this->mayReceive($user, $business, $box)) {
                $out->push($user);
            }
        }

        return $out;
    }

    public function mayReceive(User $user, Business $business, ?ChatBox $box = null): bool
    {
        $workspace = $business->workspace;

        if ($workspace === null || ! $user->status) {
            return false;
        }

        try {
            $resolved = $this->access->resolve($user, (string) $workspace->uid, (string) $business->uid, MobileBusinessAccess::FEATURE_CONVERSATIONS, 'chat_box');
        } catch (\Throwable) {
            return false;
        }

        if ($resolved === null) {
            return false;
        }

        if ($box !== null && $box->location_id !== null) {
            $location = $box->location;

            if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) $user->id, $location)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Live devices of one user that carry a push token, joined to a still-valid session.
     *
     * @return Collection<int, MobileDevice>
     */
    public function pushDevicesFor(User $user): Collection
    {
        $ids = DB::table('mobile_devices as d')
            ->join('personal_access_tokens as t', 't.id', '=', 'd.personal_access_token_id')
            ->where('d.user_id', $user->id)
            ->whereNotNull('d.push_token')->where('d.push_token', '!=', '')
            ->where(fn ($q) => $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>', now()))
            ->pluck('d.id');

        return MobileDevice::query()->whereIn('id', $ids)->get();
    }
}
