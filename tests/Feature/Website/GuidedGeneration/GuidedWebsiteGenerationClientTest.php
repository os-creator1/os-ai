<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfileFieldState;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Acceptance-correction Blocker 3. Proves the generation context is
 * built from each domain's own canonical authority — never duplicated
 * onto BusinessKnowledgeProfile — and specifically the "IMPORTANT HOURS
 * BUG": confirmed hours must come from the real BusinessLocation row,
 * never from a nonexistent BusinessKnowledgeProfile column.
 */
class GuidedWebsiteGenerationClientTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    /**
     * `is_primary` is deliberately excluded from BusinessLocation's own
     * $fillable (a protected invariant normally set only through the
     * location-management seam) — set here via direct property
     * assignment after create(), mirroring
     * CreatesBusinessKnowledgeProfileFixtures::addLocation()'s own
     * established pattern. entitledTenant() itself never creates a
     * location at all, so every test below that needs a primary one
     * creates it explicitly.
     */
    private function primaryLocation(Business $business, array $overrides = []): BusinessLocation
    {
        $location = BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'name' => 'Primary Location',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));

        $location->is_primary = true;
        $location->save();

        return $location->fresh();
    }

    public function test_confirmed_location_hours_reach_the_generation_context(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'email' => 'hello@example.test']);
        $this->primaryLocation($business, [
            'hours' => ['mon' => [['09:00', '17:00']]],
            'hours_verification_status' => BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED,
            'hours_verified_at' => now(),
        ]);

        $facts = app(GuidedWebsiteGenerationClient::class)->canonicalFacts($business->fresh());

        $this->assertArrayHasKey('hours', $facts);
        $this->assertSame(['mon' => [['09:00', '17:00']]], $facts['hours']);
    }

    public function test_unverified_hours_never_reach_the_generation_context(): void
    {
        [, $business] = $this->entitledTenant();
        $this->primaryLocation($business, [
            'hours' => ['mon' => [['09:00', '17:00']]],
            'hours_verification_status' => 'unverified',
        ]);

        $facts = app(GuidedWebsiteGenerationClient::class)->canonicalFacts($business->fresh());

        $this->assertArrayNotHasKey('hours', $facts);
    }

    public function test_a_business_with_no_saved_location_at_all_never_errors_and_sends_no_address_or_hours(): void
    {
        // entitledTenant() itself creates no BusinessLocation row —
        // canonicalFacts() must handle a null primaryLocation() cleanly.
        [, $business] = $this->entitledTenant();

        $facts = app(GuidedWebsiteGenerationClient::class)->canonicalFacts($business->fresh());

        $this->assertArrayNotHasKey('hours', $facts);
        $this->assertArrayNotHasKey('address', $facts);
    }

    public function test_business_phone_email_and_description_reach_the_context(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update([
            'phone' => '+15550001234',
            'email' => 'hello@example.test',
            'description' => 'A real local photo booth company.',
        ]);

        $facts = app(GuidedWebsiteGenerationClient::class)->canonicalFacts($business->fresh());

        $this->assertSame('+15550001234', $facts['phone']);
        $this->assertSame('hello@example.test', $facts['email']);
        $this->assertSame('A real local photo booth company.', $facts['description']);
    }

    public function test_a_public_address_reaches_the_context_but_a_private_one_does_not(): void
    {
        [, $business] = $this->entitledTenant();
        $primary = $this->primaryLocation($business, ['public_address' => true, 'address_line_1' => '400 S Washington St', 'city' => 'Naperville']);

        $facts = app(GuidedWebsiteGenerationClient::class)->canonicalFacts($business->fresh());
        $this->assertArrayHasKey('address', $facts);
        $this->assertSame('400 S Washington St', $facts['address']['address_line_1']);

        $primary->update(['public_address' => false]);
        $facts = app(GuidedWebsiteGenerationClient::class)->canonicalFacts($business->fresh());
        $this->assertArrayNotHasKey('address', $facts);
    }

    public function test_confirmed_profile_facts_reach_the_context_but_unconfirmed_ones_do_not(): void
    {
        [$customer, $business] = $this->entitledTenant();

        app(BusinessKnowledgeProfileManager::class)->updateFields($business, [
            'differentiators' => ['Fast setup', 'Local family business'],
        ], 'manual_edit', $customer->user_id, markVerified: true);

        $facts = app(GuidedWebsiteGenerationClient::class)->canonicalFacts($business->fresh());

        $this->assertArrayHasKey('differentiators', $facts);
        $this->assertSame(['Fast setup', 'Local family business'], $facts['differentiators']);
        // years_operating was never set/confirmed at all — must not appear.
        $this->assertArrayNotHasKey('years_operating', $facts);
    }
}
