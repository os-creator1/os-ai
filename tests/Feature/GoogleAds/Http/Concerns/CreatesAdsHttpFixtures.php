<?php

namespace Tests\Feature\GoogleAds\Http\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\Customer;
use App\Models\GoogleAdsAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\GoogleAds\Reporting\Concerns\SeedsGoogleAdsReportingData;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;

/**
 * Shared setup for the Ads HTTP suites: the real tenancy + plan fixtures
 * (Core / Growth, exactly as the GBP and SEO suites build them), the FAKE
 * Google Ads provider, and the normalised-row seeders. No real HTTP is ever
 * attempted; queues are faked so a sync request is observable but never run.
 */
trait CreatesAdsHttpFixtures
{
    use CreatesGoogleBusinessProfileFixtures;
    use SeedsGoogleAdsReportingData;

    protected const VIEW = 'view_google_ads';

    protected const MANAGE = 'manage_google_ads';

    protected function prepareAdsHttp(): void
    {
        Queue::fake();
        $this->pinAdsClock();
        $this->bindFakeAds();
    }

    /** @return array{0: Customer, 1: Business, 2: Workspace} */
    protected function adsHttpTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Growth): array
    {
        return $this->entitledTenant($tier);
    }

    /**
     * A Business that is active but whose Workspace has NO plan assigned, so
     * neither Ads feature is entitled.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function unentitledAdsTenant(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'No Plan Co']));

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        return [$customer, $business->fresh(), Workspace::query()->findOrFail($business->workspace_id)];
    }

    protected function asAdsUser(Customer $customer, array $permissions = [self::VIEW, self::MANAGE]): void
    {
        $this->authenticateAsCustomer($customer, $permissions);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function selectedAdsAccount(Business $business, array $overrides = []): GoogleAdsAccount
    {
        return $this->adsAccountFor($business, array_merge([
            'descriptive_name' => 'Snap Booth Ads',
            'last_successful_sync_at' => now()->subHour(),
            'data_through_date' => now()->subDay()->format('Y-m-d'),
        ], $overrides));
    }

    /** One campaign with a daily spend/conversion row for every day of October so far. */
    protected function seedOctoberData(GoogleAdsAccount $account, int $dailyCostMicros = 26_000_000, ?string $conversions = '2'): void
    {
        $campaign = $this->seedCampaign($account, '111', 'Wedding Photo Booth', ['budget_amount_micros' => 30_000_000]);
        $this->seedCampaignDays($account, '111', '2026-10-01', '2026-10-04', $dailyCostMicros, 20, $conversions);
    }

    protected function adsUrl(Workspace $workspace, Business $business, string $name = 'index', array $query = []): string
    {
        return route('customer.workspaces.businesses.ads.' . $name, array_merge([$workspace->uid, $business->uid], $query));
    }
}
