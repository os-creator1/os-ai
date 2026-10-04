<?php

namespace App\Library\GoogleAds;

use App\Exceptions\GoogleAds\GoogleAdsProviderException;

/**
 * Google Ads Module V1 contract §2 — EVERY GAQL string the module sends lives
 * here and nowhere else, built only from the field names verified against the
 * v25 protos (contract §2 "Resource facts used").
 *
 * Notes that matter:
 *   - GAQL is snake_case (the JSON the API returns is lowerCamelCase).
 *   - `ad_group_criterion` has NO metrics: keyword metrics come from
 *     `keyword_view`.
 *   - Campaign-level negatives come from `campaign_criterion`; ad-group
 *     negatives from `ad_group_criterion WHERE negative = TRUE`. A criterion
 *     that is not a keyword carries no keyword.text and is skipped by the DTO
 *     mapper (no unverified `type` filter is used).
 *   - Search terms come from `search_term_view` with `segments.date`.
 *     The unverified `segments.keyword.info.*` fields are NOT selected.
 *   - `ad_group.id` / `.name` / `.status` and `campaign.id` are the standard
 *     identifiers needed by contract §4; they are the only fields used beyond
 *     the §2 list.
 *
 * Dates are interpolated, so they are validated as real `Y-m-d` dates first
 * (a bad value is a `validation` failure before any request); nothing
 * user-supplied is ever concatenated into a query.
 */
final class GoogleAdsQueries
{
    public static function customer(): string
    {
        return 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, '
            . 'customer.manager, customer.test_account, customer.status FROM customer LIMIT 1';
    }

    public static function managerClients(): string
    {
        return 'SELECT customer_client.id, customer_client.level, customer_client.manager, '
            . 'customer_client.descriptive_name, customer_client.currency_code, customer_client.time_zone, '
            . 'customer_client.status, customer_client.test_account, customer_client.hidden, '
            . 'customer_client.client_customer FROM customer_client';
    }

    public static function campaigns(): string
    {
        return 'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, '
            . 'campaign.bidding_strategy_type, campaign.campaign_budget, campaign_budget.id, '
            . 'campaign_budget.amount_micros, campaign_budget.explicitly_shared FROM campaign';
    }

    public static function adGroups(): string
    {
        return 'SELECT ad_group.id, ad_group.name, ad_group.status, campaign.id FROM ad_group';
    }

    public static function adGroupKeywords(bool $negative): string
    {
        return 'SELECT ad_group_criterion.criterion_id, ad_group_criterion.status, ad_group_criterion.negative, '
            . 'ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, '
            . 'ad_group_criterion.quality_info.quality_score, ad_group.id, campaign.id '
            . 'FROM ad_group_criterion WHERE ad_group_criterion.negative = ' . ($negative ? 'TRUE' : 'FALSE');
    }

    public static function campaignNegativeKeywords(): string
    {
        return 'SELECT campaign_criterion.criterion_id, campaign_criterion.status, campaign_criterion.negative, '
            . 'campaign_criterion.keyword.text, campaign_criterion.keyword.match_type, campaign.id '
            . 'FROM campaign_criterion WHERE campaign_criterion.negative = TRUE';
    }

    public static function dailyCampaignMetrics(string $startDate, string $endDate): string
    {
        return 'SELECT campaign.id, segments.date, ' . self::metrics(true)
            . ' FROM campaign WHERE ' . self::dateRange($startDate, $endDate);
    }

    public static function dailyKeywordMetrics(string $startDate, string $endDate): string
    {
        return 'SELECT ad_group_criterion.criterion_id, ad_group.id, campaign.id, segments.date, ' . self::metrics(true)
            . ' FROM keyword_view WHERE ad_group_criterion.negative = FALSE AND ' . self::dateRange($startDate, $endDate);
    }

    public static function searchTerms(string $startDate, string $endDate): string
    {
        return 'SELECT campaign.id, ad_group.id, search_term_view.search_term, search_term_view.status, '
            . 'segments.date, ' . self::metrics(false)
            . ' FROM search_term_view WHERE ' . self::dateRange($startDate, $endDate);
    }

    private static function metrics(bool $withInteractions): string
    {
        return 'metrics.impressions, metrics.clicks, '
            . ($withInteractions ? 'metrics.interactions, ' : '')
            . 'metrics.cost_micros, metrics.conversions, metrics.conversions_value';
    }

    private static function dateRange(string $startDate, string $endDate): string
    {
        $start = GoogleAdsJson::date($startDate);
        $end = GoogleAdsJson::date($endDate);

        if ($start === null || $end === null || $start > $end) {
            throw GoogleAdsProviderException::validation();
        }

        return "segments.date BETWEEN '" . $start . "' AND '" . $end . "'";
    }
}
