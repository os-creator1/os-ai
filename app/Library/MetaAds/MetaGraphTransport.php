<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaApiUsage;
use App\Exceptions\MetaAds\MetaConfigurationException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaAdsCallCounter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meta Ads Module V1 contract §2 — the ONE place a request to the Graph API is
 * built, sent and its failure normalised. The Http clients only supply a path,
 * a query / form and a row mapper.
 *
 * URL shape: {base_url}/{api_version}/{path}. Base URL and version come from
 * MetaAdsConfig, never a literal here; the path is validated against a closed
 * character set (ids are digits, edges are fixed words). Nothing database-,
 * provider- or user-supplied is ever fetched as a URL: paging is done by
 * building our own `after` parameter and the `paging.next` URL (which embeds
 * the access token) is never followed or exposed.
 *
 * Authentication: `Authorization: Bearer <token>` (never a query parameter)
 * and, ALWAYS, `appsecret_proof = hash_hmac('sha256', token, app_secret)`.
 * Redirects are not followed. Connect / request timeouts come from config.
 *
 * Every outbound request is reserved against the per-Business hourly budget
 * first, so an exhausted budget makes zero provider calls.
 *
 * Failures are normalised to a closed MetaProviderException classification;
 * no response body, provider message, URL or token ever escapes, and only the
 * numeric code / subcode, the transient flag and `fbtrace_id` are read from
 * Graph's error object. Logs carry the same safe facts, never a token / URL.
 */
final class MetaGraphTransport
{
    public function __construct(
        private readonly MetaAdsConfig $config,
        private readonly MetaAdsCallCounter $counter,
    ) {
    }

    /**
     * Authenticated GET.
     *
     * @param  array<string, scalar|null>  $query
     *
     * @throws MetaProviderException|MetaConfigurationException
     */
    public function get(string $path, array $query, string $accessToken, string $kind): MetaGraphResult
    {
        $url = $this->url($path);
        $query['appsecret_proof'] = $this->proof($accessToken);

        $this->counter->reserve();

        try {
            $response = $this->request($accessToken)->get($url, array_filter($query, static fn ($v) => $v !== null));
        } catch (ConnectionException) {
            throw $this->logged(MetaProviderException::timeout(), $kind);
        } catch (Throwable) {
            throw $this->logged(MetaProviderException::providerUnavailable(), $kind);
        }

        return $this->result($response, $kind, false);
    }

    /**
     * Unauthenticated GET for the OAuth token endpoint (client secret in the
     * query, as Graph documents). The query is never logged.
     *
     * @param  array<string, scalar|null>  $query
     *
     * @throws MetaProviderException
     */
    public function getOAuth(string $path, array $query, string $kind): MetaGraphResult
    {
        $url = $this->url($path);

        $this->counter->reserve();

        try {
            $response = $this->request(null)->get($url, $query);
        } catch (ConnectionException) {
            throw $this->logged(MetaProviderException::timeout(), $kind);
        } catch (Throwable) {
            throw $this->logged(MetaProviderException::providerUnavailable(), $kind);
        }

        return $this->result($response, $kind, false);
    }

    /**
     * `POST /{id}` with a form body. Once the request may have left this
     * process any failure that is not a DEFINITE rejection is AMBIGUOUS
     * (timeout with isAmbiguous() = true) and is never retried here.
     *
     * @param  array<string, scalar>  $form
     *
     * @throws MetaProviderException|MetaConfigurationException
     */
    public function post(string $path, array $form, string $accessToken, string $kind): MetaGraphResult
    {
        $url = $this->url($path);
        $form['appsecret_proof'] = $this->proof($accessToken);

        $this->counter->reserve();

        try {
            $response = $this->request($accessToken)->asForm()->post($url, $form);
        } catch (Throwable) {
            // ConnectionException, or anything else once the send began.
            throw $this->logged(MetaProviderException::timeout(afterMutateSent: true), $kind);
        }

        return $this->result($response, $kind, true);
    }

    private function url(string $path): string
    {
        if (preg_match('/\A[A-Za-z0-9_]+(\/[A-Za-z0-9_]+){0,2}\z/', $path) !== 1) {
            throw MetaProviderException::validation();
        }

        return $this->config->versionBase() . '/' . $path;
    }

    /**
     * @throws MetaConfigurationException
     */
    private function proof(string $accessToken): string
    {
        $secret = $this->config->appSecret();

        if ($secret === null) {
            throw MetaConfigurationException::missingAppSecret();
        }

        return hash_hmac('sha256', $accessToken, $secret);
    }

    private function request(?string $accessToken): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout($this->config->connectTimeoutSeconds())
            ->timeout($this->config->requestTimeoutSeconds())
            // Never chase a redirect to another host.
            ->withOptions(['allow_redirects' => false]);

