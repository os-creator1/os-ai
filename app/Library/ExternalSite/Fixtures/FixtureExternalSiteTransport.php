<?php

namespace App\Library\ExternalSite\Fixtures;

use App\Library\ExternalSite\ExternalSiteTransport;
use App\Library\ExternalSite\TransportRequest;
use App\Library\ExternalSite\TransportResponse;

/**
 * An in-memory "internet" for the crawler's tests and the fake-driver browser
 * acceptance: it answers a TransportRequest from a URL map, and it applies the
 * same byte-limit and content-type rules the real cURL transport applies while
 * reading, so those bounds are exercised end to end. It records every request
 * (URL and the pinned IP it was given) so tests can prove what was — and was
 * never — contacted.
 */
final class FixtureExternalSiteTransport implements ExternalSiteTransport
{
    /** @var list<array{url: string, ip: string, host: string}> */
    public array $requests = [];

    /** @param array<string, array{status: int, headers?: array<string, string>, body?: string, error?: string}> $responses */
    public function __construct(private array $responses = [])
    {
        if ($this->responses === []) {
            $this->responses = FixtureSite::responses();
        }
    }

    /** @param array{status: int, headers?: array<string, string>, body?: string, error?: string} $response */
    public function set(string $url, array $response): self
    {
        $this->responses[$url] = $response;

        return $this;
    }

    public function get(TransportRequest $request): TransportResponse
    {
        $url = $request->target->url;
        $this->requests[] = ['url' => $url, 'ip' => $request->target->ip, 'host' => $request->target->host];

        $response = $this->responses[$url] ?? ['status' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><body>Not found</body></html>'];

        if (isset($response['error'])) {
            return new TransportResponse(0, [], '', $response['error'], $request->target->ip);
        }

        $headers = array_change_key_case($response['headers'] ?? [], CASE_LOWER);
        $body = (string) ($response['body'] ?? '');
        $status = (int) $response['status'];

        if ($status >= 200 && $status < 300 && $request->allowedContentTypes !== []) {
            $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));
            $ok = false;

            foreach ($request->allowedContentTypes as $allowed) {
                $ok = $ok || $type === $allowed || str_starts_with($type, $allowed);
            }

            if (! $ok) {
                return new TransportResponse($status, $headers, '', 'content_type_not_allowed', $request->target->ip);
            }
        }

        if (strlen($body) > $request->maxBytes) {
            return new TransportResponse($status, $headers, '', 'too_large', $request->target->ip);
        }

        return new TransportResponse($status, $headers, $body, null, $request->target->ip);
    }

    /** @return list<string> */
    public function requestedUrls(): array
    {
        return array_column($this->requests, 'url');
    }
}
