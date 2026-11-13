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

    private function staleDraft(Workspace $agency, Customer $owner, ?string $niche = 'dog_business'): AgencySite
    {
        $type = app(AgencySiteBookingService::class)->create((int) $owner->user_id, $agency, ['name' => 'Intro call', 'duration_minutes' => 30, 'default_hours' => true]);

        return $this->save($agency, $owner, ($niche === null ? [] : ['x.niche' => $niche]) + [
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
        $response = $this->postJson($this->url($agency))->assertOk()->assertJsonPath('ok', true);
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

        $state = app(SiteEditorPresenter::class)->present($agency, $site->fresh());
        $trust = collect($state['groups']['trust']['fields'])->firstWhere('path', 'copy.home.trust.industries');

        $this->assertContains('Dog trainers', $trust['default'], 'reset returns to the dog examples, not the event ones');
        $this->assertTrue($trust['overridden']);
        $this->assertEqualsCanonicalizing(['copy.home.trust.industries', 'copy.home.hero.title'], $state['legacySamples']['pinned']);
    }

    public function test_the_cleanup_is_owner_only_and_scoped_to_its_own_agency(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        [$other, $otherOwner] = $this->agency('Agency B');
        $this->staleDraft($agency, $owner);
        $otherSite = $this->save($other, $otherOwner, ['copy.home.hero.title' => 'More bookings.']);

        $this->authenticateAs($otherOwner);
        $this->postJson($this->url($agency))->assertNotFound();

        // Agency B is untouched by anything Agency A did.
        $this->assertSame('More bookings.', AgencySiteManager::values($otherSite->fresh())['copy.home.hero.title']);
        $this->assertArrayNotHasKey('x.niche', AgencySiteManager::values($otherSite->fresh()));
    }

    public function test_general_means_the_cleanup_is_unavailable_and_nothing_is_changed(): void
    {
        [$agency, $owner] = $this->agency();
        $site = $this->staleDraft($agency, $owner, null);          // never chose an industry
        $before = AgencySiteManager::values($site->fresh());
        $this->authenticateAs($owner);

        $this->postJson($this->url($agency))->assertStatus(422);

        // The owner switched Dog Business -> General: the button is hidden and the endpoint refuses.
        $this->save($agency, $owner, ['x.niche' => 'dog_business']);
        $this->save($agency, $owner, ['x.niche' => 'general']);
        $draft = AgencySiteManager::values($site->fresh());
        $this->assertSame([], app(SiteEditorPresenter::class)->present($agency, $site->fresh())['legacySamples']['pinned']);
        $this->postJson($this->url($agency))->assertStatus(422);
        $this->assertSame($draft, AgencySiteManager::values($site->fresh()), 'a refused cleanup mutates nothing');
        $this->assertSame($before['copy.home.hero.title'], $draft['copy.home.hero.title']);
    }

    public function test_a_niche_sent_by_the_client_can_neither_pick_nor_override_the_saved_selection(): void
    {
        [$agency, $owner] = $this->agency();
        $site = $this->staleDraft($agency, $owner, 'general');
        $this->authenticateAs($owner);

        // A stale button/page claiming Dog Business is ignored while General is saved.
        $this->postJson($this->url($agency), ['niche' => 'dog_business'])->assertStatus(422);
        $this->assertSame('general', AgencySiteManager::values($site->fresh())['x.niche']);

        // The owner moves General -> Dog Business: the cleanup follows what is saved, whatever the request says.
        $this->save($agency, $owner, ['x.niche' => 'dog_business']);
        $this->postJson($this->url($agency), ['niche' => 'general'])->assertOk();
        $values = AgencySiteManager::values($site->fresh());
        $this->assertSame('dog_business', $values['x.niche'], 'the saved selection is never overwritten');
        $this->assertArrayNotHasKey('copy.home.trust.industries', $values);
    }

    public function test_a_niche_changed_in_another_tab_after_this_one_loaded_is_respected(): void
    {
        [$agency, $owner] = $this->agency();
        $stale = $this->staleDraft($agency, $owner, 'dog_business')->fresh();   // this tab still believes Dog Business

        $this->save($agency, $owner, ['x.niche' => 'general']);                // another tab switches to General
        $before = AgencySiteManager::values($stale->fresh());

        try {
            app(LegacySampleCleaner::class)->apply((int) $owner->user_id, $agency, $stale);
            $this->fail('General is saved: the cleanup must refuse');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('niche', $e->errors());
        }

        $this->assertSame($before, AgencySiteManager::values($stale->fresh()), 'nothing is deleted or overwritten');
    }

    public function test_custom_text_saved_concurrently_in_another_tab_is_never_deleted(): void
    {
        [$agency, $owner] = $this->agency();
        $stale = $this->staleDraft($agency, $owner)->fresh();   // what this tab loaded: hero.title is still the pinned copy

        // Another tab replaces the pinned headline with the owner's own words after that load.
        $this->save($agency, $owner, ['copy.home.hero.title' => 'Our own headline.']);

        $result = app(LegacySampleCleaner::class)->apply((int) $owner->user_id, $agency, $stale);

        $values = AgencySiteManager::values($stale->fresh());
        $this->assertSame('Our own headline.', $values['copy.home.hero.title'], 'the concurrent custom edit survives');
        $this->assertArrayNotHasKey('copy.home.trust.industries', $values, 'the still-pinned copy is reset');
        $this->assertSame(['copy.home.trust.industries'], array_keys($result['removed']));
        $this->assertSame('dog_business', $values['x.niche']);
    }

    public function test_the_workspace_write_lock_is_taken_before_the_draft_is_read(): void
    {
        [$agency, $owner] = $this->agency();
        $site = $this->staleDraft($agency, $owner)->fresh();

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries) { $queries[] = strtolower($q->sql); });
        app(LegacySampleCleaner::class)->apply((int) $owner->user_id, $agency, $site);

        $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'for update') && str_contains($sql, 'workspaces'));
        $draft = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, 'agency_sites'));

        $this->assertNotFalse($lock, 'the workspace row is locked');
        $this->assertNotFalse($draft, 'the draft is read');
        $this->assertLessThan($draft, $lock, 'lock first, then read the latest draft');
    }

    public function test_the_cleanup_refuses_a_non_owner_and_changes_nothing(): void
    {
        [$agency, $owner] = $this->agency('Agency A');
        [, $otherOwner] = $this->agency('Agency B');
        $site = $this->staleDraft($agency, $owner)->fresh();
        $before = AgencySiteManager::values($site);

        try {
            app(LegacySampleCleaner::class)->apply((int) $otherOwner->user_id, $agency, $site);
            $this->fail('a user who cannot manage the Agency must be refused');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->assertSame($before, AgencySiteManager::values($site->fresh()));
    }
}
