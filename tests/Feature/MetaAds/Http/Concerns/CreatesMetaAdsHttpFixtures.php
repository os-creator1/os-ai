<?php

namespace Tests\Feature\MetaAds\Http\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\Customer;
use App\Models\MetaAdsAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\GoogleAds\Reporting\Concerns\SeedsGoogleAdsReportingData;
use Tests\Feature\MetaAds\Concerns\CreatesMetaAdsFixtures;
use Tests\Feature\MetaAds\Reporting\Concerns\SeedsMetaAdsReportingData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Shared setup for the Meta Ads HTTP suites (lane U1): the real tenancy + plan
 * fixtures (Core / Growth / Agency, exactly as the customer-context suites
 * build them), the FAKE Meta provider (and the fake Google provider for the
 * cross-channel suites), and the normalised-row seeders. No real HTTP is ever
 * attempted; queues are faked so a sync request is observable but never run.
 *
 * Clock pinned to 2026-10-04 12:00 UTC (08:00 on the 4th in New York).
 */
trait CreatesMetaAdsHttpFixtures
{
    use CreatesCustomerContextFixtures;
    use CreatesMetaAdsFixtures;
    use SeedsGoogleAdsReportingData;
    use SeedsMetaAdsReportingData;

    protected const META_VIEW = 'view_meta_ads';

    protected const META_MANAGE = 'manage_meta_ads';

    protected const G_VIEW = 'view_google_ads';

    protected const G_MANAGE = 'manage_google_ads';

    protected function prepareMetaHttp(bool $withGoogle = false): void
    {
        Queue::fake();
        $this->pinMetaClock();
        $this->bindFakeMeta();

        if ($withGoogle) {
            $this->bindFakeAds();
        }
    }

    protected function finishMetaHttp(): void
    {
        $this->unpinMetaClock();
    }

    /**
     * A customer owning one Workspace with one active Business on the tier.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function metaHttpTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, string $name = 'Snap Booth Co'): array
    {
        return $this->tenant($tier, $name, $name . ' Workspace');
    }

    /**
     * An active Business whose Workspace has NO plan: nothing is entitled.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function unentitledMetaTenant(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'No Plan Co']));

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        return [$customer, $business->fresh(), Workspace::query()->findOrFail($business->workspace_id)];
    }

    /** @param  array<int, string>  $permissions */
    protected function asMetaUser(Customer $customer, array $permissions = [self::META_VIEW, self::META_MANAGE]): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function metaSelected(Business $business, array $overrides = []): MetaAdsAccount
    {
        return $this->selectedMetaAccount($business, array_merge([
            'result_action_type' => self::RESULT_TYPE,
            'last_successful_sync_at' => now()->subHour(),
            'data_through_date' => '2026-10-03',
        ], $overrides));
    }

    /** One campaign with a daily spend + result row for each day Oct 1-3. */
    protected function seedMetaOctober(MetaAdsAccount $account, int $dailySpendMicros = 26_000_000, string|int|null $results = 2): void
    {
        $campaign = $this->seedMetaCampaign($account, 'Wedding Photo Booth');
        $this->seedMetaDays($account, \App\Enums\MetaAds\MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', $dailySpendMicros, $results);
    }

    /** @param  array<string, mixed>  $query */
    protected function metaPage(Workspace $workspace, Business $business, string $name = 'index', array $query = []): string
    {
        return route('customer.workspaces.businesses.ads.meta.' . $name, array_merge([$workspace->uid, $business->uid], $query));
    }

    protected function channelsUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.ads.overview', [$workspace->uid, $business->uid]);
    }

    /** A pending signed Meta OAuth state for the Business, issued for the actor. */
    protected function metaPendingState(Business $business, int $actorUserId): string
    {
        $connection = \App\Models\BusinessMetaConnection::query()->where('business_id', $business->id)->first()
            ?? \App\Models\BusinessMetaConnection::create([
                'business_id' => $business->id,
                'state' => \App\Enums\MetaAds\MetaConnectionState::Pending,
                'connected_by_user_id' => $actorUserId,
            ]);

        return app(\App\Library\MetaAds\MetaOAuthStateSigner::class)->issue($connection, $actorUserId);
    }

    /** Burns user id 1 (the permission short-circuit) on a platform admin. */
    protected function ensureNotSuperAdmin(): void
    {
        if (User::query()->count() === 0) {
            $this->platformAdminId();
        }
    }
}
