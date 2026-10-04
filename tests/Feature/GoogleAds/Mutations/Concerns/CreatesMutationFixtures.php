<?php

namespace Tests\Feature\GoogleAds\Mutations\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\Customer;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsAdGroup;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsSearchTerm;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleAds\Concerns\CreatesGoogleAdsFixtures;

/**
 * Fixtures for the Google Ads mutation suites. A "tenant" is a Business on a
 * plan, an actor holding (or lacking) manage_google_ads, an ACTIVE google_ads
 * connection, a selected account and a local mirror of the PhotoBooth
 * fixture (what a sync would have written).
 */
trait CreatesMutationFixtures
{
    use CreatesGoogleAdsFixtures;

    /**
     * @return array{business: Business, workspace: Workspace, actor: User, customer: Customer, account: GoogleAdsAccount, connection: BusinessGoogleConnection}
     */
    protected function mutationTenant(
        WorkspacePlanTier $tier = WorkspacePlanTier::Growth,
        bool $canManage = true,
        string $name = 'Snap Booth Co',
    ): array {
        $this->ensureAdsConfigRowsExist();

        // User id 1 short-circuits permission checks: burn it on a platform admin.
        if (User::query()->count() === 0) {
            $this->createPlatformAdmin();
        }

        $customer = $this->createCustomer();
        DB::table('customers')->where('id', $customer->id)->update([
            'permissions' => json_encode($canManage ? ['manage_google_ads', 'view_google_ads'] : ['view_google_ads']),
        ]);

        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => $name]));
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        app(EntitlementManager::class)->assignFirstPlan(
            $workspace, $tier, $this->createPlatformAdmin(), 'Mutation fixture assignment.', true, 0,
        );

        $business = $business->fresh();
        $connection = $this->activeAdsConnection($business);

        $account = GoogleAdsAccount::create([
            'business_id' => $business->id,
            'business_google_connection_id' => $connection->id,
            'customer_id' => PhotoBoothFixture::CUSTOMER_ID,
            'login_customer_id' => PhotoBoothFixture::MANAGER_ID,
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
            'selected_at' => now(),
        ]);

        $this->mirrorFixture($account);

        return [
            'business' => $business,
            'workspace' => $workspace->fresh(),
            'actor' => User::query()->findOrFail($customer->user_id),
            'customer' => $customer,
            'account' => $account,
            'connection' => $connection,
        ];
    }

    protected function createPlatformAdmin(): int
    {
        return User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin',
            'email' => 'platform-admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /** What a sync of the PhotoBooth fixture would have stored locally. */
    protected function mirrorFixture(GoogleAdsAccount $account): void
    {
        $fixture = new PhotoBoothFixture(self::FIXTURE_TODAY);
        $scope = ['business_id' => $account->business_id, 'google_ads_account_id' => $account->id];
        $campaigns = [];
        $adGroups = [];

        foreach ($fixture->campaigns() as $c) {
            $campaigns[$c->externalCampaignId] = GoogleAdsCampaign::create($scope + [
                'external_campaign_id' => $c->externalCampaignId, 'name' => $c->name,
                'status' => $c->status->value, 'last_synced_at' => now(),
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
                'is_negative' => $k->isNegative, 'level' => $k->level->value, 'last_synced_at' => now(),
            ]);
        }
    }

    protected function campaignOf(GoogleAdsAccount $account, string $external = PhotoBoothFixture::CAMPAIGN_RENTAL): GoogleAdsCampaign
    {
        return GoogleAdsCampaign::query()
            ->where('google_ads_account_id', $account->id)->where('external_campaign_id', $external)->firstOrFail();
    }

    protected function adGroupOf(GoogleAdsAccount $account, string $external = '3000000001'): GoogleAdsAdGroup
    {
        return GoogleAdsAdGroup::query()
            ->where('google_ads_account_id', $account->id)->where('external_ad_group_id', $external)->firstOrFail();
    }

    protected function positiveKeywordOf(GoogleAdsAccount $account, string $text = 'photo booth rental'): GoogleAdsKeyword
    {
        return GoogleAdsKeyword::query()
            ->where('google_ads_account_id', $account->id)->where('is_negative', false)->where('text', $text)->firstOrFail();
    }

    protected function negativeOf(GoogleAdsAccount $account, string $text, GoogleAdsKeywordLevel $level = GoogleAdsKeywordLevel::Campaign): ?GoogleAdsKeyword
    {
        return GoogleAdsKeyword::query()
            ->where('google_ads_account_id', $account->id)->where('is_negative', true)
            ->where('level', $level->value)->whereRaw('LOWER(text) = ?', [mb_strtolower($text)])->first();
    }

    protected function searchTermOf(GoogleAdsAccount $account, string $text = 'cheap photo booth'): GoogleAdsSearchTerm
    {
        $campaign = $this->campaignOf($account);
        $adGroup = $this->adGroupOf($account);

        return GoogleAdsSearchTerm::create([
            'business_id' => $account->business_id, 'google_ads_account_id' => $account->id,
            'google_ads_campaign_id' => $campaign->id, 'google_ads_ad_group_id' => $adGroup->id,
            'search_term' => $text, 'term_hash' => GoogleAdsSearchTerm::hashTerm($text),
            'metric_date' => self::FIXTURE_TODAY, 'clicks' => 3, 'cost_micros' => 9_000_000,
        ]);
    }

    /**
     * Simulates the sync that follows a mutation: copies the FAKE provider's
     * current campaign / keyword / negative state into the local mirror and
     * stamps last_synced_at in the future of any recorded operation.
     */
    protected function syncFromFakeProvider(GoogleAdsAccount $account): void
    {
        $stamp = now()->addMinutes(5);
        $customer = (string) $account->customer_id;

        GoogleAdsCampaign::query()->where('google_ads_account_id', $account->id)->get()->each(function (GoogleAdsCampaign $campaign) use ($customer, $stamp): void {
            $status = $this->fakeAds->campaignStatus($customer, (string) $campaign->external_campaign_id);
            $campaign->forceFill(['status' => ($status ?? GoogleAdsEntityStatus::Removed)->value, 'last_synced_at' => $stamp])->save();
        });

        $providerKeywords = $this->fakeAds->keywordsOf($customer);

        foreach ($providerKeywords as $remote) {
            $local = GoogleAdsKeyword::query()
                ->where('google_ads_account_id', $account->id)->where('level', $remote->level->value)
                ->where('external_criterion_id', $remote->externalCriterionId)->first();

            if ($local !== null) {
                $local->forceFill(['status' => $remote->status->value, 'last_synced_at' => $stamp])->save();

                continue;
            }

            $campaign = $this->campaignOf($account, $remote->externalCampaignId);
            $adGroup = $remote->externalAdGroupId === null ? null : $this->adGroupOf($account, $remote->externalAdGroupId);

            GoogleAdsKeyword::create([
                'business_id' => $account->business_id, 'google_ads_account_id' => $account->id,
                'google_ads_campaign_id' => $campaign->id, 'google_ads_ad_group_id' => $adGroup?->id,
                'external_criterion_id' => $remote->externalCriterionId, 'text' => $remote->text,
                'match_type' => $remote->matchType->value, 'status' => $remote->status->value,
                'is_negative' => $remote->isNegative, 'level' => $remote->level->value, 'last_synced_at' => $stamp,
            ]);
        }
    }
}
