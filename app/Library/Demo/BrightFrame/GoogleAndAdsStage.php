<?php

namespace App\Library\Demo\BrightFrame;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Google Business Profile, Google Ads and Meta Ads — SIMULATED.
 *
 * Nothing here is a real Google or Meta account. Every connection is marked
 * "active" so the product screens render their connected state, but NO token
 * is stored, so every sync, refresh, mutation and OAuth call refuses (the
 * engines require a stored authorization) and nothing can leave the server.
 * Names carry "[Demo]" / "SIMULATED" so nobody mistakes them for live data.
 */
final class GoogleAndAdsStage implements DemoStage
{
    public function run(DemoContext $context): void
    {
        $this->businessProfile($context);
        $this->googleAds($context);
        $this->metaAds($context);
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    private function businessProfile(DemoContext $context): void
    {
        $business = $context->business;
        $now = now();

        $connectionId = DB::table('business_google_connections')->where('business_id', $business->id)->where('product', 'business_profile')->value('id')
            ?? DB::table('business_google_connections')->insertGetId([
                'uid' => $this->uuid(), 'business_id' => $business->id, 'product' => 'business_profile', 'state' => 'active',
                'refresh_token_encrypted' => null,
                'granted_scopes' => 'https://www.googleapis.com/auth/business.manage',
                'google_account_email' => 'simulated-demo@example.com',
                'connected_at' => $now->copy()->subDays(40), 'last_refreshed_at' => $now->copy()->subDays(1),
                'connected_by_user_id' => $context->user->id, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);

        if (! DB::table('business_google_locations')->where('business_id', $business->id)->exists()) {
            DB::table('business_google_locations')->insert([
                'uid' => $this->uuid(), 'business_google_connection_id' => $connectionId, 'business_id' => $business->id,
                'business_location_id' => $context->location->id,
                'provider_account_resource_name' => 'accounts/SIMULATED-DEMO',
                'provider_location_resource_name' => 'locations/SIMULATED-DEMO-BRIGHTFRAME',
                'bound_title_snapshot' => 'BrightFrame Photo Booths', 'bound_locality_snapshot' => 'Charlotte', 'bound_region_code_snapshot' => 'US',
                'verification_state' => 'verified', 'has_voice_of_merchant' => true, 'has_pending_edits' => false, 'open_status' => 'OPEN',
                'profile_mirror' => json_encode([
                    'title' => 'BrightFrame Photo Booths',
                    'phone_primary' => BrightFrameData::PHONE,
                    'website_uri' => BrightFrameData::WEBSITE,
                    'store_code' => 'DEMO-001',
                    'primary_category_id' => 'gcid:photo_booth',
                    'primary_category_name' => 'Photo booth rental service',
                    'additional_category_ids' => ['gcid:event_planner'],
                    'additional_category_names' => ['Event planner'],
                    'service_area_type' => 'CUSTOMER_AND_BUSINESS_LOCATION',
                    'service_area_place_count' => 12,
                    'latitude' => 35.2271, 'longitude' => -80.8431,
                    'maps_uri' => null, 'new_review_uri' => null,
                    'locality' => 'Charlotte', 'region_code' => 'US',
                ]),
                'mirror_fetched_at' => $now, 'mirror_expires_at' => $now->copy()->addYears(10), 'last_synced_at' => $now->copy()->subHours(6),
                'bound_by_user_id' => $context->user->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $context->note('gbp_bound_locations', DB::table('business_google_locations')->where('business_id', $business->id)->count());
    }

    private function googleAds(DemoContext $context): void
    {
        $business = $context->business;
        $now = now();

        if (DB::table('google_ads_accounts')->where('business_id', $business->id)->exists()) {
            $context->note('google_ads_campaigns', DB::table('google_ads_campaigns')->where('business_id', $business->id)->count());

            return;
        }

        $connectionId = DB::table('business_google_connections')->insertGetId([
            'uid' => $this->uuid(), 'business_id' => $business->id, 'product' => 'google_ads', 'state' => 'active',
            'refresh_token_encrypted' => null, 'granted_scopes' => 'https://www.googleapis.com/auth/adwords',
            'google_account_email' => 'simulated-demo@example.com',
            'connected_at' => $now->copy()->subDays(38), 'last_refreshed_at' => $now->copy()->subDays(1),
            'connected_by_user_id' => $context->user->id, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $through = $now->copy()->subDay()->toDateString();
        $accountId = DB::table('google_ads_accounts')->insertGetId([
            'uid' => $this->uuid(), 'business_id' => $business->id, 'business_google_connection_id' => $connectionId,
            'customer_id' => '5550100142', 'descriptive_name' => 'SIMULATED DEMO: BrightFrame Search Ads', 'currency_code' => 'USD',
            'time_zone' => BrightFrameData::TIMEZONE, 'is_test_account' => true, 'selected_at' => $now, 'selected_by_user_id' => $context->user->id,
            'monthly_budget_target_micros' => 2400 * 1_000_000, 'target_cpl_micros' => 38 * 1_000_000,
            'last_sync_started_at' => $now->copy()->subHours(7), 'last_successful_sync_at' => $now->copy()->subHours(7), 'data_through_date' => $through,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // [campaign name, status, daily budget $, keywords [text, match, QS, base cpc cents, ctr %, conv %]]
        $plan = [
            ['[Demo] Wedding Photo Booth Charlotte', 'ENABLED', 45, [
                ['photo booth rental charlotte', 'PHRASE', 8, 310, 6.4, 9.0], ['wedding photo booth charlotte nc', 'EXACT', 9, 340, 8.1, 11.0],
                ['glam photo booth wedding', 'PHRASE', 7, 285, 5.2, 7.5], ['photo booth hire near me', 'BROAD', 6, 240, 3.9, 5.0],
            ]],
            ['[Demo] Corporate Events and Launches', 'ENABLED', 30, [
                ['corporate photo booth rental', 'PHRASE', 8, 395, 5.8, 8.0], ['360 video booth rental charlotte', 'EXACT', 9, 360, 7.2, 10.0],
                ['branded photo booth for events', 'BROAD', 6, 330, 3.5, 4.5],
            ]],
            ['[Demo] Prom and Graduation (paused)', 'PAUSED', 15, [
                ['prom photo booth rental', 'PHRASE', 7, 260, 4.8, 6.0], ['graduation party photo booth', 'BROAD', 5, 230, 3.2, 3.5],
            ]],
        ];

        $criterion = 9000;
        $campaignN = 0;
        foreach ($plan as [$name, $status, $budget, $keywords]) {
            $campaignN++;
            $externalCampaign = (string) (22000000 + $campaignN);
            $campaignId = DB::table('google_ads_campaigns')->insertGetId([
                'uid' => $this->uuid(), 'business_id' => $business->id, 'google_ads_account_id' => $accountId,
                'external_campaign_id' => $externalCampaign, 'name' => $name, 'status' => $status, 'channel_type' => 'SEARCH',
                'bidding_strategy_type' => 'MAXIMIZE_CONVERSIONS', 'budget_external_id' => (string) (33000000 + $campaignN),
                'budget_amount_micros' => $budget * 1_000_000, 'budget_shared' => false, 'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $groupId = DB::table('google_ads_ad_groups')->insertGetId([
                'business_id' => $business->id, 'google_ads_account_id' => $accountId, 'google_ads_campaign_id' => $campaignId,
                'external_ad_group_id' => (string) (44000000 + $campaignN), 'name' => 'Core terms', 'status' => $status === 'PAUSED' ? 'PAUSED' : 'ENABLED',
                'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $rowsByDay = [];
            foreach ($keywords as $k => [$text, $match, $qs, $cpc, $ctr, $conv]) {
                $criterion++;
                DB::table('google_ads_keywords')->insert([
                    'uid' => $this->uuid(), 'business_id' => $business->id, 'google_ads_account_id' => $accountId, 'google_ads_campaign_id' => $campaignId,
                    'google_ads_ad_group_id' => $groupId, 'external_criterion_id' => (string) $criterion, 'text' => $text, 'match_type' => $match,
                    'status' => $status === 'PAUSED' ? 'PAUSED' : 'ENABLED', 'is_negative' => false, 'level' => 'ad_group', 'quality_score' => $qs,
                    'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $days = $status === 'PAUSED' ? range(30, 59) : range(0, 59);
                foreach ($days as $d) {
                    $date = $now->copy()->subDays($d + 1);
                    mt_srand(crc32("$criterion|$d"));
                    $weekend = in_array($date->dayOfWeekIso, [6, 7], true) ? 1.25 : 1.0;
                    $impressions = (int) ((80 + mt_rand(0, 90)) * (1 + (60 - $d) / 240) * $weekend * (1 + $k * 0.1));
                    $clicks = max(0, (int) round($impressions * $ctr / 100 * (0.8 + mt_rand(0, 40) / 100)));
                    $cost = (int) ($clicks * $cpc * 10_000 * (0.9 + mt_rand(0, 20) / 100));
                    $conversions = round($clicks * $conv / 100 * (0.6 + mt_rand(0, 80) / 100), 2);
                    $row = ['impressions' => $impressions, 'clicks' => $clicks, 'cost_micros' => $cost, 'conversions' => $conversions, 'value' => $conversions * 640];

                    DB::table('google_ads_daily_metrics')->insert([
                        'business_id' => $business->id, 'google_ads_account_id' => $accountId, 'level' => 'keyword', 'entity_key' => (string) $criterion,
                        'metric_date' => $date->toDateString(), 'impressions' => $impressions, 'clicks' => $clicks, 'interactions' => $clicks,
                        'cost_micros' => $cost, 'conversions' => $conversions, 'conversions_value' => $row['value'],
                        'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);

                    $sum = $rowsByDay[$date->toDateString()] ?? ['impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0, 'value' => 0];
                    foreach ($sum as $key => $v) {
                        $sum[$key] = $v + $row[$key];
                    }
                    $rowsByDay[$date->toDateString()] = $sum;
                }
            }

            foreach ($rowsByDay as $date => $sum) {
                DB::table('google_ads_daily_metrics')->insert([
                    'business_id' => $business->id, 'google_ads_account_id' => $accountId, 'level' => 'campaign', 'entity_key' => $externalCampaign,
                    'metric_date' => $date, 'impressions' => $sum['impressions'], 'clicks' => $sum['clicks'], 'interactions' => $sum['clicks'],
                    'cost_micros' => $sum['cost_micros'], 'conversions' => $sum['conversions'], 'conversions_value' => $sum['value'],
                    'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            if ($campaignN === 1) {
                $terms = [
                    ['photo booth rental charlotte nc', 'photo booth rental charlotte', 'PHRASE', 'ADDED', 41, 3, 118, 1.0],
                    ['wedding photo booth near me', 'wedding photo booth charlotte nc', 'EXACT', 'NONE', 28, 2, 79, 1.0],
                    ['cheap photo booth rental', 'photo booth hire near me', 'BROAD', 'NONE', 33, 5, 140, 0.0],
                    ['diy photo booth backdrop', 'photo booth hire near me', 'BROAD', 'NONE', 24, 4, 96, 0.0],
                    ['photo booth jobs charlotte', 'photo booth hire near me', 'BROAD', 'NONE', 19, 3, 71, 0.0],
                    ['mirror photo booth rental cost', 'glam photo booth wedding', 'PHRASE', 'NONE', 22, 2, 66, 1.0],
                ];
                foreach ($terms as [$term, $matched, $match, $targeting, $impr, $clicks, $costCents, $conv]) {
                    DB::table('google_ads_search_terms')->insert([
                        'business_id' => $business->id, 'google_ads_account_id' => $accountId, 'google_ads_campaign_id' => $campaignId,
                        'google_ads_ad_group_id' => $groupId, 'search_term' => $term, 'term_hash' => hash('sha256', $term),
                        'metric_date' => $now->copy()->subDays(3)->toDateString(), 'impressions' => $impr, 'clicks' => $clicks,
                        'cost_micros' => $costCents * 10_000, 'conversions' => $conv, 'conversions_value' => $conv * 640, 'targeting_status' => $targeting,
                        'matched_keyword_text' => $matched, 'matched_keyword_match_type' => $match, 'review_state' => 'unreviewed',
                        'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        DB::table('google_ads_sync_runs')->insert([
            'uid' => $this->uuid(), 'business_id' => $business->id, 'google_ads_account_id' => $accountId, 'state' => 'succeeded', 'trigger' => 'scheduled',
            'scope' => 'full', 'started_at' => $now->copy()->subHours(7), 'completed_at' => $now->copy()->subHours(7)->addMinutes(2),
            'data_through_date' => $through, 'rows_counted' => 1240, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $context->note('google_ads_campaigns', DB::table('google_ads_campaigns')->where('business_id', $business->id)->count());
    }

    private function metaAds(DemoContext $context): void
    {
        $business = $context->business;
        $now = now();

        if (DB::table('meta_ads_accounts')->where('business_id', $business->id)->exists()) {
            $context->note('meta_ads_campaigns', DB::table('meta_ads_campaigns')->where('business_id', $business->id)->count());

            return;
        }

        $connectionId = DB::table('business_meta_connections')->insertGetId([
            'uid' => $this->uuid(), 'business_id' => $business->id, 'state' => 'active', 'access_token_encrypted' => null,
            'granted_scopes' => 'ads_read', 'meta_user_id' => 'SIMULATED-DEMO', 'meta_user_name' => 'Simulated Demo User',
            'connected_at' => $now->copy()->subDays(35), 'last_verified_at' => $now->copy()->subDays(1),
            'connected_by_user_id' => $context->user->id, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $accountId = DB::table('meta_ads_accounts')->insertGetId([
            'uid' => $this->uuid(), 'business_id' => $business->id, 'business_meta_connection_id' => $connectionId, 'ad_account_id' => '5550100142',
            'name' => 'SIMULATED DEMO: BrightFrame Social Ads', 'currency_code' => 'USD', 'time_zone' => BrightFrameData::TIMEZONE, 'account_status' => 1,
            'result_action_type' => 'lead', 'selected_at' => $now, 'selected_by_user_id' => $context->user->id, 'selected_meta_user_id' => 'SIMULATED-DEMO',
            'monthly_budget_target_micros' => 900 * 1_000_000, 'target_cost_per_result_micros' => 22 * 1_000_000,
            'last_sync_started_at' => $now->copy()->subHours(8), 'last_successful_sync_at' => $now->copy()->subHours(8), 'data_through_date' => $now->copy()->subDay()->toDateString(),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $plan = [
            ['[Demo] Spring Wedding Season: Lead Form', 'OUTCOME_LEADS', 20, 'Weddings 25-40, Charlotte +25mi', 'Engaged couples'],
            ['[Demo] Corporate Holiday Parties', 'OUTCOME_LEADS', 14, 'HR and event planners, Charlotte +30mi', 'Event planners'],
        ];

        foreach ($plan as $n => [$name, $objective, $budget, $targeting, $adSetName]) {
            $campaignId = DB::table('meta_ads_campaigns')->insertGetId([
                'uid' => $this->uuid(), 'business_id' => $business->id, 'meta_ads_account_id' => $accountId, 'external_campaign_id' => (string) (66000000 + $n),
                'name' => $name, 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'objective' => $objective, 'daily_budget_minor' => $budget * 100,
                'start_time' => $now->copy()->subDays(50), 'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $adSetId = DB::table('meta_ads_ad_sets')->insertGetId([
                'uid' => $this->uuid(), 'business_id' => $business->id, 'meta_ads_account_id' => $accountId, 'meta_ads_campaign_id' => $campaignId,
                'external_ad_set_id' => (string) (77000000 + $n), 'name' => $adSetName, 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE',
                'daily_budget_minor' => $budget * 100, 'optimization_goal' => 'LEAD_GENERATION', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                'targeting_summary' => $targeting, 'reach_7d' => 5200 + $n * 900, 'frequency_7d' => 1.8 + $n * 0.4, 'frequency_window_end' => $now->copy()->subDay()->toDateString(),
                'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('meta_ads_ads')->insert([
                'uid' => $this->uuid(), 'business_id' => $business->id, 'meta_ads_account_id' => $accountId, 'meta_ads_campaign_id' => $campaignId,
                'meta_ads_ad_set_id' => $adSetId, 'external_ad_id' => (string) (88000000 + $n), 'name' => $n === 0 ? 'Glam booth reel' : 'Holiday party carousel',
                'status' => 'ACTIVE', 'effective_status' => 'ACTIVE',
                'creative_title' => $n === 0 ? 'Your wedding, one frame at a time' : 'Make your holiday party unforgettable',
                'creative_body' => $n === 0 ? 'Book a BrightFrame booth with an attendant and instant sharing.' : 'Branded photo booths for company celebrations.',
                'creative_object_type' => 'VIDEO', 'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach (range(0, 59) as $d) {
                $date = $now->copy()->subDays($d + 1)->toDateString();
                mt_srand(crc32("meta|$n|$d"));
                $spend = (int) ($budget * (0.8 + mt_rand(0, 35) / 100) * 1_000_000);
                $impressions = (int) ($spend / 1_000_000 * (110 + mt_rand(0, 60)));
                $clicks = (int) ($impressions * (0.011 + mt_rand(0, 8) / 1000));
                $results = round($clicks * (0.07 + mt_rand(0, 6) / 100), 2);
                foreach (['campaign' => $campaignId, 'ad_set' => $adSetId] as $level => $entityId) {
                    DB::table('meta_ads_daily_insights')->insert([
                        'business_id' => $business->id, 'meta_ads_account_id' => $accountId, 'level' => $level, 'entity_id' => $entityId, 'metric_date' => $date,
                        'spend_micros' => $spend, 'impressions' => $impressions, 'clicks' => $clicks, 'link_clicks' => (int) ($clicks * 0.8),
                        'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('meta_ads_daily_results')->insert([
                        'business_id' => $business->id, 'meta_ads_account_id' => $accountId, 'level' => $level, 'entity_id' => $entityId, 'metric_date' => $date,
                        'action_type' => 'lead', 'results' => $results, 'result_value' => $results * 640,
                        'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        $context->note('meta_ads_campaigns', DB::table('meta_ads_campaigns')->where('business_id', $business->id)->count());
    }
}
