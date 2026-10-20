<?php

namespace App\Library\Voice;

use App\Library\Navigation\CustomerContext;
use App\Models\Business;
use App\Models\VoiceNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Whether the browser-calling panel (and the Call buttons that drive it) should be on a page at all:
 * a signed-in user, inside a Business route, not "viewing as" a client, and calling could exist — the
 * platform's managed voice is on, or this Business has set up a BYO voice number. A cheap, cached
 * UI gate only; every real decision is made server-side on each call.
 */
final class VoiceAvailability
{
    /**
     * Should this user SEE a Call action here at all? Only an authenticated user inside a Business route who
     * holds the Conversations permission and is not "viewing as" a client. Whether the action is usable or
     * disabled-with-a-reason is a separate question (mounted()).
     */
    public static function mayCall(Request $request): bool
    {
        $user = $request->user();

        if (! $user || ! is_string($request->route('workspaceUid')) || ! is_string($request->route('businessUid'))) {
            return false;
        }

        if (app()->bound(CustomerContext::class) && app(CustomerContext::class)->viewAs !== null) {
            return false;
        }

        return Gate::forUser($user)->allows('chat_box');
    }

    /** The reason shown beside a disabled Call action when no calling panel is mounted for this Business. */
    public static function unavailableReason(): string
    {
        return 'Browser calling is not set up for this account yet. Ask an admin to enable a calling number in Voice settings.';
    }

    public static function mounted(Request $request): bool
    {
        $businessUid = $request->route('businessUid');

        if (! self::mayCall($request)) {
            return false;
        }

        return (bool) config('services.telnyx.voice_enabled')
            || Cache::remember(
                'voice:byo:' . $businessUid,
                60,
                fn () => VoiceNumber::query()
                    ->whereIn('business_id', Business::query()->where('uid', $businessUid)->select('id'))
                    ->exists(),
            );
    }
}
