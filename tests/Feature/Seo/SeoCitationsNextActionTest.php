<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoCitationStatus;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\Seo\SeoCitationNextAction;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\SeoCitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * The one prioritized next action, the listing kit and the honest directory action wording.
 * Everything is derived from stored rows; nothing here may create a citation or mark one Listed.
 */
class SeoCitationsNextActionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypassCitationEntitlementForTest();
    }

    /** @return array{0: Business, 1: \App\Models\Workspace, 2: BusinessLocation} */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->publicStorefront($business, 'Main Storefront');
        $this->authenticateAsSeoCustomer($customer);

        return [$business, $workspace, $location];
    }

    private function page(\App\Models\Workspace $workspace, Business $business, BusinessLocation $location): string
    {
        return $this->get($this->citationsUrl($workspace, $business) . '?location=' . $location->uid)->assertOk()->getContent();
    }

    private function bestKind(string $html): string
    {
        $this->assertSame(1, preg_match('/data-section="citation-next-best"[^>]*data-kind="([a-z_]+)"/', $html, $m));

        return $m[1];
    }

    public function test_missing_canonical_information_comes_first_and_is_not_invented(): void
    {
        [$business, $workspace, $location] = $this->tenant();
        $business->forceFill(['website_url' => null])->save();

        $html = $this->page($workspace, $business, $location);

        $this->assertSame(SeoCitationNextAction::MISSING_INFO, $this->bestKind($html));
        $this->assertStringContainsString('Website is not set in your business profile.', $html);
        $this->assertMatchesRegularExpression('/data-kit="website".*?data-kit-missing>Not set/s', $html);
    }

    public function test_an_unchecked_priority_directory_is_not_checked_rather_than_missing_and_nothing_is_stored(): void
    {
        [$business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);

        $this->assertSame(SeoCitationNextAction::PRIORITY_DIRECTORY, $this->bestKind($html));
        $this->assertStringContainsString('has not been checked, which does not mean it is missing', $html);
        $this->assertStringContainsString('does not change your status here', $html);
        $this->assertStringContainsString('Listings do not guarantee any ranking change', $html);
        $this->assertStringNotContainsString('boost your ranking', strtolower($html));
        $this->assertSame(0, SeoCitation::query()->count(), 'Viewing or opening a link never creates or lists a citation.');
    }

    public function test_a_known_inconsistency_outranks_unchecked_directories(): void
    {
        [$business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('bing_places'), [
            'status' => SeoCitationStatus::NeedsCorrection->value,
            'listed_name' => 'Wrong Name Ltd',
            'last_verified_at' => now(),
        ]);

        $html = $this->page($workspace, $business, $location);

        $this->assertSame(SeoCitationNextAction::INCONSISTENT, $this->bestKind($html));
        $this->assertStringContainsString('Correct Bing Places for Business', $html);
    }

    public function test_directory_actions_describe_what_can_really_happen(): void
    {
        [$business, $workspace, $location] = $this->tenant();

        $html = $this->page($workspace, $business, $location);
        $this->assertStringContainsString('Set up Apple Business', $html);
        $this->assertStringContainsString('Opens Apple', $html);

        \App\Models\SeoCitationDirectory::query()->where('key', 'apple_business')->update(['is_active' => false]);
        $html = $this->page($workspace, $business, $location);
        $this->assertMatchesRegularExpression('/data-role="next-best-action"[^>]*>.*?Import from Google/s', $html);

        \App\Models\SeoCitationDirectory::query()->where('key', 'bing_places')->update(['is_active' => false]);
        $html = $this->page($workspace, $business, $location);
        $this->assertMatchesRegularExpression('/data-role="next-best-action"[^>]*>.*?Open listing setup/s', $html);
        $this->assertSame(0, SeoCitation::query()->count(), 'Opening links never marks anything Listed.');
    }

    public function test_an_existing_listing_links_to_the_recorded_url_not_the_claim_url(): void
    {
        [$business, $workspace, $location] = $this->tenant();
        $apple = $this->directory('apple_business');
        $this->makeCitation($business, $location, $apple, [
            'status' => SeoCitationStatus::NeedsCorrection->value,
            'listed_name' => 'Wrong Name Ltd',
            'listing_url' => 'https://maps.example-directory.com/place/acme',
            'last_verified_at' => now(),
        ]);

        $html = $this->page($workspace, $business, $location);
        $this->assertSame(SeoCitationNextAction::INCONSISTENT, $this->bestKind($html));
        $this->assertMatchesRegularExpression('/href="https:\/\/maps\.example-directory\.com\/place\/acme"[^>]*data-role="next-best-action" data-action-kind="listing"[^>]*>.*?Review existing listing/s', $html);
        $this->assertDoesNotMatchRegularExpression('/href="https:\/\/business\.apple\.com"[^>]*data-role="next-best-action"/', $html);
    }

    public function test_a_recorded_listing_without_a_url_uses_the_official_setup_link_labelled_as_such(): void
    {
        [$business, $workspace, $location] = $this->tenant();
        $this->makeCitation($business, $location, $this->directory('apple_business'), [
            'status' => SeoCitationStatus::NeedsCorrection->value, 'listed_name' => 'Wrong Name Ltd', 'listing_url' => null, 'last_verified_at' => now(),
        ]);

        $html = $this->page($workspace, $business, $location);
        $this->assertMatchesRegularExpression('/href="https:\/\/business\.apple\.com"[^>]*data-role="next-best-action" data-action-kind="setup"[^>]*>.*?Manage listing/s', $html);
    }

    public function test_a_directory_without_a_claim_url_offers_the_drawer_and_never_a_link(): void
    {
        [$business, $workspace, $location] = $this->tenant();
        $this->noClaimUrlEverywhere();

        $html = $this->page($workspace, $business, $location);
        $this->assertStringContainsString('No official setup link is on file', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-role="next-best-action"\s+data-action-kind="drawer"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*data-role="next-best-action"/', $html);
    }

    public function test_a_read_only_viewer_gets_guidance_and_no_write_action(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->publicStorefront($business, 'Main Storefront');
        $this->authenticateAsSeoCustomer($customer, ['view_seo', 'view_google_business_profile']);
        $this->noClaimUrlEverywhere();

        $html = $this->page($workspace, $business, $location);
        $this->assertStringContainsString('Someone with edit access can record it.', $html);
        $this->assertStringNotContainsString('data-role="next-best-action"', $html);
        $this->assertStringNotContainsString('data-role="next-best-record"', $html);
    }

    public function test_the_all_clear_state_advertises_no_button(): void
    {
        [$business, $workspace, $location] = $this->tenant();
        \App\Models\SeoCitationDirectory::query()->update(['is_active' => false]);

        $html = $this->page($workspace, $business, $location);
        $this->assertSame(SeoCitationNextAction::CLEAR, $this->bestKind($html));
        $this->assertStringNotContainsString('data-role="next-best-action"', $html);
    }

    /** @dataProvider retentionProvider */
    public function test_missing_google_mirror_never_monopolises_the_next_action(?int $retentionDays): void
    {
        $this->bindFakeGoogleClient();
        config(['google_business_profile.mirror.retention_days' => $retentionDays]);
        [$business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), false);

        $html = $this->page($workspace, $business, $location);

        $this->assertSame(SeoCitationNextAction::PRIORITY_DIRECTORY, $this->bestKind($html), 'A read-only Google refresh must not outrank directory setup.');
        $this->assertStringContainsString('your Google listing is not verified here', $html);
    }

    public static function retentionProvider(): array
    {
        return ['zero retention (unset)' => [null], 'positive retention' => [7]];
    }

    public function test_google_connection_loss_still_outranks_directories(): void
    {
        $this->bindFakeGoogleClient();
        [$business, $workspace, $location] = $this->tenant();
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business, ['state' => GoogleConnectionState::Revoked]));

        $this->assertSame(SeoCitationNextAction::GOOGLE, $this->bestKind($this->page($workspace, $business, $location)));
    }

    private function noClaimUrlEverywhere(): void
    {
        \App\Models\SeoCitationDirectory::query()->update(['claim_url' => null]);
    }

    public function test_a_private_address_never_reaches_the_listing_kit(): void
    {
        [$business, $workspace] = $this->tenant();
        $private = $this->privateLocation($business, 'Home Office');
        $private->forceFill(['service_area_cities' => ['Springfield', 'Shelbyville']])->save();

        $html = $this->page($workspace, $business, $private);

        $this->assertStringNotContainsString('99 Secret Lane', $html);
        $this->assertMatchesRegularExpression('/data-kit="service_area".*?Springfield, Shelbyville/s', $html);
        $this->assertStringContainsString('street address is private', $html);
        $this->assertStringNotContainsString('data-kit="address"', $html);
    }

    public function test_each_location_gets_its_own_kit_and_inaccessible_locations_do_not_leak(): void
    {
        [$business, $workspace, $main] = $this->tenant();
        $other = $this->publicStorefront($business, 'Second Branch');
        $other->forceFill(['address_line_1' => '5 Other Avenue'])->save();

        $mainHtml = $this->page($workspace, $business, $main);
        $otherHtml = $this->page($workspace, $business, $other);

        $this->assertStringContainsString('12 High Street', $mainHtml);
        $this->assertStringNotContainsString('5 Other Avenue', $mainHtml);
        $this->assertStringContainsString('5 Other Avenue', $otherHtml);

        // Another tenant's location is never rendered, whatever uid is requested.
        [, $foreignBusiness] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $foreign = $this->publicStorefront($foreignBusiness, 'Foreign');
        $foreign->forceFill(['address_line_1' => '1 Foreign Way'])->save();
        $html = $this->get($this->citationsUrl($workspace, $business) . '?location=' . $foreign->uid)->getContent();
        $this->assertStringNotContainsString('1 Foreign Way', $html);
    }
}
