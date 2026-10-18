<?php

namespace App\Library\GoogleAds\Sync\Stages;

use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\DTO\GoogleAds\GoogleAdsSearchTermData;
use App\Library\GoogleAds\Sync\GoogleAdsStageResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;
use App\Models\GoogleAdsSearchTerm;

/**
 * Per-day search terms (`google_ads_search_terms`), keyed (account, date,
 * ad group, term hash). The owner's `review_state` is deliberately NOT in the
 * update list, so a re-sync never un-ignores a term. Like daily metrics, rows
 * are never deleted.
 */
final class SearchTermsStage extends AbstractGoogleAdsStage
{
    public function key(): string
    {
        return 'search_terms';
    }

    public function fetch(GoogleAdsSyncContext $context): GoogleAdsReportResult
    {
        return $this->client->searchTerms($context->access, $context->searchTermsStart, $context->endDate);
    }

    public function persist(GoogleAdsSyncContext $context, GoogleAdsReportResult $report): GoogleAdsStageResult
    {
        $stamp = $context->stamp();
        $campaigns = $this->idMap('google_ads_campaigns', 'external_campaign_id', $context);
        $adGroups = $this->idMap('google_ads_ad_groups', 'external_ad_group_id', $context);
        $rows = [];

        /** @var GoogleAdsSearchTermData $term */
        foreach ($report->rows as $term) {
            $campaignId = $campaigns[$term->externalCampaignId] ?? null;
            $adGroupId = $adGroups[$term->externalAdGroupId] ?? null;

            if ($campaignId === null || $adGroupId === null) {
                continue;
            }

            $hash = GoogleAdsSearchTerm::hashTerm($term->searchTerm);
            $key = $term->date . '|' . $adGroupId . '|' . $hash;
            $m = $term->metrics;
            $existing = $rows[$key] ?? null;

            $rows[$key] = [
                'business_id' => $context->businessId(),
                'google_ads_account_id' => $context->accountId(),
                'google_ads_campaign_id' => $campaignId,
                'google_ads_ad_group_id' => $adGroupId,
                'search_term' => $existing['search_term'] ?? $term->searchTerm,
                'term_hash' => $hash,
                'metric_date' => $term->date,
                'impressions' => ($existing['impressions'] ?? 0) + $m->impressions,
                'clicks' => ($existing['clicks'] ?? 0) + $m->clicks,
                'cost_micros' => ($existing['cost_micros'] ?? 0) + $m->costMicros,
                'conversions' => $this->addDecimal($existing['conversions'] ?? null, $m->conversions),
                'conversions_value' => $this->addDecimal($existing['conversions_value'] ?? null, $m->conversionsValue),
                'targeting_status' => $existing['targeting_status'] ?? $term->targetingStatus->value,
                'matched_keyword_text' => $existing['matched_keyword_text'] ?? $term->matchedKeywordText,
                'matched_keyword_match_type' => $existing['matched_keyword_match_type'] ?? $term->matchedKeywordMatchType?->value,
                'last_synced_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        $written = $this->upsertChunked(
            'google_ads_search_terms',
            array_values($rows),
            ['google_ads_account_id', 'metric_date', 'google_ads_ad_group_id', 'term_hash'],
            ['google_ads_campaign_id', 'search_term', 'impressions', 'clicks', 'cost_micros', 'conversions', 'conversions_value', 'targeting_status', 'matched_keyword_text', 'matched_keyword_match_type', 'last_synced_at', 'updated_at'],
        );

        return new GoogleAdsStageResult($written, $report->truncated);
    }
}
