<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * LocalBusiness structured data states service coverage only for places the site visibly names: the markup must agree with
 * what a visitor can read. The entity id stays the same on every page, and nothing is invented.
 */
class WebsiteAreaServedStructuredDataTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    /** @return array<string, mixed> the page's LocalBusiness JSON-LD */
    private function localBusiness(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            foreach (isset($decoded['@graph']) ? $decoded['@graph'] : [$decoded] as $item) {
                if (($item['@type'] ?? null) === 'LocalBusiness') {
                    return $item;
                }
            }
        }

        $this->fail('No LocalBusiness JSON-LD on ' . $url);
    }

    public function test_area_served_lists_only_the_confirmed_places_the_site_actually_names(): void
    {
        [, $business] = $this->entitledTenant();
        (new BusinessLocation())->forceFill([
            'business_id' => $business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US',
            'service_area_cities' => ['Spokane Valley, WA', 'Liberty Lake, WA', 'Cheney, WA'],
        ])->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['sections' => [['type' => 'text', 'data' => ['heading' => 'Where we work', 'body' => 'We bring our booths to Spokane, Spokane Valley and Cheney.']]]]);
        $this->subPage($website, 'about');
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->domains()->create(['domain' => 'areas-served.test', 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);

        $home = $this->localBusiness('http://areas-served.test/');

        $this->assertSame(
            [['@type' => 'City', 'name' => 'Spokane'], ['@type' => 'City', 'name' => 'Spokane Valley'], ['@type' => 'City', 'name' => 'Cheney']],
            $home['areaServed'],
            'Liberty Lake is saved but named nowhere on the site, so it is not asserted.'
        );
        $this->assertSame('https://areas-served.test/#business', $home['@id'], 'One stable entity id on the customer domain.');
        $this->assertSame($home['@id'], $this->localBusiness('http://areas-served.test/about')['@id'], 'The same business entity on every page.');
        $this->assertArrayNotHasKey('aggregateRating', $home);
        $this->assertArrayNotHasKey('priceRange', $home);
    }

    public function test_no_area_served_when_the_site_names_no_confirmed_place(): void
    {
        [, $business] = $this->entitledTenant();
        (new BusinessLocation())->forceFill([
            'business_id' => $business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US',
            'service_area_cities' => ['Cheney, WA'],
        ])->save();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->domains()->create(['domain' => 'no-areas.test', 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);

        $this->assertArrayNotHasKey('areaServed', $this->localBusiness('http://no-areas.test/'));
    }
}
