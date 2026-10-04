<?php

namespace App\Library\Seo\Rank\Provider;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DataForSEO (https://docs.dataforseo.com/v3/) implementation of
 * SeoRankProvider — Standard (normal-priority) queue ONLY.
 *
 * Endpoints (verified against the docs at implementation time):
 *   organic  POST /v3/serp/google/organic/task_post          GET .../task_get/regular/{id}
 *   local    POST /v3/serp/google/local_finder/task_post     GET .../task_get/advanced/{id}
 *   ready    GET  /v3/serp/google/{organic|local_finder}/tasks_ready   (free)
 *   places   GET  /v3/serp/google/locations/{iso}                      (free)
 *
 * NEVER sent: priority 2, live endpoints, postback/pingback, AI-overview or
 * pixel options, `max_crawl_pages`. Keywords carrying search operators are
 * refused locally because the vendor bills them at 5x.
 *
 * Failure classification is deliberately conservative: only an explicit,
 * parsed vendor refusal is Rejected (safe to retry). Anything where a task
 * might exist — timeout, 5xx, unparseable body — is Ambiguous.
 *
 * Credentials come from config only and are never logged or put in an error.
 */
final class DataForSeoRankProvider implements SeoRankProvider
{
    public const KEY = 'dataforseo';

    private const STATUS_OK = 20000;
    private const STATUS_TASK_CREATED = 20100;
    private const STATUS_NO_RESULTS = 40102;
    private const STATUS_AUTH = 40100;
    private const STATUS_PAYMENT = 40200;
    private const STATUS_RATE_LIMIT = 40202;
    private const STATUS_TASK_HANDED = 40601;
    private const STATUS_TASK_IN_QUEUE = 40602;

    private const MAX_ORGANIC_DEPTH = 100;
    private const MAX_LOCAL_DEPTH = 10;

    public function key(): string
    {
        return self::KEY;
    }

    public function submit(SeoRankTaskRequest $request): SeoRankTaskSubmission
    {
        $this->assertRequestSafe($request);

        $client = $this->client();

        try {
            $response = $client->post($this->postPath($request->checkType), [[
                'keyword' => $request->keyword,
                'location_code' => $request->locationCode,
                'language_code' => $request->languageCode,
                'device' => $request->device,
                'depth' => $request->depth,
                'priority' => 1,
                'tag' => $request->tag,
            ]]);
        } catch (Throwable) {
            // Timeout / connection reset / anything else after the request may
            // have left: a task (and a charge) might exist.
            throw SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_UNAVAILABLE);
        }

        $this->assertHttpAcceptable($response);

        $body = $response->json();
        $task = is_array($body) ? ($body['tasks'][0] ?? null) : null;

        if (! is_array($task) || ! isset($task['status_code'])) {
            throw SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_BAD_RESPONSE);
        }

        $status = (int) $task['status_code'];

        if ($status === self::STATUS_TASK_CREATED && is_string($task['id'] ?? null) && $task['id'] !== '') {
            return new SeoRankTaskSubmission($task['id'], $this->micros($task['cost'] ?? null));
        }

        // A vendor-stated refusal inside a 200 envelope: no task exists.
        if ($status >= 40000 && $status < 50000) {
            throw SeoRankProviderException::rejected($this->codeForStatus($status));
        }

        throw SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_BAD_RESPONSE);
    }

    public function fetch(string $checkType, string $taskId): SeoRankTaskResult
    {
        $path = $this->getPath($checkType, $taskId);
        $client = $this->client();

        try {
            $response = $client->get($path);
        } catch (Throwable) {
            throw SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_UNAVAILABLE);
        }

        $this->assertHttpAcceptable($response);

        $body = $response->json();
        $task = is_array($body) ? ($body['tasks'][0] ?? null) : null;

        if (! is_array($task) || ! isset($task['status_code'])) {
            throw SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_BAD_RESPONSE);
        }

        $status = (int) $task['status_code'];

        if ($status === self::STATUS_TASK_IN_QUEUE || $status === self::STATUS_TASK_HANDED) {
            return SeoRankTaskResult::pending();
        }

        $cost = $this->micros($task['cost'] ?? null);

        if ($status === self::STATUS_NO_RESULTS) {
            return new SeoRankTaskResult(SeoRankTaskResult::COMPLETED, [], $cost);
        }

        if ($status !== self::STATUS_OK) {
            return new SeoRankTaskResult(
                SeoRankTaskResult::FAILED,
                [],
                $cost,
                $this->codeForStatus($status, SeoRankProviderException::CODE_TASK_FAILED),
            );
        }

        $result = $task['result'][0] ?? null;

        if (! is_array($result)) {
            // 20000 with no result block = a completed SERP with nothing in it.
            return new SeoRankTaskResult(SeoRankTaskResult::COMPLETED, [], $cost);
        }

        $itemType = $checkType === 'local' ? 'local_pack' : 'organic';
        $items = [];

        foreach ((array) ($result['items'] ?? []) as $raw) {
            if (! is_array($raw) || ($raw['type'] ?? null) !== $itemType) {
                continue;
            }

            $position = $raw['rank_group'] ?? null;

            if (! is_int($position) || $position < 1) {
                continue;
            }

            $items[] = new SeoRankResultItem(
                $position,
                $this->cleanString($raw['domain'] ?? null, 255),
                $this->cleanString($raw['url'] ?? null, 2048),
                $this->cleanString($raw['phone'] ?? null, 64),
                $this->cleanString($raw['cid'] ?? null, 64),
            );
        }

        return new SeoRankTaskResult(SeoRankTaskResult::COMPLETED, $items, $cost);
    }

    public function readyTasksByTag(string $checkType): array
    {
        $client = $this->client();

        try {
            $response = $client->get('/v3/serp/google/' . $this->segment($checkType) . '/tasks_ready');
        } catch (Throwable) {
            throw SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_UNAVAILABLE);
        }

        $this->assertHttpAcceptable($response);

        $ready = [];

        foreach ((array) ($response->json('tasks.0.result') ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                continue;
            }

            $tag = $row['tag'] ?? ($row['data']['tag'] ?? null);

            if (is_string($tag) && $tag !== '') {
                $ready[$tag] = $row['id'];
            }
        }

        return $ready;
    }

    public function locations(string $countryIso): array
    {
        $iso = strtolower($countryIso);

        if (preg_match('/^[a-z]{2}$/', $iso) !== 1) {
            throw SeoRankProviderException::rejected(SeoRankProviderException::CODE_INVALID_REQUEST);
        }

        $client = $this->client(120);

        try {
            $response = $client->get('/v3/serp/google/locations/' . $iso);
        } catch (Throwable) {
            throw SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_UNAVAILABLE);
        }

        $this->assertHttpAcceptable($response);

        $locations = [];

        foreach ((array) ($response->json('tasks.0.result') ?? []) as $row) {
            if (! is_array($row) || ! is_int($row['location_code'] ?? null) || ! is_string($row['location_name'] ?? null)) {
                continue;
            }

            $locations[] = [
                'code' => $row['location_code'],
                'name' => mb_substr($row['location_name'], 0, 255),
                'parent_code' => is_int($row['location_code_parent'] ?? null) ? $row['location_code_parent'] : null,
                'country_iso' => strtoupper((string) ($row['country_iso_code'] ?? $iso)),
                'type' => mb_substr((string) ($row['location_type'] ?? ''), 0, 32),
            ];
        }

        return $locations;
    }

    /** The vendor bills operator queries at 5x; a paid request never carries one. */
    public static function hasSearchOperator(string $keyword): bool
    {
        return preg_match('/["*|]|(^|\s)-\S|\b[a-z]+:|\s(OR|AND)\s/u', $keyword) === 1;
    }

    private function client(int $timeout = 20): PendingRequest
    {
        $login = config('seo.rank_tracking.dataforseo.login');
        $password = config('seo.rank_tracking.dataforseo.password');
        $base = config('seo.rank_tracking.dataforseo.base_url');

        if (! is_string($login) || $login === '' || ! is_string($password) || $password === ''
            || ! is_string($base) || ! str_starts_with($base, 'https://')) {
            throw SeoRankProviderException::rejected(SeoRankProviderException::CODE_NOT_CONFIGURED);
        }

        return Http::baseUrl(rtrim($base, '/'))
            ->withBasicAuth($login, $password)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout(10)
            ->withoutRedirecting();
    }

    private function assertRequestSafe(SeoRankTaskRequest $request): void
    {
        $maxDepth = $request->checkType === 'local' ? self::MAX_LOCAL_DEPTH : self::MAX_ORGANIC_DEPTH;

        $ok = in_array($request->checkType, ['organic', 'local'], true)
            && $request->device === 'mobile'
            && $request->depth >= 1 && $request->depth <= $maxDepth
            && $request->locationCode > 0
            && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $request->languageCode) === 1
            && $request->keyword !== '' && mb_strlen($request->keyword) <= 120
            && ! self::hasSearchOperator($request->keyword);

        if (! $ok) {
            throw SeoRankProviderException::rejected(SeoRankProviderException::CODE_INVALID_REQUEST);
        }
    }

    private function assertHttpAcceptable(Response $response): void
    {
        $code = $response->status();

        if ($code === 200) {
            return;
        }

        if ($code === 401) {
            throw SeoRankProviderException::rejected(SeoRankProviderException::CODE_AUTH);
        }

        if ($code === 402) {
            throw SeoRankProviderException::rejected(SeoRankProviderException::CODE_PAYMENT);
        }

        if ($code === 429) {
            throw SeoRankProviderException::rejected(SeoRankProviderException::CODE_RATE_LIMIT);
        }

        if ($code >= 400 && $code < 500) {
            throw SeoRankProviderException::rejected(SeoRankProviderException::CODE_INVALID_REQUEST);
        }

        throw SeoRankProviderException::ambiguous(SeoRankProviderException::CODE_UNAVAILABLE);
    }

    private function codeForStatus(int $status, string $fallback = SeoRankProviderException::CODE_INVALID_REQUEST): string
    {
        return match ($status) {
            self::STATUS_AUTH => SeoRankProviderException::CODE_AUTH,
            self::STATUS_PAYMENT => SeoRankProviderException::CODE_PAYMENT,
            self::STATUS_RATE_LIMIT => SeoRankProviderException::CODE_RATE_LIMIT,
            default => $fallback,
        };
    }

    private function postPath(string $checkType): string
    {
        return '/v3/serp/google/' . $this->segment($checkType) . '/task_post';
    }

    private function getPath(string $checkType, string $taskId): string
    {
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $taskId) !== 1) {
            throw SeoRankProviderException::rejected(SeoRankProviderException::CODE_INVALID_REQUEST);
        }

        $mode = $checkType === 'local' ? 'advanced' : 'regular';

        return '/v3/serp/google/' . $this->segment($checkType) . '/task_get/' . $mode . '/' . $taskId;
    }

    private function segment(string $checkType): string
    {
        return $checkType === 'local' ? 'local_finder' : 'organic';
    }

    /** USD float -> integer micro-USD. Null when absent or not a sane number. */
    private function micros(mixed $cost): ?int
    {
        if (! is_int($cost) && ! is_float($cost)) {
            return null;
        }

        return ($cost >= 0 && $cost < 100) ? (int) round($cost * 1_000_000) : null;
    }

    private function cleanString(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
