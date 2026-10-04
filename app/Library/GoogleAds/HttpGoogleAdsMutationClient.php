<?php

namespace App\Library\GoogleAds;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsMutationResult;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\Contracts\GoogleAdsMutationClient;

/**
 * Google Ads Module V1 contract §2/§6 — the real MUTATION client: exactly
 * three methods, and the module's whole write surface.
 *
 *   - Pause/resume use `{ "update": {resourceName, status}, "updateMask": "status" }`
 *     with ENABLED / PAUSED only; `REMOVED` is refused before any request.
 *   - A negative keyword is a `create` (the `negative` flag is immutable at
 *     Google) on `campaignCriteria:mutate` (campaign scope) or
 *     `adGroupCriteria:mutate` (ad-group scope).
 *   - Every id is validated as digits and the keyword text as <= 80 chars /
 *     <= 10 words before anything is sent; a bad input is a local
 *     `validation` failure with zero requests (and zero budget) consumed.
 *
 * Ambiguity (a timeout after send) is raised by the transport and propagates
 * untouched; this class never retries.
 */
final class HttpGoogleAdsMutationClient implements GoogleAdsMutationClient
{
    public function __construct(private readonly GoogleAdsHttpTransport $transport)
    {
    }

    public function setCampaignStatus(GoogleAdsAccessContext $context, string $campaignId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult
    {
        $campaign = $this->id($campaignId);
        $this->writable($status);

        $resourceName = 'customers/' . $context->customerId . '/campaigns/' . $campaign;

        return new GoogleAdsMutationResult($this->transport->mutate($context, 'campaigns', [
            'update' => ['resourceName' => $resourceName, 'status' => $status->value],
            'updateMask' => 'status',
        ]));
    }

    public function setKeywordStatus(GoogleAdsAccessContext $context, string $adGroupId, string $criterionId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult
    {
        $adGroup = $this->id($adGroupId);
        $criterion = $this->id($criterionId);
        $this->writable($status);

        $resourceName = 'customers/' . $context->customerId . '/adGroupCriteria/' . $adGroup . '~' . $criterion;

        return new GoogleAdsMutationResult($this->transport->mutate($context, 'adGroupCriteria', [
            'update' => ['resourceName' => $resourceName, 'status' => $status->value],
            'updateMask' => 'status',
        ]));
    }

    public function addNegativeKeyword(GoogleAdsAccessContext $context, GoogleAdsKeywordLevel $scope, string $parentId, string $text, GoogleAdsMatchType $matchType): GoogleAdsMutationResult
    {
        $parent = $this->id($parentId);
        $keywordText = GoogleAdsKeywordText::normalize($text);

        if ($keywordText === null) {
            throw GoogleAdsProviderException::validation();
        }

        $keyword = ['text' => $keywordText, 'matchType' => $matchType->value];

        if ($scope === GoogleAdsKeywordLevel::Campaign) {
            return new GoogleAdsMutationResult($this->transport->mutate($context, 'campaignCriteria', [
                'create' => [
                    'campaign' => 'customers/' . $context->customerId . '/campaigns/' . $parent,
                    'negative' => true,
                    'keyword' => $keyword,
                ],
            ]));
        }

        return new GoogleAdsMutationResult($this->transport->mutate($context, 'adGroupCriteria', [
            'create' => [
                'adGroup' => 'customers/' . $context->customerId . '/adGroups/' . $parent,
                'negative' => true,
                'keyword' => $keyword,
            ],
        ]));
    }

    private function id(string $value): string
    {
        return GoogleAdsJson::id($value) ?? throw GoogleAdsProviderException::validation();
    }

    private function writable(GoogleAdsEntityStatus $status): void
    {
        if (! $status->isWritable()) {
            throw GoogleAdsProviderException::validation();
        }
    }
}
