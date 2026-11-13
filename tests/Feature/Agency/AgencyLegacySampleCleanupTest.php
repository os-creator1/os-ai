<?php

namespace Tests\Feature\Agency;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencySite\AgencySiteBookingService;
use App\Library\AgencySite\AgencySiteManager;
use App\Library\AgencySite\LegacySampleCleaner;
use App\Library\AgencySite\SiteEditorPresenter;
use App\Models\AgencySite;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * A draft that pinned the platform's old event demo copy is cleaned up narrowly: only unedited copies of the general
 * default are reset; everything the Agency really wrote or configured survives.
 */
class AgencyLegacySampleCleanupTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    private const EVENT_INDUSTRIES = ['Event services', 'Photo booth rentals', 'Home services', 'Health & wellness', 'Salons & studios', 'Professional services', 'Marketing agencies'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    /** @return array{0: Workspace, 1: Customer} */
    private function agency(string $name = 'Northwind Agency'): array
    {
        [$owner, $business, $agency] = $this->tenant(WorkspacePlanTier::Agency, $name . ' Business', $name);
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($business, ['service_mode' => 'storefront', 'country_code' => 'US']);
        app(\App\Library\Branding\AgencyWhiteLabelManager::class)->save((int) $owner->user_id, $agency, ['display_name' => $name . ' Digital', 'accent_color' => '#1a73e8', 'is_enabled' => '1']);

        return [$agency, $owner];
    }

    private function save(Workspace $agency, Customer $owner, array $answers): AgencySite
    {
        $sites = app(AgencySiteManager::class);
        $sites->ensure((int) $owner->user_id, $agency);

        return $sites->save((int) $owner->user_id, $agency, $answers);
    }

    private function url(Workspace $agency): string
    {
        return route('customer.workspaces.agency.site.clear-legacy-samples', ['workspaceUid' => $agency->uid]);
    }

    private function staleDraft(Workspace $agency, Customer $owner): AgencySite
    {
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        return $this->save($agency, $owner, [
            'x.goal' => 'book_call',
            'x.booking_type_uid' => $type->public_booking_uuid,
            // pinned copies of the old general demo (unedited defaults saved long ago)
            'copy.home.trust.industries' => self::EVENT_INDUSTRIES,
            'copy.home.hero.title' => 'More bookings.',
            // genuine custom content that must survive
            'copy.home.hero.title_accent' => 'Fewer headaches.',
            'nav.product' => 'Features',
            'links.privacy' => 'https://northwind.example/privacy',
            'links.terms' => 'https://northwind.example/terms',
            // custom text that still mentions event wording: reported, never rewritten for the owner
            'copy.home.hero.lead' => 'Our wedding clients love it.',
        ]);
    }

    public function test_only_pinned_copies_of_the_old_demo_are_reset_and_custom_content_is_preserved(): void
    {
        [$agency, $owner] = $this->agency();
        $site = $this->staleDraft($agency, $owner);
        $before = AgencySiteManager::values($site->fresh());

        $analysis = app(LegacySampleCleaner::class)->analyse($site->fresh());
        $this->assertEqualsCanonicalizing(['copy.home.trust.industries', 'copy.home.hero.title'], $analysis['pinned']);
        $this->assertSame(['copy.home.hero.lead'], $analysis['review']);

        $this->authenticateAs($owner);
        $response = $this->postJson($this->url($agency), ['niche' => 'dog_business'])->assertOk()->assertJsonPath('ok', true);
        $response->assertJsonPath('review.0', 'copy.home.hero.lead');

        $after = AgencySiteManager::values($site->fresh());
        $this->assertSame('dog_business', $after['x.niche']);
        $this->assertArrayNotHasKey('copy.home.trust.industries', $after);
        $this->assertArrayNotHasKey('copy.home.hero.title', $after);

        // Everything else is byte-for-byte what it was.
        foreach ($before as $path => $value) {
            if (! in_array($path, ['copy.home.trust.industries', 'copy.home.hero.title'], true)) {
                $this->assertSame($value, $after[$path], "{$path} preserved");
            }
        }

        $home = $this->get(route('customer.workspaces.agency.site.preview', [$agency->uid, 'home']))->assertOk()->getContent();
        $this->assertStringContainsString('Dog trainers', $home);
        $this->assertStringContainsString('More clients. More bookings.', $home);
        $this->assertStringContainsString('Fewer headaches.', $home, 'custom headline kept');
        $this->assertStringNotContainsString('Lumen Events', $home);
        $this->assertStringNotContainsString('photo booth', strtolower($home));
        $this->assertStringNotContainsString('Photo booth rentals', $home);
    }

    public function test_the_editor_defaults_follow_the_selected_niche_and_flag_the_leftovers(): void
    {
        [$agency, $owner] = $this->agency();
        $site = $this->staleDraft($agency, $owner);
        $this->save($agency, $owner, ['x.niche' => 'dog_business']);

        $state = app(SiteEditorPresenter::class)->present($agency, $site->fresh());
        $trust = collect($state['groups']['trust']['fields'])->firstWhere('path', 'copy.home.trust.industries');

        $this->assertContains('Dog trainers', $trust['default'], 'reset returns to the dog examples, not the event ones');
        $this->assertTrue($trust['overridden']);
        $this->assertEqualsCanonicalizing(['copy.home.trust.industries', 'copy.home.hero.title'], $state['legacySamples']['pinned']);
    }

    public function test_the_cleanup_is_owner_only_scoped_to_its_own_agency_and_validated(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        [$other, $otherOwner] = $this->agency('Agency B');
        $this->staleDraft($agency, $owner);
        $otherSite = $this->save($other, $otherOwner, ['copy.home.hero.title' => 'More bookings.']);

        $this->authenticateAs($otherOwner);
        $this->postJson($this->url($agency), ['niche' => 'dog_business'])->assertNotFound();

        $this->authenticateAs($owner);
        $this->postJson($this->url($agency), ['niche' => 'general'])->assertStatus(422);
        $this->postJson($this->url($agency), ['niche' => 'nonsense'])->assertStatus(422);

        // Agency B is untouched by anything Agency A did.
        $this->assertSame('More bookings.', AgencySiteManager::values($otherSite->fresh())['copy.home.hero.title']);
        $this->assertArrayNotHasKey('x.niche', AgencySiteManager::values($otherSite->fresh()));
    }
}
