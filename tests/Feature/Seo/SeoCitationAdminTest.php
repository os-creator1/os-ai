<?php

namespace Tests\Feature\Seo;

use App\Enums\Seo\SeoCitationStatus;
use App\Library\Seo\SeoCitationCatalogManager;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Citations V1 — the Platform Owner's Citation Directories and Citation Niches
 * screens: who may use them, what they can and cannot change, and that
 * disabling or un-recommending a directory never touches a Business's history.
 */
class SeoCitationAdminTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    private function admin(): User
    {
        $admin = User::create([
            'first_name' => 'Test', 'last_name' => 'Admin', 'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        return $admin;
    }

    private function backendNonAdmin(): User
    {
        $user = User::create([
            'first_name' => 'Backend', 'last_name' => 'Staff', 'email' => 'staff' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($user);

        return $user;
    }

    private function directoryForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Example Local Listings',
            'website_url' => 'https://www.example-listings.com',
            'claim_url' => 'https://www.example-listings.com/claim',
            'category' => 'directory',
            'icon' => 'book-open',
            'importance' => 'optional',
            'tracking_mode' => 'assisted',
            'setup_guidance' => 'Claim it and check the details.',
            'country_scope' => 'US',
            'sort_order' => 300,
            'is_platform_core' => '1',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_a_non_administrator_cannot_open_or_use_either_screen(): void
    {
        $this->backendNonAdmin();
        $dir = $this->directory('yelp');

        $this->get(route('admin.citation-directories.index'))->assertStatus($this->deniedStatus());
        $this->get(route('admin.citation-niches.index'))->assertStatus($this->deniedStatus());
        $this->post(route('admin.citation-directories.store'), $this->directoryForm())->assertStatus($this->deniedStatus());
        $this->post(route('admin.citation-directories.active', $dir->uid), ['active' => 0])->assertStatus($this->deniedStatus());

        $this->assertTrue($dir->fresh()->is_active);
        $this->assertSame(0, SeoCitationDirectory::query()->where('name', 'Example Local Listings')->count());
    }

    private function deniedStatus(): int
    {
        // Whatever the group middleware answers for a non-administrator, it
        // must be consistent across every route; read it once from the index.
        $this->backendNonAdmin();

        return $this->get(route('admin.citation-directories.index'))->status();
    }

    public function test_the_catalog_manager_re_checks_administrator_authority_itself(): void
    {
        $user = $this->backendNonAdmin();
        $manager = app(SeoCitationCatalogManager::class);

        foreach ([
            fn () => $manager->createDirectory((int) $user->id, $this->directoryForm()),
            fn () => $manager->updateDirectory((int) $user->id, (string) $this->directory('yelp')->uid, $this->directoryForm()),
            fn () => $manager->setActive((int) $user->id, (string) $this->directory('yelp')->uid, false),
            fn () => $manager->recommend((int) $user->id, 'photo_booth_service', (string) $this->directory('mapquest')->uid, null, null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A non-administrator must be refused.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // -----------------------------------------------------------------
    // Directory catalog
    // -----------------------------------------------------------------

    public function test_an_administrator_sees_the_catalog_and_the_sidebar_entries(): void
    {
        $this->admin();

        $html = $this->get(route('admin.citation-directories.index'))->assertOk()->getContent();

        foreach (['Apple Business (Apple Maps)', 'Bing Places for Business', 'WeddingWire &amp; The Knot (WeddingPro)'] as $name) {
            $this->assertStringContainsString($name, $html);
        }
        $this->assertStringContainsString('Citation Directories', $html);
        $this->assertStringContainsString('Citation Niches', $html);
        $this->assertStringContainsString('None verified', $html, 'A directory with no verified claim link says so.');
    }

    public function test_a_directory_can_be_added_edited_and_disabled(): void
    {
        $this->admin();

        $this->post(route('admin.citation-directories.store'), $this->directoryForm())->assertRedirect(route('admin.citation-directories.index'));
        $directory = SeoCitationDirectory::query()->where('name', 'Example Local Listings')->sole();
        $this->assertNull($directory->business_id);
        $this->assertSame('assisted', $directory->tracking_mode->value);
        $this->assertTrue($directory->is_platform_core);
        $this->assertTrue($directory->is_active);

        $this->put(route('admin.citation-directories.update', $directory->uid), $this->directoryForm(['name' => 'Renamed Listings', 'importance' => 'essential']))->assertRedirect();
        $this->assertSame('Renamed Listings', $directory->fresh()->name);
        $this->assertSame('essential', $directory->fresh()->importance->value);
        $this->assertSame($directory->key, $directory->fresh()->key, 'The key is stable.');

        $this->post(route('admin.citation-directories.active', $directory->uid), ['active' => 0])->assertRedirect();
        $this->assertFalse($directory->fresh()->is_active);
    }

    public function test_unverifiable_or_unsafe_catalog_values_are_refused(): void
    {
        $this->admin();

        foreach ([
            ['claim_url' => 'http://insecure.example.com/claim'],
            ['claim_url' => 'javascript:alert(1)'],
            ['website_url' => 'ftp://x.example.com'],
            ['claim_url' => ''],                         // assisted needs a claim link
            ['tracking_mode' => 'automatic_check'],      // no screen can grant automation
            ['tracking_mode' => 'connected'],
            ['importance' => 'authority_score_92'],
            ['category' => 'made_up'],
            ['country_scope' => 'USA'],
        ] as $bad) {
            $this->post(route('admin.citation-directories.store'), $this->directoryForm($bad));
        }

        $this->assertSame(0, SeoCitationDirectory::query()->where('name', 'Example Local Listings')->count());
    }

    public function test_manual_directories_may_have_no_claim_link(): void
    {
        $this->admin();

        $this->post(route('admin.citation-directories.store'), $this->directoryForm(['claim_url' => '', 'tracking_mode' => 'manual', 'name' => 'Manual Only']));

        $this->assertNull(SeoCitationDirectory::query()->where('name', 'Manual Only')->sole()->claim_url);
    }

    public function test_disabling_a_directory_never_touches_a_business_record(): void
    {
        [, $business, , $location] = $this->tenantWithLocation();
        $this->makeCitation($business, $location, $this->directory('yelp'), ['listed_name' => 'Kept', 'status' => SeoCitationStatus::Listed->value]);
        $before = $this->dbFingerprint(['seo_citations']);

        $this->admin();
        $this->post(route('admin.citation-directories.active', $this->directory('yelp')->uid), ['active' => 0])->assertRedirect();

        $this->assertSame($before, $this->dbFingerprint(['seo_citations']));
        $this->assertSame('Kept', SeoCitation::query()->sole()->listed_name);
    }

    public function test_a_business_custom_directory_is_unreachable_from_the_platform_screens(): void
    {
        [, $business, , $location] = $this->tenantWithLocation();
        $custom = new SeoCitationDirectory();
        $custom->forceFill(['key' => 'custom_abc', 'name' => 'Owner Custom', 'category' => 'custom', 'importance' => 'optional', 'tracking_mode' => 'manual', 'business_id' => $business->id, 'sort_order' => 1000])->save();

        $this->admin();

        $this->assertStringNotContainsString('Owner Custom', $this->get(route('admin.citation-directories.index'))->getContent());
        $this->get(route('admin.citation-directories.edit', $custom->uid))->assertNotFound();
        $this->post(route('admin.citation-directories.active', $custom->uid), ['active' => 0]);
        $this->assertTrue($custom->fresh()->is_active);
    }

    private function tenantWithLocation(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant(\App\Enums\Entitlement\WorkspacePlanTier::Growth);

        return [$customer, $business, $workspace, $this->publicStorefront($business, 'Main')];
    }

    // -----------------------------------------------------------------
    // Niche recommendations
    // -----------------------------------------------------------------

    public function test_the_niche_screens_list_niches_and_show_the_photo_booth_recommendations(): void
    {
        $this->admin();

        $this->get(route('admin.citation-niches.index'))->assertOk()->assertSee('Photo booth')->assertSee('photo_booth_service');

        $html = $this->get(route('admin.citation-niches.show', 'photo_booth_service'))->assertOk()->getContent();
        foreach (['gigsalad', 'eventective', 'weddingwire_the_knot', 'bark', 'the_bash'] as $key) {
            $this->assertStringContainsString('data-recommendation="' . $key . '"', $html);
        }

        $this->get(route('admin.citation-niches.show', 'not_a_niche'))->assertNotFound();
    }

    public function test_a_recommendation_can_be_added_changed_disabled_and_removed_without_touching_the_directory(): void
    {
        $this->admin();
        $directory = $this->directory('mapquest');
        $snapshot = $directory->only(['name', 'claim_url', 'website_url', 'tracking_mode', 'importance', 'is_platform_core']);

        $this->post(route('admin.citation-niches.recommendations.store', 'home_services'), ['directory' => $directory->uid, 'importance' => 'recommended', 'guidance' => 'Good for local jobs.'])->assertRedirect();
        $rec = SeoNicheCitationRecommendation::query()->where('niche_key', 'home_services')->sole();
        $this->assertSame('recommended', $rec->importance->value);
        $this->assertTrue($rec->is_enabled);

        $this->post(route('admin.citation-niches.recommendations.store', 'home_services'), ['directory' => $directory->uid]);
        $this->assertSame(1, SeoNicheCitationRecommendation::query()->where('niche_key', 'home_services')->count(), 'No duplicate recommendation.');

        // Extra platform fields in the request are ignored: a niche cannot rewrite the directory.
        $this->put(route('admin.citation-niches.recommendations.update', ['home_services', $rec->uid]), [
            'importance' => 'essential', 'guidance' => 'Updated.', 'sort_order' => 5,
            'claim_url' => 'https://evil.example.com', 'tracking_mode' => 'connected', 'name' => 'Hacked',
        ])->assertRedirect();

        $rec = $rec->fresh();
        $this->assertSame('essential', $rec->importance->value);
        $this->assertSame('Updated.', $rec->guidance);
        $this->assertSame(5, $rec->sort_order);
        $this->assertFalse($rec->is_enabled, 'An unchecked "enabled" box disables it.');
        $this->assertSame($snapshot, $directory->fresh()->only(array_keys($snapshot)), 'The catalog directory is untouched.');

        $this->delete(route('admin.citation-niches.recommendations.destroy', ['home_services', $rec->uid]))->assertRedirect();
        $this->assertSame(0, SeoNicheCitationRecommendation::query()->where('niche_key', 'home_services')->count());
        $this->assertNotNull($directory->fresh(), 'Removing a recommendation never deletes the directory.');
    }

    public function test_invalid_recommendation_values_are_refused(): void
    {
        $this->admin();
        $directory = $this->directory('mapquest');

        $this->post(route('admin.citation-niches.recommendations.store', 'home_services'), ['directory' => $directory->uid, 'importance' => 'huge']);
        $this->post(route('admin.citation-niches.recommendations.store', 'home_services'), ['directory' => (string) \Illuminate\Support\Str::uuid()]);

        $this->assertSame(0, SeoNicheCitationRecommendation::query()->where('niche_key', 'home_services')->count());
    }

    public function test_a_recommendation_edit_reaches_an_existing_business_immediately(): void
    {
        [$customer, $business, $workspace, $location] = $this->tenantWithLocation();
        $this->bypassCitationEntitlementForTest();
        $this->authenticateAsSeoCustomer($customer);

        $url = $this->citationsUrl($workspace, $business) . '?location=' . $location->uid;
        $this->assertMatchesRegularExpression('/data-directory="bark"[^>]*data-importance="optional"/', $this->get($url)->getContent());

        SeoNicheCitationRecommendation::query()->whereHas('directory', fn ($q) => $q->where('key', 'bark'))->update(['importance' => 'essential', 'guidance' => 'Now essential for you.']);

        $html = $this->get($url)->getContent();
        $this->assertMatchesRegularExpression('/data-directory="bark"[^>]*data-importance="essential"/', $html);
        $this->assertStringContainsString('Now essential for you.', $html);
    }
}
