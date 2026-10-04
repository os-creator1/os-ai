<?php

namespace Tests\Feature\GoogleAds\Http\Data\Concerns;

use App\Library\GoogleAds\PhotoBoothFixture;
use App\Models\Business;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsAdGroup;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsSearchTerm;
use App\Models\Workspace;
use Tests\Feature\GoogleAds\Http\Concerns\CreatesAdsHttpFixtures;

/**
 * Fixtures for the Ads DATA pages and their mutation endpoints. The selected
 * account addresses the PhotoBooth fake provider account (customer id, manager
 * login id) and its campaigns / ad groups / keywords are mirrored locally
 * exactly as a sync would have stored them, so a mutation request reaches
 * FakeGoogleAdsClient with ids it actually knows. Nothing here performs a
 * provider call.
 */
trait CreatesAdsDataFixtures
{
    use CreatesAdsHttpFixtures;

    /** @return array{0: GoogleAdsAccount, 1: array<string, GoogleAdsCampaign>, 2: array<string, GoogleAdsAdGroup>} */
    protected function mirroredAdsAccount(Business $business, array $overrides = []): array
    {
        $account = $this->selectedAdsAccount($business, array_merge([
            'customer_id' => PhotoBoothFixture::CUSTOMER_ID,
            'login_customer_id' => PhotoBoothFixture::MANAGER_ID,
        ], $overrides));

        $fixture = new PhotoBoothFixture(self::FIXTURE_TODAY);
        $scope = ['business_id' => $account->business_id, 'google_ads_account_id' => $account->id];
        $campaigns = [];
        $adGroups = [];

        foreach ($fixture->campaigns() as $c) {
            $campaigns[$c->externalCampaignId] = GoogleAdsCampaign::create($scope + [
                'external_campaign_id' => $c->externalCampaignId, 'name' => $c->name,
                'status' => $c->status->value, 'channel_type' => 'SEARCH',
                'budget_amount_micros' => $c->budgetAmountMicros, 'budget_shared' => false, 'last_synced_at' => now(),
            ]);
        }

        foreach ($fixture->adGroups() as $g) {
            $adGroups[$g->externalAdGroupId] = GoogleAdsAdGroup::create($scope + [
                'google_ads_campaign_id' => $campaigns[$g->externalCampaignId]->id,
                'external_ad_group_id' => $g->externalAdGroupId, 'name' => $g->name,
                'status' => $g->status->value, 'last_synced_at' => now(),
            ]);
        }

        foreach ($fixture->keywords() as $k) {
            GoogleAdsKeyword::create($scope + [
                'google_ads_campaign_id' => $campaigns[$k->externalCampaignId]->id,
                'google_ads_ad_group_id' => $k->externalAdGroupId === null ? null : $adGroups[$k->externalAdGroupId]->id,
                'external_criterion_id' => $k->externalCriterionId, 'text' => $k->text,
                'match_type' => $k->matchType->value, 'status' => $k->status->value,
                'is_negative' => $k->isNegative, 'level' => $k->level->value, 'quality_score' => $k->qualityScore,
                'last_synced_at' => now(),
            ]);
        }

        return [$account, $campaigns, $adGroups];
    }

    protected function rentalCampaign(GoogleAdsAccount $account): GoogleAdsCampaign
    {
        return GoogleAdsCampaign::query()->where('google_ads_account_id', $account->id)
            ->where('external_campaign_id', PhotoBoothFixture::CAMPAIGN_RENTAL)->firstOrFail();
    }

    protected function keywordNamed(GoogleAdsAccount $account, string $text): GoogleAdsKeyword
    {
        return GoogleAdsKeyword::query()->where('google_ads_account_id', $account->id)
            ->where('is_negative', false)->where('text', $text)->firstOrFail();
    }

    /** A cached search term served in the rental campaign's first ad group, over the last few days. */
    protected function seedTerm(GoogleAdsAccount $account, string $term, int $costMicros, int $clicks, ?string $conversions, array $overrides = []): GoogleAdsSearchTerm
    {
        $campaign = $this->rentalCampaign($account);
        $adGroup = GoogleAdsAdGroup::query()->where('google_ads_campaign_id', $campaign->id)->orderBy('id')->firstOrFail();

        return $this->seedSearchTerm($campaign, $adGroup, $term, '2026-10-02', $costMicros, $clicks, $conversions, $overrides);
    }

    /** Spend across the PhotoBooth campaigns for the first days of October (so last_30 has data). */
    protected function seedPhotoBoothMetrics(GoogleAdsAccount $account): void
    {
        $this->seedCampaignDays($account, PhotoBoothFixture::CAMPAIGN_RENTAL, '2026-10-01', '2026-10-04', 6_000_000, 10, '2');
        $this->seedCampaignDays($account, PhotoBoothFixture::CAMPAIGN_360, '2026-10-01', '2026-10-04', 4_000_000, 8, '0');
    }

    /**
     * adsUrl() for the campaign detail route, which needs a uid: the helper
     * returns ".../ads/campaigns" so a test appends "/" + uid (or any
     * hand-written segment, to exercise unknown ids).
     */
    protected function adsUrl(Workspace $workspace, Business $business, string $name = 'index', array $query = []): string
    {
        if ($name === 'campaigns.show') {
            return str_replace('/UID-PLACEHOLDER', '', route('customer.workspaces.businesses.ads.campaigns.show', [$workspace->uid, $business->uid, 'UID-PLACEHOLDER']));
        }

        return route('customer.workspaces.businesses.ads.' . $name, array_merge([$workspace->uid, $business->uid], $query));
    }

    protected function adsPost(Workspace $workspace, Business $business, string $name, array $parameters = [], array $data = [])
    {
        return $this->post(route('customer.workspaces.businesses.ads.' . $name, array_merge([$workspace->uid, $business->uid], $parameters)), $data);
    }
}
