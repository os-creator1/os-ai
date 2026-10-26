<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\SiteSchema;
use App\Library\Branding\AgencyWhiteLabelManager;
use App\Library\Marketing\MarketingDemo;
use App\Models\AgencySite;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * The industry sample data drawn inside the marketing illustrations: the dog training and boarding Agency sees dog
 * scenarios everywhere, while the platform's own site and every other Agency keep the original examples.
 */
class AgencySiteDemoContentTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    private const PAGES = ['home', 'product', 'solutions', 'how-it-works', 'pricing'];

    /** Anything left over from the original events / photo booth examples. */
    private const UNRELATED = '/wedding|photo ?booth|\bbooth\b|lumen|riverside|\bgala\b|corporate|birthday|\bchen\b|nguyen party|event service|event booth|unforgettable events/i';

    /** Features MotionGrove does not have; the dog scenario must never imply them. */
    private const UNSUPPORTED = '/occupancy|vaccin|medical record|health record|overnight reservation|kennel management|runs? and kennels? availability/i';

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
        config(['marketing.enabled' => true, 'marketing.hosts' => [], 'marketing.show_placeholders' => false]);
    }

    /** @return array{0: Workspace, 1: Customer} */
    private function agency(string $name): array
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, $name.' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $workspace, ['display_name' => $name, 'accent_color' => '#1a73e8', 'is_enabled' => '1']);

        return [$workspace, $owner];
    }

    /** Daniel's Agency with the dog preset applied to its draft. */
    private function daniel(): array
    {
        [$agency, $owner] = $this->agency('Daniel Black');
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);
        $this->assertSame(0, Artisan::call('agency-site:apply-preset', ['workspaceUid' => $agency->uid, '--apply' => true]), Artisan::output());
        $this->authenticateAs($owner);

        return [$agency, $owner, $type];
    }

    private function preview(Workspace $agency, string $page): string
    {
        return $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, $page]))->assertOk()->getContent();
    }

    private function text(string $html): string
    {
        return html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $html)), ENT_QUOTES | ENT_HTML5);
    }

    private function allPages(callable $fetch): string
    {
        return implode("\n", array_map($fetch, self::PAGES));
    }

    // ------------------------------------------------------------ the provider

    public function test_the_dog_set_has_every_key_the_views_use_and_makes_no_unsupported_or_unrelated_claim(): void
    {
        $text = '';

        foreach (MarketingDemo::keys() as $key) {
            $text .= ' '.MarketingDemo::get(MarketingDemo::DOG_TRAINING, $key);
        }

        $text .= ' '.json_encode(MarketingDemo::tree(MarketingDemo::DOG_TRAINING));

        $this->assertDoesNotMatchRegularExpression(self::UNRELATED, $text);
        $this->assertDoesNotMatchRegularExpression(self::UNSUPPORTED, $text);
        $this->assertDoesNotMatchRegularExpression('/guarantee|\d+\s?%|\$\d/i', $text, 'no guarantees, statistics or prices');
        $this->assertSame('', MarketingDemo::get('nonsense', 'site.services'), 'an unknown set is the default set');
        $this->assertSame('Lumen Events', MarketingDemo::get(null, 'business'));
        $this->assertSame([], MarketingDemo::tree('nonsense'));
    }

    public function test_the_editor_accepts_only_known_demo_sets(): void
    {
        $this->assertSame('dog-training', SiteSchema::sanitize('x.demo', 'dog-training'));
        $this->assertNull(SiteSchema::sanitize('x.demo', ''));

        $this->expectException(ValidationException::class);
        SiteSchema::sanitize('x.demo', 'weddings');
    }

    // ------------------------------------------------------------ Daniel's pages

    public function test_daniels_pages_show_dog_training_and_boarding_examples(): void
    {
        [$agency] = $this->daniel();
        $home = $this->preview($agency, 'home');
        $product = $this->preview($agency, 'product');

        foreach ([
            'Paw & Progress Dog Training', 'New puppy training inquiry — Jordan P.', 'Puppy consultation · from your website form',
            'dog trainer near me', 'puppy training', 'dog boarding near me', 'Puppy Training', 'Obedience Training', 'Board & Train',
            'pawandprogress.example', 'Book a consultation', 'Jordan Parker', 'Hi, do you have availability for a puppy training consultation?',
            'Thanks for your inquiry! Here’s a link to book a consultation.', 'Sample data',
        ] as $expected) {
            $this->assertStringContainsString($expected, $this->text($home.$product), $expected);
        }

        $this->assertStringContainsString('Sam Okafor', $this->text($product), 'the product CRM board is dog-themed');
        $this->assertStringContainsString('Board & Train', $this->text($product), 'the product website illustration lists dog services');
    }

    public function test_no_wedding_photo_booth_or_event_content_remains_on_any_of_his_pages(): void
    {
        [$agency] = $this->daniel();

        foreach (self::PAGES as $page) {
            $text = $this->text($this->preview($agency, $page));
            // The FAQ states plainly what MotionGrove does NOT do; that disclaimer is the one place these words belong.
            $claims = str_replace(['Does MotionGrove manage kennel occupancy or pet health records?', 'It does not include kennel occupancy, vaccination or pet health record management.'], '', $text);

            $this->assertDoesNotMatchRegularExpression(self::UNRELATED, $text, "unrelated industry content on {$page}");
            $this->assertDoesNotMatchRegularExpression(self::UNSUPPORTED, $claims, "unsupported claim on {$page}");
        }

        $this->assertDoesNotMatchRegularExpression('/June 14/', $this->text($this->preview($agency, 'home')), 'no event date in the home illustrations');
    }

    public function test_desktop_and_mobile_examples_use_the_same_dog_scenario(): void
    {
        [$agency] = $this->daniel();
        $home = $this->preview($agency, 'home');

        // The hero draws the desktop dashboard (.hide-m) and the phone dashboard (.show-m).
        $this->assertStringContainsString('<div class="hide-m">', $home);
        $this->assertStringContainsString('<div class="wrap show-m">', $home);
        [$beforePhone, $phone] = explode('<div class="wrap show-m">', $home, 2);
        $phone = substr($phone, 0, strpos($phone, '</figure>') ?: null);

        $this->assertStringContainsString('Paw &amp; Progress Dog Training', $phone);
        $this->assertStringContainsString('New puppy training inquiry — Jordan P.', $phone);
        $this->assertStringContainsString('Puppy consultation', $phone);
        $this->assertStringContainsString('Consultation booked', $phone);
        $this->assertDoesNotMatchRegularExpression(self::UNRELATED, $phone);
        $this->assertStringContainsString('Paw &amp; Progress Dog Training', $beforePhone);
        $this->assertStringContainsString('Puppy consultation · from your website form', $beforePhone);
    }

    public function test_sentences_about_the_software_say_motiongrove_while_the_agency_brand_is_unchanged(): void
    {
        [$agency] = $this->daniel();
        $home = $this->preview($agency, 'home');
        $text = $this->text($home);

        $this->assertStringContainsString('MotionGrove builds a mobile-ready site', $text);
        $this->assertStringNotContainsString('Daniel Black builds', $text);
        $this->assertStringContainsString('Ask MotionGrove anything', $text);
        $this->assertStringContainsString('Daniel Black', $home, 'the Agency brand is still the header and footer identity');
        $this->assertStringContainsString('aria-label="Daniel Black home"', $home);
    }

    public function test_the_original_calls_to_action_and_pricing_page_still_work(): void
    {
        [$agency, , $type] = $this->daniel();
        $home = $this->preview($agency, 'home');

        $this->assertStringContainsString((string) $type->public_booking_uuid, $home, 'the booking CTA still opens the Agency calendar');
        $this->assertStringContainsString('Book a demo', $home);
        $this->assertStringContainsString('Pricing', $this->text($this->preview($agency, 'pricing')));
        $this->assertStringNotContainsString('getmotiongrove.com', $home, 'platform wiring never leaks into an Agency site');
    }

    // ------------------------------------------------------------ everything else is unchanged

    public function test_the_platforms_own_marketing_site_keeps_its_original_examples(): void
    {
        $home = $this->get('/')->assertOk()->getContent();
        $product = $this->get('/product')->assertOk()->getContent();

        foreach (['Lumen Events', 'Wedding, June 14 · from your website form', 'Proposal signed — Riverside Gala', 'lumenevents.com', 'Unforgettable events, captured.', 'photo booth rental near me', 'wedding photo booth', '360 booth hire', 'Hi! Do you have a booth free on June 14?', 'Proposals &amp; payments', 'Move it back to the top of your <b>Weddings page</b>'] as $expected) {
            $this->assertStringContainsString($expected, $home, $expected);
        }

        foreach (['Riverside Gala', 'Chen wedding', 'event booth hire', 'Pick a time · Sat, June 14'] as $expected) {
            $this->assertStringContainsString($expected, $product, $expected);
        }

        $this->assertStringNotContainsString('Paw &amp; Progress', $home.$product);
        $this->assertStringNotContainsString('dog trainer near me', $home.$product);
    }

    public function test_another_agency_without_the_preset_keeps_the_original_examples_and_is_not_touched(): void
    {
        [$daniel] = $this->daniel();
        [$other, $otherOwner] = $this->agency('Northwind Agency');
        $site = app(AgencySiteManager::class)->ensure((int) $otherOwner->user_id, $other);
        $configBefore = $site->fresh()->config;
        $this->authenticateAs($otherOwner);

        $home = $this->preview($other, 'home');

        $this->assertStringContainsString('Lumen Events', $home);
        $this->assertStringContainsString('photo booth rental near me', $home);
        $this->assertStringNotContainsString('Paw &amp; Progress', $home);
        $this->assertStringContainsString('Northwind Agency builds a mobile-ready site', $this->text($home), 'brand-as-product wording is unchanged for other Agencies');
        $this->assertSame($configBefore, $site->fresh()->config);
        $this->assertNotSame($daniel->id, $other->id);
    }

    public function test_a_tenant_cannot_see_another_agencys_draft_preview(): void
    {
        [$daniel] = $this->daniel();
        [, $stranger] = $this->agency('Northwind Agency');
        $this->authenticateAs($stranger);

        $this->get(route('customer.workspaces.agency.site.preview', [$daniel->uid, 'home']))->assertNotFound();
    }

    // ------------------------------------------------------------ draft and published render the same

    public function test_the_published_pages_use_the_same_dog_content_as_the_draft_preview(): void
    {
        [$agency] = $this->daniel();
        $site = AgencySite::query()->where('agency_workspace_id', $agency->id)->sole();
        $this->postJson(route('customer.workspaces.agency.site.save', $agency->uid), ['answers' => [
            'links.privacy' => 'https://daniel.example/privacy', 'links.terms' => 'https://daniel.example/terms',
        ], 'step' => 2])->assertOk();
        $this->postJson(route('customer.workspaces.agency.site.publish', $agency->uid))->assertOk()->assertJsonPath('isLive', true);

        foreach (['home', 'product'] as $page) {
            $url = $page === 'home' ? route('agency.site.home', $site->public_id) : route('agency.site.page', [$site->public_id, $page]);
            $live = $this->text($this->get($url)->assertOk()->getContent());
            $draft = $this->text($this->preview($agency, $page));

            foreach (['Paw & Progress Dog Training', 'dog trainer near me', 'Puppy Training', 'Jordan Parker'] as $expected) {
                $this->assertSame(str_contains($draft, $expected), str_contains($live, $expected), "{$expected} on {$page}");
            }

            $this->assertDoesNotMatchRegularExpression(self::UNRELATED, $live, "published {$page}");
        }

    }
}
