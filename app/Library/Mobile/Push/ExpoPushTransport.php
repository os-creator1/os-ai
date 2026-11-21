<?php

namespace App\Library\Mobile\Push;

use Illuminate\Support\Facades\Http;

/** Expo's push service (free, the supported path for Expo apps). Used only when MOBILE_PUSH_ENABLED=true. */
final class ExpoPushTransport implements MobilePushTransport
{
    public function send(array $messages): array
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];

        if (($token = (string) config('mobile.push.expo_access_token')) !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $res = Http::withHeaders($headers)->timeout((int) config('mobile.push.timeout_seconds', 10))
            ->post((string) config('mobile.push.expo_url'), $messages);

        if (! $res->successful()) {
            throw new \RuntimeException('Expo push request failed with HTTP ' . $res->status());
        }

        $tickets = [];

        foreach ((array) $res->json('data', []) as $ticket) {
            $tickets[] = ($ticket['status'] ?? '') === 'ok'
                ? ['status' => 'ok']
                : ['status' => 'error', 'error' => (string) ($ticket['details']['error'] ?? 'unknown')];
        }

        return $tickets;
    }
}
