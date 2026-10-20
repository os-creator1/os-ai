<?php

namespace App\Library\Voice;

use App\Library\Navigation\CustomerContext;
use App\Models\Business;
use App\Models\VoiceNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the browser-calling panel (and the Call buttons that drive it) should be on a page at all:
 * a signed-in user, inside a Business route, not "viewing as" a client, and calling could exist — the
 * platform's managed voice is on, or this Business has set up a BYO voice number. A cheap, cached
 * UI gate only; every real decision is made server-side on each call.
 */
final class VoiceAvailability
{
    public static function mounted(Request $request): bool
    {
        $workspaceUid = $request->route('workspaceUid');
        $businessUid = $request->route('businessUid');

        if (! $request->user() || ! is_string($workspaceUid) || ! is_string($businessUid)) {
            return false;
        }

        if (app()->bound(CustomerContext::class) && app(CustomerContext::class)->viewAs !== null) {
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
