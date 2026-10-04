<?php

namespace Tests\Feature\Seo\Rank\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankResultItem;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\SeoKeywordManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\SeoKeyword;
use App\Models\SeoRankLocation;
use App\Models\SeoRankTarget;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;

/**
 * Shared fixtures for the rank-tracking suites. Every test runs against
 * FakeSeoRankProvider: this trait rebinds the provider boundary so NO paid (or
 * any) provider network request can happen, and turns the master switch on.
 */
trait CreatesRankFixtures
{
    use CreatesSeoFixtures;

    public const CHICAGO = 1016367;
    public const NAPERVILLE = 1016368;
    public const SHREVEPORT = 1023311;

    protected function rankSetUp(): void
    {
        FakeSeoRankProvider::reset();
        $this->app->singleton(SeoRankProvider::class, fn () => new FakeSeoRankProvider());
        config(['seo.rank_tracking.enabled' => true]);

        $this->seedLocation(self::CHICAGO, 'Chicago,Illinois,United States');
        $this->seedLocation(self::NAPERVILLE, 'Naperville,Illinois,United States');
        $this->seedLocation(self::SHREVEPORT, 'Shreveport,Louisiana,United States');
    }

    protected function seedLocation(int $code, string $name, string $type = 'City'): SeoRankLocation
    {
        $location = new SeoRankLocation();
        $location->forceFill([
            'provider' => 'dataforseo',
            'location_code' => $code,
            'location_name' => $name,
            'country_iso' => 'US',
            'location_type' => $type,
        ])->save();

        return $location;
    }

    /** @return array{0: Customer, 1: Business, 2: Workspace} */
    protected function rankTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, ?string $domain = 'photoboothco.com'): array
    {
        [$owner, $business, $workspace] = $this->entitledTenant($tier);

        if ($domain !== null) {
            $this->giveDomain($business, $domain);
        }

        return [$owner, $business, $workspace];
    }

    /** The canonical Website domain: an Active, primary WebsiteDomain. */
    protected function giveDomain(Business $business, string $domain): WebsiteDomain
    {
        $website = Website::query()->where('business_id', $business->id)->first()
            ?? $this->publishWebsite($business, [$this->snapshotPage('home', 'Home', [], [], true, 'home')]);

        return WebsiteDomain::create([
            'uid' => (string) Str::uuid(),
            'website_id' => $website->id,
            'domain' => $domain,
            'is_primary' => true,
            'status' => WebsiteDomainStatus::Active,
        ]);
    }

    protected function keyword(Customer $owner, Business $business, string $phrase = 'photo booth rental'): SeoKeyword
    {
        return app(SeoKeywordManager::class)->create((int) $owner->user_id, $business, $phrase);
    }

    protected function track(Customer $owner, Business $business, SeoKeyword $keyword, int $locationCode = self::CHICAGO): SeoRankTarget
    {
        return app(SeoRankTargetManager::class)->track((int) $owner->user_id, $business, $keyword->uid, $locationCode);
    }

    protected function item(int $position, ?string $domain = null, ?string $url = null, ?string $phone = null, ?string $cid = null): SeoRankResultItem
    {
        return new SeoRankResultItem($position, $domain, $url ?? ($domain !== null ? 'https://' . $domain . '/' : null), $phone, $cid);
    }
}
