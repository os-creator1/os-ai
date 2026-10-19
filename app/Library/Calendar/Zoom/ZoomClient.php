<?php

namespace App\Library\Calendar\Zoom;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one place that talks to Zoom (OAuth token endpoint and REST API v2). Stateless: callers pass tokens in.
 * Never logs a token, a response body or a meeting URL.
 *
 * Meeting create deliberately DROPS the privileged `start_url`: only `id` and the participant `join_url` leave this class.
 */
class ZoomClient
{
    public function isConfigured(): bool
    {
        return filled(config('zoom.client_id')) && filled(config('zoom.client_secret')) && filled(config('zoom.redirect'))
            && str_starts_with((string) config('zoom.redirect'), 'https://');
    }

    public function authorizeUrl(string $state): string
    {
        return config('zoom.authorize_url').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('zoom.client_id'),
            'redirect_uri' => config('zoom.redirect'),
            'state' => $state,
        ]);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function exchangeCode(string $code): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('zoom.redirect')]);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function refresh(string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** @return array{id: ?string, email: ?string, name: ?string} Best effort: needs user:read:user. */
    public function me(string $accessToken): array
    {
        $body = $this->check($this->api($accessToken)->get('/users/me'))->json() ?? [];

        return [
            'id' => isset($body['id']) ? (string) $body['id'] : null,
            'email' => isset($body['email']) ? (string) $body['email'] : null,
            'name' => trim(($body['first_name'] ?? '').' '.($body['last_name'] ?? '')) ?: null,
        ];
    }

    /**
     * @param  array{topic: string, start_utc: \DateTimeInterface, duration: int, timezone: string, agenda?: string}  $meeting
     * @return array{id: string, join_url: string}
     */
    public function createMeeting(string $accessToken, array $meeting): array
    {
        $response = $this->check($this->api($accessToken)->post('/users/me/meetings', [
            'topic' => mb_substr($meeting['topic'], 0, 200),
            'type' => 2,
            'start_time' => \Illuminate\Support\Carbon::instance($meeting['start_utc'])->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration' => $meeting['duration'],
            'timezone' => $meeting['timezone'],
            'agenda' => mb_substr((string) ($meeting['agenda'] ?? ''), 0, 1000),
            'settings' => (array) config('zoom.meeting_settings', []),
        ]));

        $body = $response->json() ?? [];

        if (! isset($body['id']) || ! ZoomJoinUrl::valid($body['join_url'] ?? null)) {
            // A "success" without a usable participant link is a failure: never store or send it.
            throw new ZoomApiException('permanent', 'invalid_meeting_response');
        }

        return ['id' => (string) $body['id'], 'join_url' => (string) $body['join_url']];
    }

    /**
     * Recovery lookup for a create whose response was lost. Zoom's create call has no idempotency key, so the only way to learn
     * whether it succeeded is to list the host's upcoming scheduled meetings (needs meeting:read:list_meetings) and match ours:
     * by the per-appointment agenda marker, else by identical topic and start. Bounded to a few pages.
     *
     * @param  array{topic: string, start_utc: \DateTimeInterface, agenda: string}  $meeting
     * @return ?array{id: string, join_url: string} null when the host has no such meeting
     */
    public function findScheduledMeeting(string $accessToken, array $meeting): ?array
    {
        $start = \Illuminate\Support\Carbon::instance($meeting['start_utc'])->utc();
        $topic = mb_substr($meeting['topic'], 0, 200);
        $agenda = mb_substr((string) $meeting['agenda'], 0, 1000);
        $pageToken = null;

        for ($page = 0; $page < 5; $page++) {
            $body = $this->check($this->api($accessToken)->get('/users/me/meetings', array_filter([
                'type' => 'scheduled', 'page_size' => 300, 'next_page_token' => $pageToken,
            ])))->json() ?? [];

            foreach ((array) ($body['meetings'] ?? []) as $m) {
                $sameStart = isset($m['start_time']) && \Illuminate\Support\Carbon::parse($m['start_time'])->utc()->equalTo($start);
                $isOurs = (($m['agenda'] ?? null) === $agenda) || (($m['topic'] ?? null) === $topic && $sameStart);

                if ($isOurs && isset($m['id']) && ZoomJoinUrl::valid($m['join_url'] ?? null)) {
                    return ['id' => (string) $m['id'], 'join_url' => (string) $m['join_url']];
                }
            }

            $pageToken = $body['next_page_token'] ?? null;

            if (blank($pageToken)) {
                break;
            }
        }

        return null;
    }

    /** @param  array{topic?: string, start_utc: \DateTimeInterface, duration: int, timezone: string}  $changes */
    public function updateMeeting(string $accessToken, string $meetingId, array $changes): void
    {
        $this->check($this->api($accessToken)->patch('/meetings/'.rawurlencode($meetingId), array_filter([
            'topic' => isset($changes['topic']) ? mb_substr($changes['topic'], 0, 200) : null,
            'start_time' => \Illuminate\Support\Carbon::instance($changes['start_utc'])->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration' => $changes['duration'],
            'timezone' => $changes['timezone'],
        ], fn ($v) => $v !== null)));
    }

    /** A meeting that is already gone counts as deleted. */
    public function deleteMeeting(string $accessToken, string $meetingId): void
    {
        try {
            $this->check($this->api($accessToken)->delete('/meetings/'.rawurlencode($meetingId), [
                'schedule_for_reminder' => 'false',
                'cancel_meeting_reminder' => 'false',
            ]));
        } catch (ZoomApiException $e) {
            if ($e->kind !== 'not_found') {
                throw $e;
            }
        }
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::baseUrl((string) config('zoom.api_base'))
            ->withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('zoom.http_timeout_seconds', 15));
    }

    /** @param  array<string, string>  $form */
    private function token(array $form): array
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth((string) config('zoom.client_id'), (string) config('zoom.client_secret'))
                ->timeout((int) config('zoom.http_timeout_seconds', 15))
                ->post((string) config('zoom.token_url'), $form);
        } catch (ConnectionException) {
            throw new ZoomApiException('transient', 'connection');
        }

        if ($response->status() === 400 || $response->status() === 401) {
            // invalid_grant / invalid_client / revoked: the authorization is gone. Reconnect, never retry.
            throw new ZoomApiException('auth', (string) ($response->json('error') ?: 'invalid_grant'));
        }

        $body = $this->check($response)->json() ?? [];

        if (blank($body['access_token'] ?? null) || blank($body['refresh_token'] ?? null)) {
            throw new ZoomApiException('permanent', 'invalid_token_response');
        }

        return [
            'access_token' => (string) $body['access_token'],
            'refresh_token' => (string) $body['refresh_token'],
            'expires_in' => (int) ($body['expires_in'] ?? 3600),
        ];
    }

    private function check(Response $response): Response
    {
        $status = $response->status();

        if ($response->successful()) {
            return $response;
        }

        $code = (string) ($response->json('code') ?? $status);

        throw match (true) {
            $status === 401 => new ZoomApiException('auth', $code),
            $status === 404 => new ZoomApiException('not_found', $code),
            $status === 429 => new ZoomApiException('rate_limited', $code, max(1, min(3600, (int) $response->header('Retry-After') ?: 60))),
            $status >= 500 => new ZoomApiException('transient', $code),
            default => new ZoomApiException('permanent', $code),
        };
    }
}
