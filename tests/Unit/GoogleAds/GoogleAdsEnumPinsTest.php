<?php

namespace Tests\Unit\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsMetricLevel;
use App\Enums\GoogleAds\GoogleAdsMutationKind;
use App\Enums\GoogleAds\GoogleAdsSearchTermReviewState;
use App\Enums\GoogleAds\GoogleAdsSearchTermStatus;
use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Enums\GoogleAds\LeadAttributionEntrySurface;
use App\Enums\GoogleAds\LeadAttributionSubjectType;
use App\Enums\GoogleAds\LeadAttributionTouchRole;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use PHPUnit\Framework\TestCase;

/**
 * Google Ads Module V1 — the enum values are PERSISTED (and, for the
 * operation types, shared with Business Profile / Search Console rows), so
 * their exact values are pinned. A change here is a data migration.
 */
class GoogleAdsEnumPinsTest extends TestCase
{
    public function test_connection_product_gains_google_ads_without_disturbing_the_others(): void
    {
        $this->assertSame(
            ['business_profile', 'search_console', 'google_ads'],
            array_map(static fn (GoogleConnectionProduct $p): string => $p->value, GoogleConnectionProduct::cases()),
        );
        $this->assertSame('https://www.googleapis.com/auth/adwords', GoogleConnectionProduct::GoogleAds->scope());
        $this->assertSame('https://www.googleapis.com/auth/business.manage', GoogleConnectionProduct::BusinessProfile->scope());
        $this->assertSame('https://www.googleapis.com/auth/webmasters.readonly', GoogleConnectionProduct::SearchConsole->scope());
    }

    public function test_operation_types_are_extended_additively_and_fit_the_column(): void
    {
        $values = array_map(static fn (GoogleOperationType $t): string => $t->value, GoogleOperationType::cases());

        // Existing values keep their exact spelling (rows already persisted).
        foreach ([
            'connect_initiated', 'connect_completed', 'connect_failed', 'token_refreshed', 'disconnected',
            'accounts_enumerated', 'locations_enumerated', 'location_bound', 'location_unbound',
            'mirror_refreshed', 'mirror_purged', 'properties_enumerated', 'property_bound',
            'property_unbound', 'metrics_synced', 'metrics_purged',
        ] as $existing) {
            $this->assertContains($existing, $values);
        }

        $ads = [
            'ads_accounts_listed', 'ads_account_selected', 'ads_sync',
            'ads_campaign_status_changed', 'ads_keyword_status_changed', 'ads_negative_keyword_added',
        ];

        foreach ($ads as $type) {
            $this->assertContains($type, $values);
        }

        $this->assertCount(16 + 6, $values);
        $this->assertSame(count($values), count(array_unique($values)));

        foreach ($values as $value) {
            $this->assertLessThanOrEqual(40, strlen($value), $value . ' must fit operation_type varchar(40)');
        }
    }

    public function test_ads_enum_values_are_pinned(): void
    {
        $this->assertSame(['ENABLED', 'PAUSED', 'REMOVED', 'UNKNOWN'], array_column(GoogleAdsEntityStatus::cases(), 'value'));
        $this->assertSame(['EXACT', 'PHRASE', 'BROAD'], array_column(GoogleAdsMatchType::cases(), 'value'));
        $this->assertSame(['ad_group', 'campaign'], array_column(GoogleAdsKeywordLevel::cases(), 'value'));
        $this->assertSame(['campaign', 'keyword'], array_column(GoogleAdsMetricLevel::cases(), 'value'));
        $this->assertSame(['queued', 'running', 'succeeded', 'partial', 'failed', 'skipped'], array_column(GoogleAdsSyncRunState::cases(), 'value'));
        $this->assertSame(['scheduled', 'manual', 'connect'], array_column(GoogleAdsSyncTrigger::cases(), 'value'));
        $this->assertSame(['unreviewed', 'ignored'], array_column(GoogleAdsSearchTermReviewState::cases(), 'value'));
        $this->assertSame(['ADDED', 'EXCLUDED', 'ADDED_EXCLUDED', 'NONE'], array_column(GoogleAdsSearchTermStatus::cases(), 'value'));
        $this->assertSame(['campaign_status', 'keyword_status', 'negative_keyword'], array_column(GoogleAdsMutationKind::cases(), 'value'));
        $this->assertSame(['public_form', 'website_form', 'booking'], array_column(LeadAttributionEntrySurface::cases(), 'value'));
        $this->assertSame(['first', 'last'], array_column(LeadAttributionTouchRole::cases(), 'value'));
        $this->assertSame(['form_submission', 'website_form_submission', 'appointment'], array_column(LeadAttributionSubjectType::cases(), 'value'));
    }

    public function test_only_pause_and_enable_are_writable_and_unknown_provider_values_are_safe(): void
    {
        $this->assertTrue(GoogleAdsEntityStatus::Enabled->isWritable());
        $this->assertTrue(GoogleAdsEntityStatus::Paused->isWritable());
        $this->assertFalse(GoogleAdsEntityStatus::Removed->isWritable(), '`REMOVED` is never written (contract §6)');
        $this->assertFalse(GoogleAdsEntityStatus::Unknown->isWritable());

        $this->assertSame(GoogleAdsEntityStatus::Paused, GoogleAdsEntityStatus::fromProvider('paused'));
        $this->assertSame(GoogleAdsEntityStatus::Unknown, GoogleAdsEntityStatus::fromProvider('SOMETHING_NEW'));
        $this->assertSame(GoogleAdsEntityStatus::Unknown, GoogleAdsEntityStatus::fromProvider(null));
        $this->assertNull(GoogleAdsMatchType::fromProvider('WEIRD'));
        $this->assertSame(GoogleAdsSearchTermStatus::None, GoogleAdsSearchTermStatus::fromProvider('WEIRD'));
    }

    public function test_each_mutation_kind_maps_to_its_own_ledger_type(): void
    {
        $this->assertSame(GoogleOperationType::AdsCampaignStatusChanged, GoogleAdsMutationKind::CampaignStatus->operationType());
        $this->assertSame(GoogleOperationType::AdsKeywordStatusChanged, GoogleAdsMutationKind::KeywordStatus->operationType());
        $this->assertSame(GoogleOperationType::AdsNegativeKeywordAdded, GoogleAdsMutationKind::NegativeKeyword->operationType());
    }
}
