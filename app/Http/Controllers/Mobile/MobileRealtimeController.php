<?php

namespace App\Http\Controllers\Mobile;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Validator;

/**
 * Real-time for the phone over the platform's EXISTING broadcast provider (Pusher protocol). Two endpoints:
 *  - config: the PUBLIC connection parameters (app key, cluster/host). Never the secret or app id.
 *  - auth:   channel authorization for a mobile-scoped token. Only `private-mobile.business.{uid}.user.{id}` is allowed, only
 *            for the caller's own id, and only while MobileRecipients still says they may receive that Business's events.
 */
class MobileRealtimeController extends MobileController
{
    public function config(): JsonResponse
    {
        $enabled = config('broadcasting.default') === 'pusher' && (string) config('broadcasting.connections.pusher.key') !== '';
        $opts = (array) config('broadcasting.connections.pusher.options', []);

        return response()->json(['data' => [
            'enabled' => $enabled,
            'provider' => 'pusher',
            'key' => $enabled ? (string) config('broadcasting.connections.pusher.key') : null,
            'cluster' => $enabled ? ($opts['cluster'] ?? null) : null,
            // A self-hosted Pusher-protocol server (e.g. Soketi) sets PUSHER_HOST; the hosted service leaves it as api-<cluster>.
            'host' => $enabled && ! str_starts_with((string) ($opts['host'] ?? ''), 'api-') ? ($opts['host'] ?? null) : null,
            'port' => $enabled ? (int) ($opts['port'] ?? 443) : null,
            'tls' => ($opts['scheme'] ?? 'https') === 'https',
        ]])->header('Cache-Control', 'no-store');
    }

    public function auth(Request $request): mixed
    {
        $v = Validator::make($request->all(), [
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:200', 'regex:/^private-mobile\.business\.[A-Za-z0-9\-]{8,64}\.user\.[A-Za-z0-9\-]{8,64}$/'],
        ]);

        if ($v->fails() || config('broadcasting.default') !== 'pusher') {
            return $this->fail('forbidden', 'Not allowed.', 403);
        }

        $user = $this->actor($request);
        $request->setUserResolver(fn () => $user);

        try {
            return Broadcast::auth($request);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            // Foreign Business, another user's channel, lock, hidden module: all the same answer.
            return $this->fail('forbidden', 'Not allowed.', 403);
        }
    }
}
