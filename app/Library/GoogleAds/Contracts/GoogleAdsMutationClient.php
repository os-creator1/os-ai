<?php

namespace App\Library\GoogleAds\Contracts;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsMutationResult;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;

/**
 * Google Ads Module V1 contract §6 — the WRITE half of the provider seam,
 * and the whole of the module's write surface: pause/resume a campaign,
 * pause/resume a keyword, add a negative keyword. Nothing else is writable
 * (never bidding, budgets, targeting, creation, or `REMOVED`).
 *
 * Callers (GoogleAdsMutationService) open the ledger operation BEFORE calling
 * and never replay a mutation. A mutate that times out or drops AFTER being
 * sent throws a GoogleAdsProviderException whose isAmbiguous() is true: the
 * outcome is unknown and must be recorded `unknown`, never retried.
 *
 * The status methods accept only Enabled / Paused; anything else is a
 * `validation` failure raised before any request is made. A keyword's text
 * (<= 80 chars, <= 10 words) is validated the same way.
 */
interface GoogleAdsMutationClient
{
    /**
     * @throws GoogleAdsProviderException
     */
    public function setCampaignStatus(GoogleAdsAccessContext $context, string $campaignId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult;

    /**
     * @param  string  $adGroupId  the keyword's ad group (resource `adGroupCriteria/{adGroupId}~{criterionId}`)
     *
     * @throws GoogleAdsProviderException
     */
    public function setKeywordStatus(GoogleAdsAccessContext $context, string $adGroupId, string $criterionId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult;

    /**
     * @param  GoogleAdsKeywordLevel  $scope  Campaign → `campaignCriteria:mutate`; AdGroup → `adGroupCriteria:mutate`
     * @param  string  $parentId  the campaign id or ad group id the negative attaches to
     *
     * @throws GoogleAdsProviderException
     */
    public function addNegativeKeyword(GoogleAdsAccessContext $context, GoogleAdsKeywordLevel $scope, string $parentId, string $text, GoogleAdsMatchType $matchType): GoogleAdsMutationResult;
}
