<?php

namespace Tests\Feature\Seo\Concerns;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Seo\SeoCitationStatus;
use App\Http\Controllers\Customer\Business\SeoCitationController;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;
use App\Models\Workspace;
use Tests\Support\Seo\EntitlementBypassSeoCitationController;

/**
 * Contract 18 Sub-slice 18E — Citations fixtures, on top of the SEO ones.
 */
trait CreatesSeoCitationFixtures
{
    use CreatesSeoFixtures;

    protected function bypassCitationEntitlementForTest(): void
    {
        $this->app->bind(SeoCitationController::class, EntitlementBypassSeoCitationController::class);
    }

    protected function citationsUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.seo.citations.index', [$workspace->uid, $business->uid]);
    }

    protected function citationUpdateUrl(Workspace $workspace, Business $business, string $locationUid, string $directoryKey): string
    {
        return route('customer.workspaces.businesses.seo.citations.update', [$workspace->uid, $business->uid, $locationUid, $directoryKey]);
    }

    protected function directory(string $key = 'bing_places'): SeoCitationDirectory
    {
        return SeoCitationDirectory::query()->where('key', $key)->firstOrFail();
    }

    /**
     * A Location that is allowed to expose a street address.
     */
    protected function publicStorefront(Business $business, string $name = 'Storefront'): BusinessLocation
    {
        return $this->extraLocation($business, $name, [
            'service_mode' => BusinessServiceMode::Storefront,
            'public_address' => true,
            'address_line_1' => '12 High Street',
            'city' => 'Springfield',
            'region' => 'IL',
            'postal_code' => '62701',
            'country_code' => 'US',
        ]);
    }

    /**
     * A Location that must never expose a street address.
     */
    protected function privateLocation(Business $business, string $name = 'Home Office'): BusinessLocation
    {
        return $this->extraLocation($business, $name, [
            'service_mode' => BusinessServiceMode::ServiceArea,
            'public_address' => false,
            'address_line_1' => '99 Secret Lane',
            'city' => 'Springfield',
            'region' => 'IL',
            'postal_code' => '62701',
            'country_code' => 'US',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeCitation(Business $business, BusinessLocation $location, ?SeoCitationDirectory $directory = null, array $overrides = []): SeoCitation
    {
        return SeoCitation::create(array_merge([
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'seo_citation_directory_id' => ($directory ?? $this->directory())->id,
            'status' => SeoCitationStatus::Listed->value,
            'verification_source' => SeoCitation::SOURCE_USER_ASSERTED,
        ], $overrides));
    }

    /**
     * A complete, valid form submission.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function citationInput(array $overrides = []): array
    {
        return array_merge([
            'status' => SeoCitationStatus::Listed->value,
            'listing_url' => 'https://www.example-directory.com/biz/acme',
            'listed_name' => 'Acme Plumbing',
            'listed_phone' => '+1 555 010 1234',
            'listed_address' => '',
            'last_verified_at' => '2026-09-01',
            'notes' => 'Checked by hand.',
        ], $overrides);
    }
}
