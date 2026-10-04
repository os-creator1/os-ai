<?php

namespace App\Library\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Ads Module V1 contract §2 — the ONE place a request to the Google
 * Ads REST API is built, sent and its failure normalised. The read and
 * mutation clients only supply a path, a body and a row mapper.
 *
 * URL shape: {base_url}/{api_version}/customers/{id}/googleAds:search (and
 * `{resource}:mutate`, `customers:listAccessibleCustomers`). Base URL and
 * version come from GoogleAdsConfig, never from a literal here; the customer
 * id is a normalised 10-digit value; the mutate resource is chosen from a
 * closed list. Nothing database-, provider- or user-supplied is ever
 * fetched as a URL.
 *
 * Headers: `Authorization: Bearer <access token>`; `login-customer-id`
 * (digits only) ONLY when the context carries one; `developer-token` ONLY
 * when one is configured (Google sunset developer tokens on 2026-09-09).
 *
 * Every outbound request is reserved against the per-Business hourly budget
 * first (one per page), so an exhausted budget makes zero provider calls.
 *
 * Failures are normalised to a closed GoogleAdsProviderException
 * classification; no response body, provider message or upstream exception
 * ever escapes. Only Google's machine-readable `error.status` is read, and
 * only the `request-id` response header is logged (never a secret).
 */
final class GoogleAdsHttpTransport
{
    /** The only `{resource}:mutate` endpoints the module may call (contract §2). */
    public const MUTATE_RESOURCES = ['campaigns', 'adGroupCriteria', 'campaignCriteria'];

    public function __construct(
        private readonly GoogleAdsConfig $config,
        private readonly GoogleAdsCallBudget $budget,
    ) {
    }

    /**
     * `GET /{v}/customers:listAccessibleCustomers`.
     *
     * @return array<string, mixed>
     */
    public function listAccessibleCustomers(string $accessToken): array
    {
        $this->budget->reserve();

        try {
            $response = $this->request($accessToken, null)
                ->get($this->versionBase() . '/customers:listAccessibleCustomers');
        } catch (ConnectionException) {
            throw $this->logged(GoogleAdsProviderException::timeout(), 'list_accessible_customers');
        } catch (Throwable) {
            throw $this->logged(GoogleAdsProviderException::providerUnavailable(), 'list_accessible_customers');
        }

        return $this->decode($this->guard($response, 'list_accessible_customers', false));
    }

