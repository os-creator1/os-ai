<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\Presets\DogTrainingBoardingPreset;
use App\Library\AgencySite\SiteSchema;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Models\AgencySite;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\BookingType;
use App\Models\Customer;
use App\Models\StaffAvailabilityRule;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/** The operator command that writes the dog training and boarding preset into one Agency's draft. */
class ApplyAgencySitePresetTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    /** @return array{0: Workspace, 1: Customer} */
    private function agency(string $name = 'Daniel Black'): array
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, $name.' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $workspace, ['display_name' => $name, 'accent_color' => '#1a73e8', 'is_enabled' => '1']);

        return [$workspace, $owner];
    }

    private function run_(Workspace $agency, array $options = []): array
    {
        $code = Artisan::call('agency-site:apply-preset', ['workspaceUid' => $agency->uid] + $options);

        return [$code, Artisan::output()];
    }

    private function values(Workspace $agency): array
    {
        return AgencySiteManager::values(AgencySite::query()->where('agency_workspace_id', $agency->id)->firstOrFail());
    }

    public function test_every_preset_value_passes_the_editors_own_validation_and_makes_no_unsupported_claim(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$sell, $book]) {
            $text = '';

            foreach (DogTrainingBoardingPreset::values($sell, $book) as $path => $value) {
                $this->assertNotNull(SiteSchema::fieldIndex()[$path] ?? null, "{$path} is a real editor field");
                SiteSchema::sanitize($path, $value);
                $text .= ' '.json_encode($value);
            }

            $this->assertDoesNotMatchRegularExpression('/guarantee|\d+\s?%|testimonial|case study|\$\d/i', $text, 'no guarantees, statistics, testimonials or prices');
        }

        $text = json_encode(DogTrainingBoardingPreset::values(true, true));
        $this->assertStringContainsString('does not include kennel occupancy', $text, 'the limits are stated plainly');
        $this->assertStringContainsString('More clients. More bookings.', $text);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        [$agency] = $this->agency();

        [$code, $out] = $this->run_($agency);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertSame(0, AgencySite::query()->count());
    }

    public function test_apply_writes_only_this_agencys_draft_never_publishes_and_leaves_branding_alone(): void
    {
        [$agency, $owner] = $this->agency();
        [$other, $otherOwner] = $this->agency('Other Agency');
        $otherSite = app(AgencySiteManager::class)->ensure((int) $otherOwner->user_id, $other);
        $otherBefore = $otherSite->fresh()->config;
        $brandBefore = AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->firstOrFail()->only(['display_name', 'accent_color', 'logo_path']);
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        [$code, $out] = $this->run_($agency, ['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $site = AgencySite::query()->where('agency_workspace_id', $agency->id)->sole();
        $this->assertSame('draft', $site->status);
        $this->assertNull($site->published_revision_id);
        $this->assertSame(1, AgencySite::query()->where('agency_workspace_id', $agency->id)->count(), 'no duplicate site');

        $values = $this->values($agency);
        $this->assertSame('More clients. More bookings.', $values['copy.home.hero.title']);
        $this->assertSame('book_call', $values['x.goal'], 'booking works, nothing is sellable yet');
        $this->assertSame($type->public_booking_uuid, $values['x.booking_type_uid']);
        $this->assertSame('booking', $values['x.cta.hero']);
        $this->assertArrayNotHasKey('x.pricing_mode', $values);
        $this->assertArrayNotHasKey('x.testimonials', $values, 'no testimonials are invented');

        $this->assertSame($otherBefore, $otherSite->fresh()->config, 'another Agency is untouched');
        $this->assertSame($brandBefore, AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->firstOrFail()->only(['display_name', 'accent_color', 'logo_path']));
    }

    public function test_with_a_sellable_plan_and_a_bookable_type_the_site_offers_both_and_can_publish(): void
    {
        [$agency, $owner] = $this->agency();
        $this->connectAgencyStripe($agency, (int) $owner->user_id);
        $this->publishedPlan($agency, (int) $owner->user_id, WorkspacePlanTier::Growth, '49.00', null, 'Studio');
        app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        $this->run_($agency, ['--apply' => true]);

        $values = $this->values($agency);
        $this->assertSame('both', $values['x.goal']);
        $this->assertSame('signup', $values['x.cta.hero']);
        $this->assertSame('Get started', $values['copy.home.hero.primary']);

        // The preset's content satisfies the editor's own publish checks except what the Agency must still supply.
        $site = AgencySite::query()->where('agency_workspace_id', $agency->id)->sole();
        $failing = collect(app(AgencySiteManager::class)->checklist($agency, $site))->where('status', 'fail')->pluck('key')->all();
        $this->assertSame(['legal'], $failing, 'only the Agency\'s own privacy and terms links remain');

        // The draft preview renders the new content with the marketing stylesheet.
        $this->authenticateAs($owner);
        foreach (['home', 'product', 'solutions', 'how-it-works', 'pricing'] as $page) {
            $html = $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, $page]))->assertOk()->getContent();
            $this->assertStringContainsString('preview-css/marketing.css', $html, $page);
            $this->assertStringNotContainsString('Jazmin', $html, $page);
        }
        $home = $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'home']))->getContent();
        $this->assertStringContainsString('More clients. More bookings.', $home);
        $this->assertStringContainsString('Dog Training Businesses', $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'solutions']))->getContent());
        $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'customers']))->assertNotFound();
    }

    public function test_without_any_working_conversion_route_the_goal_is_left_alone_and_nothing_is_invented(): void
    {
        [$agency, $owner] = $this->agency();
        app(AgencySiteManager::class)->ensure((int) $owner->user_id, $agency);
        $goalBefore = $this->values($agency)['x.goal'] ?? null;

        [$code, $out] = $this->run_($agency, ['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertSame($goalBefore, $this->values($agency)['x.goal'] ?? null);
        $this->assertStringContainsString('Blocks publishing', $out);
        $this->assertSame(0, BookingType::query()->count());
    }

    public function test_the_demo_booking_type_is_created_only_on_request_and_without_inventing_working_hours(): void
    {
        [$agency, $owner] = $this->agency();

        $this->run_($agency, ['--apply' => true]);
        $this->assertSame(0, BookingType::query()->count(), 'not created unless asked');

        $this->run_($agency, ['--apply' => true, '--create-booking-type' => true]);
        $this->run_($agency, ['--apply' => true, '--create-booking-type' => true]);

        $this->assertSame(1, BookingType::query()->where('name', 'MotionGrove Demo — Dog Training & Boarding')->count(), 'a second run does not duplicate it');
        $this->assertSame(30, (int) BookingType::query()->sole()->duration_minutes);
        $this->assertSame(0, StaffAvailabilityRule::query()->count(), 'no hours were invented');
        $this->assertNotSame('book_call', $this->values($agency)['x.goal'] ?? null, 'not bookable yet, so it is not advertised');
    }

    public function test_it_refuses_production_and_unknown_workspaces(): void
    {
        [$agency] = $this->agency();

        $this->assertSame(1, Artisan::call('agency-site:apply-preset', ['workspaceUid' => 'does-not-exist', '--apply' => true]));

        $this->app['env'] = 'production';
        [$code, $out] = $this->run_($agency, ['--apply' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('production', $out);
        $this->assertSame(0, AgencySite::query()->count());
    }
}
