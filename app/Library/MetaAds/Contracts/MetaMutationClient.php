<?php

namespace App\Library\MetaAds\Contracts;

use App\DTO\MetaAds\MetaMutationResult;
use App\Exceptions\MetaAds\MetaProviderException;

/**
 * Meta Ads Module V1 contract §7 — the WRITE half of the provider seam, and
 * the whole of the module's write surface: pause / resume a campaign, ad set
 * or ad. Nothing else is writable.
 *
 * Callers open the ledger operation BEFORE calling and never replay. A mutate
 * that times out, drops, answers 5xx / transient (codes 1, 2) or returns an
 * unusable 2xx throws a MetaProviderException whose isAmbiguous() is true: the
 * outcome is unknown and must be recorded `unknown`, never retried.
 *
 * Anything but `PAUSED`/`ACTIVE`, an unknown target type or a non-numeric id is
 * a `validation` failure raised before any request is made.
 */
interface MetaMutationClient
{
    /**
     * @param  ?string  $adAccountId  the selected ad account (digits); the Fake scopes its data by it. The
     *                                Graph request itself is `POST /{externalId}`.
     * @param  'campaign'|'ad_set'|'ad'  $targetType
     * @param  'PAUSED'|'ACTIVE'  $requestedState
     *
     * @throws MetaProviderException
     */
    public function setStatus(string $accessToken, ?string $adAccountId, string $externalId, string $targetType, string $requestedState): MetaMutationResult;
}
