<?php

namespace Tests\Feature\MetaAds\Mutations\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\Customer;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MetaAds\Concerns\CreatesMetaAdsFixtures;

/**
 * Fixtures for the Meta Ads mutation suites. A "tenant" is a Business on a
 * plan, an actor holding (or lacking) manage_meta_ads, an ACTIVE connection
 * (ads_read + ads_management), a selected account and a local mirror of the
 * Photo Booth fixture (what a sync would have written).
 */
trait CreatesMetaMutationFixtures
{
    use CreatesMetaAdsFixtures;

    /**
     * @return array{business: Business, workspace: Workspace, actor: User, customer: Customer, account: MetaAdsAccount, connection: BusinessMetaConnection}
     */
    protected function mutationTenant(
        WorkspacePlanTier $tier = WorkspacePlanTier::Growth,
        bool $canManage = true,
        string $name = 'Snap Booth Co',
        array $connectionOverrides = [],
    ): array {
        $this->ensureMetaConfigRowsExist();

        // User id 1 short-circuits permission checks: burn it on a platform admin.
        if (User::query()->count() === 0) {
            $this->createPlatformAdmin();
        }

        $customer = $this->createCustomer();
        DB::table('customers')->where('id', $customer->id)->update([
            'permissions' => json_encode($canManage ? ['manage_meta_ads', 'view_meta_ads'] : ['view_meta_ads']),
        ]);

        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => $name]));
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        app(EntitlementManager::class)->assignFirstPlan(
            $workspace, $tier, $this->createPlatformAdmin(), 'Mutation fixture assignment.', true, 0,
        );

        $business = $business->fresh();
        $connection = $this->activeMetaConnection($business, $connectionOverrides);
        $account = $this->selectedMetaAccount($business);

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

    /** What a sync of the Photo Booth fixture would have stored locally. */
    protected function mirrorFixture(MetaAdsAccount $account): void
    {
        $fixture = new MetaPhotoBoothFixture();
        $scope = ['business_id' => $account->business_id, 'meta_ads_account_id' => $account->id];
        $campaigns = [];
        $adSets = [];

        foreach ($fixture->campaigns() as $c) {
            $campaigns[$c->externalCampaignId] = MetaAdsCampaign::create($scope + [
                'external_campaign_id' => $c->externalCampaignId, 'name' => $c->name,
                'status' => $c->status, 'effective_status' => $c->effectiveStatus,
                'objective' => $c->objective, 'last_synced_at' => now(),
            ]);
        }

        foreach ($fixture->adSets() as $s) {
            $adSets[$s->externalAdSetId] = MetaAdsAdSet::create($scope + [
                'meta_ads_campaign_id' => $campaigns[$s->externalCampaignId]->id,
                'external_ad_set_id' => $s->externalAdSetId, 'name' => $s->name,
                'status' => $s->status, 'effective_status' => $s->effectiveStatus, 'last_synced_at' => now(),
            ]);
        }

        foreach ($fixture->ads() as $a) {
            MetaAdsAd::create($scope + [
                'meta_ads_campaign_id' => $campaigns[$a->externalCampaignId]->id,
                'meta_ads_ad_set_id' => $adSets[$a->externalAdSetId]->id,
                'external_ad_id' => $a->externalAdId, 'name' => $a->name,
                'status' => $a->status, 'effective_status' => $a->effectiveStatus, 'last_synced_at' => now(),
            ]);
        }
    }

    protected function campaignOf(MetaAdsAccount $account, string $external = MetaPhotoBoothFixture::CAMPAIGN_LEADS): MetaAdsCampaign
    {
        return MetaAdsCampaign::query()
            ->where('meta_ads_account_id', $account->id)->where('external_campaign_id', $external)->firstOrFail();
    }

    protected function adSetOf(MetaAdsAccount $account, string $external = MetaPhotoBoothFixture::AD_SET_FATIGUED): MetaAdsAdSet
    {
        return MetaAdsAdSet::query()
            ->where('meta_ads_account_id', $account->id)->where('external_ad_set_id', $external)->firstOrFail();
    }

    protected function adOf(MetaAdsAccount $account, string $external = MetaPhotoBoothFixture::AD_WITH_THUMBNAIL): MetaAdsAd
    {
        return MetaAdsAd::query()
            ->where('meta_ads_account_id', $account->id)->where('external_ad_id', $external)->firstOrFail();
    }

    /**
     * Simulates the sync that follows a mutation: copies the FAKE provider's
     * current campaign / ad set / ad status into the local mirror and stamps
     * last_synced_at in the future of any recorded operation.
     */
    protected function syncFromFakeProvider(MetaAdsAccount $account, ?\DateTimeInterface $stamp = null): void
    {
        $stamp ??= now()->addMinutes(5);
        $adAccount = (string) $account->ad_account_id;

        MetaAdsCampaign::query()->where('meta_ads_account_id', $account->id)->get()->each(function (MetaAdsCampaign $c) use ($adAccount, $stamp): void {
            $c->forceFill(['status' => $this->fakeMeta->campaignStatus($adAccount, (string) $c->external_campaign_id) ?? 'DELETED', 'last_synced_at' => $stamp])->save();
        });
        MetaAdsAdSet::query()->where('meta_ads_account_id', $account->id)->get()->each(function (MetaAdsAdSet $s) use ($adAccount, $stamp): void {
            $s->forceFill(['status' => $this->fakeMeta->adSetStatus($adAccount, (string) $s->external_ad_set_id) ?? 'DELETED', 'last_synced_at' => $stamp])->save();
        });
        MetaAdsAd::query()->where('meta_ads_account_id', $account->id)->get()->each(function (MetaAdsAd $a) use ($adAccount, $stamp): void {
            $a->forceFill(['status' => $this->fakeMeta->adStatus($adAccount, (string) $a->external_ad_id) ?? 'DELETED', 'last_synced_at' => $stamp])->save();
        });
    }
}