        return $accessToken === null ? $request : $request->withToken($accessToken);
    }

    private function result(Response $response, string $kind, bool $mutate): MetaGraphResult
    {
        $usage = $this->usage($response);

        if (! $response->successful()) {
            throw $this->logged($this->classify($response, $mutate, $usage), $kind, $response->status());
        }

        try {
            $decoded = $response->json();
        } catch (Throwable) {
            $decoded = null;
        }

        if (! is_array($decoded)) {
            throw $this->logged(MetaProviderException::unexpectedResponse(afterMutateSent: $mutate), $kind);
        }

        return new MetaGraphResult($decoded, $usage);
    }

    /**
     * Graph error object -> closed classification (contract §6/§7).
     *
     * For a MUTATION, anything that is not a definite rejection (a 5xx, 408,
     * `is_transient`, codes 1 / 2, an unclassifiable status) is AMBIGUOUS.
     */
    private function classify(Response $response, bool $mutate, ?MetaApiUsage $usage): MetaProviderException
    {
        [$code, $subcode, $transient, $trace] = $this->errorFacts($response);
        $status = $response->status();

        if ($code === 190) {
            return MetaProviderException::invalidToken($subcode, $trace);
        }

        if ($status === 429
            || in_array($code, [4, 17, 32, 613], true)
            || ($code !== null && $code >= 80000 && $code <= 80014)) {
            return MetaProviderException::rateLimited($code, $trace, $usage?->regainSeconds);
        }

        if ($code === 10 || ($code !== null && $code >= 200 && $code <= 299)) {
            return MetaProviderException::accessDenied($code, $subcode, $trace);
        }

        if ($code === 803 || ($code === 100 && $subcode === 33)) {
            return MetaProviderException::notFound($code, $trace);
        }

        if ($code === 100) {
            return MetaProviderException::validation($code, $trace);
        }

        if ($transient || in_array($code, [1, 2], true) || $status >= 500 || $status === 408) {
            return $mutate
                ? MetaProviderException::timeout(afterMutateSent: true, code: $code, fbtraceId: $trace)
                : MetaProviderException::providerUnavailable($code, $trace);
        }

        return match ($status) {
            404 => MetaProviderException::notFound($code, $trace),
            400 => MetaProviderException::validation($code, $trace),
            401, 403 => MetaProviderException::accessDenied($code, $subcode, $trace),
            default => MetaProviderException::unexpectedResponse(afterMutateSent: $mutate, fbtraceId: $trace),
        };
    }

    /**
     * Reads ONLY Graph's machine-readable error facts, never `message` /
     * `error_user_msg` (which can echo request content).
     *
     * @return array{0: ?int, 1: ?int, 2: bool, 3: ?string}
     */
    private function errorFacts(Response $response): array
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return [null, null, false, null];
        }

        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        return [
            MetaAdsJson::unsignedInt($error['code'] ?? null),
            MetaAdsJson::unsignedInt($error['error_subcode'] ?? null),
            ($error['is_transient'] ?? false) === true,
            is_string($error['fbtrace_id'] ?? null) ? $error['fbtrace_id'] : null,
        ];
    }

    /**
     * `X-Business-Use-Case-Usage`: {"<id>":[{call_count,total_time,total_cputime,
     * estimated_time_to_regain_access (MINUTES)}]}; `X-Ad-Account-Usage`:
     * {acc_id_util_pct, reset_time_duration (SECONDS)}. The MAX of every figure
     * seen is kept; null when Meta sent neither header.
     */
    private function usage(Response $response): ?MetaApiUsage
    {
        $call = $time = $cpu = 0.0;
        $regain = null;
        $seen = false;

        $buc = $this->jsonHeader($response, 'X-Business-Use-Case-Usage');

        foreach ($buc as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $seen = true;
                $call = max($call, $this->pct($entry['call_count'] ?? null));
                $time = max($time, $this->pct($entry['total_time'] ?? null));
                $cpu = max($cpu, $this->pct($entry['total_cputime'] ?? null));
                $minutes = MetaAdsJson::unsignedInt($entry['estimated_time_to_regain_access'] ?? null);

                if ($minutes !== null && $minutes > 0) {
                    $regain = max((int) $regain, min($minutes, 100000) * 60);
                }
            }
        }

        $account = $this->jsonHeader($response, 'X-Ad-Account-Usage');

        if ($account !== []) {
            $seen = true;
            $call = max($call, $this->pct($account['acc_id_util_pct'] ?? null));
            $reset = MetaAdsJson::unsignedInt($account['reset_time_duration'] ?? null);

            if ($reset !== null && $reset > 0 && $this->pct($account['acc_id_util_pct'] ?? null) >= 100.0) {
                $regain = max((int) $regain, min($reset, 6_000_000));
            }
        }

        return $seen ? new MetaApiUsage($call, $time, $cpu, $regain) : null;
    }

    /**
     * @return array<string|int, mixed>
     */
    private function jsonHeader(Response $response, string $name): array
    {
        $raw = $response->header($name);

        if ($raw === '' || strlen($raw) > 8192) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function pct(mixed $value): float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            ? max(0.0, min(1000.0, (float) $value))
            : 0.0;
    }

    private function logged(MetaProviderException $exception, string $kind, ?int $status = null): MetaProviderException
    {
        Log::warning('meta_ads.request_failed', array_filter([
            'kind' => $kind,
            'classification' => $exception->classification,
            'http_status' => $status,
            'code' => $exception->providerCode,
            'subcode' => $exception->providerSubcode,
            'fbtrace_id' => $exception->fbtraceId,
        ], static fn ($value) => $value !== null));

        return $exception;
    }
}
