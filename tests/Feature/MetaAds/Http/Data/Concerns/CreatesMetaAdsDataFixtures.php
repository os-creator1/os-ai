<?php

namespace Tests\Feature\MetaAds\Http\Data\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\MetaAdsAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\MetaAds\Mutations\Concerns\CreatesMetaMutationFixtures;
use Tests\Feature\MetaAds\Reporting\Concerns\SeedsMetaAdsReportingData;

/**
 * Fixtures for the Meta Ads DATA pages and their pause / resume endpoints.
 *
 * Builds on CreatesMetaMutationFixtures (tenant + plan + ACTIVE connection +
 * Photo Booth mirror, FAKE provider bound by bindFakeMeta) and the reporting
 * seeders (normalised insight / result rows keyed by local ids). The clock is
 * pinned to 2026-10-04 12:00 UTC. Nothing here performs a provider call.
 */
trait CreatesMetaAdsDataFixtures
{
    use CreatesMetaMutationFixtures;
    use SeedsMetaAdsReportingData;

    protected const VIEW = 'view_meta_ads';

    protected const MANAGE = 'manage_meta_ads';

    protected function prepareMetaHttp(): void
    {
        Queue::fake();
        $this->pinMetaClock();
        $this->bindFakeMeta();
    }

    /**
     * A Business on a plan with an ACTIVE connection and a SELECTED account
     * (result type chosen) but NO mirrored entities: the test seeds its own.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace, 3: MetaAdsAccount}
     */
    protected function dataTenant(?WorkspacePlanTier $tier = WorkspacePlanTier::Growth, array $accountOverrides = [], array $connectionOverrides = [], bool $withAccount = true): array
    {
        $this->ensureMetaConfigRowsExist();

        // User id 1 short-circuits permission checks: burn it on a platform admin.
        if (User::query()->count() === 0) {
            $this->createPlatformAdmin();
        }

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Snap Booth Co']));
        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        // A null tier leaves the Workspace with NO plan (nothing entitled).
        if ($tier !== null) {
            app(EntitlementManager::class)->assignFirstPlan(
                $workspace, $tier, $this->createPlatformAdmin(), 'Meta data fixture assignment.', true, 0,
            );
        }

        $business = $business->fresh();
        $account = null;

        if ($withAccount) {
            $this->activeMetaConnection($business, $connectionOverrides);
            $account = $this->selectedMetaAccount($business, array_merge([
                'result_action_type' => self::RESULT_TYPE,
                'last_successful_sync_at' => now()->subHour(),
                'data_through_date' => '2026-10-03',
            ], $accountOverrides));
        }

        return [$customer, $business, $workspace->fresh(), $account];
    }

    /** @param  array<int, string>  $permissions */
    protected function asMetaUser(Customer $customer, array $permissions = [self::VIEW, self::MANAGE]): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    /** @param  array<string, mixed>  $query */
    protected function metaUrl(Workspace $workspace, Business $business, string $name = 'index', array $query = []): string
    {
        return route('customer.workspaces.businesses.ads.meta.' . $name, array_merge([$workspace->uid, $business->uid], $query));
    }

    /** The detail URL of a campaign (the helper takes the uid after the base). */
    protected function metaCampaignUrl(Workspace $workspace, Business $business, string $campaignUid, array $query = []): string
    {
        return route('customer.workspaces.businesses.ads.meta.campaigns.show', array_merge([$workspace->uid, $business->uid, $campaignUid], $query));
    }

    /** @param  array<int, string>  $parameters  @param  array<string, mixed>  $data */
    protected function metaPost(Workspace $workspace, Business $business, string $name, array $parameters = [], array $data = [])
    {
        return $this->post(route('customer.workspaces.businesses.ads.meta.' . $name, array_merge([$workspace->uid, $business->uid], $parameters)), $data);
    }
}
