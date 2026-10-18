<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaMutationResult;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaMutationClient;

/**
 * Meta Ads Module V1 contract §7 — the real WRITE client: pause / resume of a
 * campaign, ad set or ad via `POST /{id}` with `status=PAUSED|ACTIVE`. Nothing
 * else is ever written (no creation, budget, bid, targeting or creative edit).
 *
 * Validation happens BEFORE any request. The transport classifies the outcome:
 * a definite rejection (400/403/404, codes 100/10/200-299/190/803, throttling)
 * is a plain failure; a timeout, dropped connection, 5xx, 408, `is_transient`,
 * codes 1/2 or a 2xx without `success: true` is AMBIGUOUS and never replayed.
 */
final class HttpMetaMutationClient implements MetaMutationClient
{
    public const TARGET_TYPES = ['campaign', 'ad_set', 'ad'];

    public const STATES = ['PAUSED', 'ACTIVE'];

    public function __construct(private readonly MetaGraphTransport $transport)
    {
    }

    public function setStatus(string $accessToken, ?string $adAccountId, string $externalId, string $targetType, string $requestedState): MetaMutationResult
    {
        $id = MetaAdsJson::id($externalId);

        if ($id === null
            || ! in_array($targetType, self::TARGET_TYPES, true)
            || ! in_array($requestedState, self::STATES, true)
            || ($adAccountId !== null && MetaAdsJson::id($adAccountId) === null)) {
            throw MetaProviderException::validation();
        }

        $result = $this->transport->post($id, ['status' => $requestedState], $accessToken, 'set_status_' . $targetType);

        // Graph answers {"success": true}. Anything else after a send is unknown.
        if (($result->body['success'] ?? null) !== true) {
            throw MetaProviderException::unexpectedResponse(afterMutateSent: true);
        }

        return new MetaMutationResult($id, $requestedState);
    }
}