    /**
     * Runs one GAQL query, following `nextPageToken` within the configured
     * page and row caps. Rows are returned RAW (decoded JSON arrays); the
     * caller maps them. `truncated` is true when a cap was hit while Google
     * still had more.
     *
     * @return GoogleAdsReportResult<array<string, mixed>>
     */
    public function search(GoogleAdsAccessContext $context, string $gaql): GoogleAdsReportResult
    {
        $maxPages = $this->config->maxPagesPerReport();
        $maxRows = $this->config->maxRowsPerReport();

        $rows = [];
        $pageToken = null;
        $pages = 0;
        $truncated = false;

        do {
            $this->budget->reserve();

            $body = ['query' => $gaql];

            if ($pageToken !== null) {
                $body['pageToken'] = $pageToken;
            }

            try {
                $response = $this->request($context->accessToken, $context->loginCustomerId)
                    ->post($this->customerUrl($context->customerId, 'googleAds:search'), $body);
            } catch (ConnectionException) {
                throw $this->logged(GoogleAdsProviderException::timeout(), 'search');
            } catch (Throwable) {
                throw $this->logged(GoogleAdsProviderException::providerUnavailable(), 'search');
            }

            $payload = $this->decode($this->guard($response, 'search', false));
            $pages++;

            foreach (is_array($payload['results'] ?? null) ? $payload['results'] : [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $next = $payload['nextPageToken'] ?? null;
            $pageToken = is_string($next) && $next !== '' ? $next : null;

            if (count($rows) > $maxRows) {
                $rows = array_slice($rows, 0, $maxRows);
                $truncated = true;
                break;
            }

            if ($pageToken !== null && (count($rows) === $maxRows || $pages >= $maxPages)) {
                $truncated = true;
                break;
            }
        } while ($pageToken !== null);

        return new GoogleAdsReportResult($rows, $truncated, $pages);
    }

    /**
     * `POST /{v}/customers/{id}/{resource}:mutate` with exactly ONE
     * operation, `validateOnly=false`, `partialFailure=false`.
     *
     * A connection failure or timeout once the request may have left this
     * process is AMBIGUOUS (the write may have happened): it throws a
     * timeout with isAmbiguous() = true and is never retried here.
     *
     * @param  array<string, mixed>  $operation  one mutate operation (`create` or `update` + `updateMask`)
     * @return string the resource name Google reports for the operation
     */
    public function mutate(GoogleAdsAccessContext $context, string $resource, array $operation): string
    {
        if (! in_array($resource, self::MUTATE_RESOURCES, true)) {
            throw GoogleAdsProviderException::validation();
        }

        $this->budget->reserve();

        try {
            $response = $this->request($context->accessToken, $context->loginCustomerId)
                ->post($this->customerUrl($context->customerId, $resource . ':mutate'), [
                    'operations' => [$operation],
                    'validateOnly' => false,
                    'partialFailure' => false,
                ]);
        } catch (Throwable) {
            // ConnectionException, or anything else once the send began.
            throw $this->logged(GoogleAdsProviderException::timeout(afterMutateSent: true), 'mutate');
        }

        $payload = $this->decodeMutation($this->guard($response, 'mutate', true));
        $resourceName = $payload['results'][0]['resourceName'] ?? null;

        if (! is_string($resourceName) || $resourceName === '') {
            throw $this->logged(GoogleAdsProviderException::unexpectedResponse(afterMutateSent: true), 'mutate');
        }

        return $resourceName;
    }

    /** `{base_url}/{api_version}` */
    private function versionBase(): string
    {
        return $this->config->baseUrl() . '/' . $this->config->apiVersion();
    }

    private function customerUrl(string $customerId, string $suffix): string
    {
        return $this->versionBase() . '/customers/' . $customerId . '/' . $suffix;
    }

    private function request(string $accessToken, ?string $loginCustomerId): PendingRequest
    {
        $headers = [];

        if ($loginCustomerId !== null) {
            $headers['login-customer-id'] = $loginCustomerId;
        }

        $developerToken = $this->config->developerToken();

        if ($developerToken !== null) {
            $headers['developer-token'] = $developerToken;
        }

        return Http::withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->connectTimeout($this->config->connectTimeoutSeconds())
            ->timeout($this->config->requestTimeoutSeconds())
            // Never chase a redirect to another host.
            ->withOptions(['allow_redirects' => false]);
    }

    private function guard(Response $response, string $kind, bool $mutate): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $errorStatus = $this->errorStatus($response);

        $exception = match (true) {
            $status === 429, $errorStatus === 'RESOURCE_EXHAUSTED' => GoogleAdsProviderException::rateLimited(),
            in_array($status, [401, 403], true), in_array($errorStatus, ['UNAUTHENTICATED', 'PERMISSION_DENIED'], true) => GoogleAdsProviderException::accessDenied(),
            $status === 404, $errorStatus === 'NOT_FOUND' => GoogleAdsProviderException::notFound(),
            $status === 400, $errorStatus === 'INVALID_ARGUMENT' => GoogleAdsProviderException::validation(),
            $response->serverError() => GoogleAdsProviderException::providerUnavailable(),
            default => GoogleAdsProviderException::unexpectedResponse(),
        };

        throw $this->logged($exception, $kind, $status, (string) $response->header('request-id'));
    }

    /**
     * Reads ONLY Google's machine-readable `error.status` (e.g.
     * RESOURCE_EXHAUSTED), never the human-readable message, which can echo
     * request content.
     */
    private function errorStatus(Response $response): ?string
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        $status = is_array($body) ? GoogleAdsJson::dig($body, ['error', 'status']) : null;

        return is_string($status) && preg_match('/\A[A-Z_]{1,64}\z/', $status) === 1 ? $status : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable) {
            throw GoogleAdsProviderException::unexpectedResponse();
        }

        if (! is_array($decoded)) {
            throw GoogleAdsProviderException::unexpectedResponse();
        }

        return $decoded;
    }

    /**
     * Like decode(), but an unusable 2xx body after a mutate is AMBIGUOUS
     * (the write probably happened) rather than a plain failure.
     *
     * @return array<string, mixed>
     */
    private function decodeMutation(Response $response): array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable) {
            $decoded = null;
        }

        if (! is_array($decoded)) {
            throw $this->logged(GoogleAdsProviderException::unexpectedResponse(afterMutateSent: true), 'mutate');
        }

        return $decoded;
    }

    private function logged(GoogleAdsProviderException $exception, string $kind, ?int $status = null, string $requestId = ''): GoogleAdsProviderException
    {
        Log::warning('google_ads.request_failed', array_filter([
            'kind' => $kind,
            'classification' => $exception->classification,
            'http_status' => $status,
            'request_id' => $requestId !== '' ? mb_substr($requestId, 0, 64) : null,
        ], static fn ($value) => $value !== null));

        return $exception;
    }
}
