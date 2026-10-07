<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Seo\SeoKeywordCoverageStatus;
use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Seo\SeoKeywordSuggester;
use App\Models\Business;
use App\Models\BusinessBlueprintComponentInstallation;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\SeoKeyword;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * SEO V1 final (A4, A5 view) — "Suggested keywords" on the Keywords page.
 *
 * The Niche Blueprint SEO strategy (BlueprintConfigReader::seoStrategy) had no
 * consumer. It now feeds a short list of SUGGESTIONS, filled ONLY with the
 * Business's own services and the cities of the Locations the actor may see —
 * never invented, never permuted into spam, never saved until the owner presses
 * Add (an ordinary keyword add through SeoKeywordManager and its existing
 * tenancy, permission, Location-access and ceiling rules).
 */
class SeoKeywordSuggestionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    /** Replaces the Blueprint seam with a fixed strategy; everything else is production code. */
    private function strategy(array $patterns): void
    {
        $reader = Mockery::mock(BlueprintConfigReader::class);
        $reader->shouldReceive('seoStrategy')->andReturn(['keyword_patterns' => $patterns, 'faq_topics' => [], 'schema_types' => [], 'internal_links' => []]);
        $this->app->instance(BlueprintConfigReader::class, $reader);
    }

    private function patterns(string ...$patterns): array
    {
        return array_map(fn (string $p) => ['pattern' => $p, 'intent' => 'transactional'], $patterns);
    }

    private function service(Business $business, string $name, string $status = 'active', int $order = 0): BusinessService
    {
        return BusinessService::create([
            'business_id' => $business->id, 'name' => $name, 'slug' => str($name)->slug()->toString(),
            'status' => $status, 'sort_order' => $order,
        ]);
    }

    /** @return array<int, string> */
    private function phrases(Business $business, ?int $actorId = null, array $locations = null, int $active = 0): array
    {
        $locations ??= BusinessLocation::query()->where('business_id', $business->id)->get()->all();

        return array_map(
            fn ($s) => $s->phrase,
            app(SeoKeywordSuggester::class)->suggest($business, collect($locations), SeoKeyword::query()->where('business_id', $business->id)->get(), $active)
        );
    }

    private function keywordsUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.seo.keywords.index', [$workspace->uid, $business->uid]);
    }

    // -----------------------------------------------------------------
    // Filled with real data only
    // -----------------------------------------------------------------

    public function test_patterns_are_filled_only_with_the_businesss_real_cities_and_services(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['name' => 'Naperville Studio', 'city' => 'Naperville', 'service_area_cities' => ['Aurora', 'naperville']]);
        $this->service($business, 'Photo Booth Rental');
        $this->service($business, 'Retired Package', 'inactive');

        $this->strategy($this->patterns(
            'photo booth rental {city}',
            '{service} for events',
            'how much does a photo booth cost',
            'dj {state}',
            '{missing}',
        ));

        $this->assertSame([
            'photo booth rental Naperville',
            'Photo Booth Rental for events',
            'how much does a photo booth cost',
            'photo booth rental Aurora',
        ], $this->phrases($business), 'Naperville appears once (case-insensitive); the inactive service and unknown placeholders are never used.');
    }

    public function test_a_city_fill_is_attributed_to_the_location_it_came_from_and_a_service_fill_is_business_wide(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['name' => 'Naperville Studio', 'city' => 'Naperville']);
        $this->service($business, 'Photo Booth Rental');
        $this->strategy($this->patterns('photo booth {city}', '{service} deals'));

        $suggestions = app(SeoKeywordSuggester::class)->suggest($business, collect([$location]), collect(), 0);

        $this->assertSame('photo booth Naperville', $suggestions[0]->phrase);
        $this->assertSame($location->uid, $suggestions[0]->locationUid);
        $this->assertSame('Naperville Studio', $suggestions[0]->locationName);
        $this->assertNull($suggestions[1]->locationUid);
        $this->assertSame('Ready to book', $suggestions[0]->intentLabel());
    }

    public function test_a_pattern_whose_placeholder_has_no_real_data_is_skipped_not_guessed(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        // A Location with no city and no service area; no services at all.
        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['city' => null, 'address_line_1' => null]);
        $this->strategy($this->patterns('photo booth rental {city}', '{service} near me', 'photo booth near me'));

        $this->assertSame(['photo booth near me'], $this->phrases($business), 'Only the pattern that needs nothing real survives.');
    }

    public function test_no_blueprint_strategy_means_no_suggestions(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $reader = Mockery::mock(BlueprintConfigReader::class);
        $reader->shouldReceive('seoStrategy')->andReturn(null);
        $this->app->instance(BlueprintConfigReader::class, $reader);

        $this->assertSame([], $this->phrases($business));
    }

    // -----------------------------------------------------------------
    // Not spam
    // -----------------------------------------------------------------

    public function test_suggestions_are_deduplicated_against_every_existing_keyword_by_the_one_normalization(): void
    {
        [$customer, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $manager = app(SeoKeywordManager::class);
        $manager->create((int) $customer->user_id, $business, 'Photo  Booth   RENTAL Naperville');
        $archived = $manager->create((int) $customer->user_id, $business, 'wedding photo booth Naperville');
        $manager->archive((int) $customer->user_id, $business, $archived);
        $this->strategy($this->patterns('photo booth rental {city}', 'wedding photo booth {city}', 'corporate photo booth {city}'));

        $this->assertSame(['corporate photo booth Naperville'], $this->phrases($business, null, [$location]), 'Active and archived keywords both count as already there.');
    }

    public function test_the_list_is_capped_per_pattern_and_in_total_and_interleaves_patterns(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, [
            'city' => 'Alpha', 'service_area_cities' => ['Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta'],
        ]);

        // One pattern, eight cities: three fills at most.
        $this->strategy($this->patterns('photo booth {city}'));
        $this->assertSame(['photo booth Alpha', 'photo booth Beta', 'photo booth Gamma'], $this->phrases($business));

        // Many patterns: ten in total, taken round-robin so the first pattern cannot crowd out the rest.
        $this->strategy($this->patterns('a {city}', 'b {city}', 'c {city}', 'd {city}', 'e {city}'));
        $phrases = $this->phrases($business);

        $this->assertCount(10, $phrases);
        $this->assertSame(['a Alpha', 'b Alpha', 'c Alpha', 'd Alpha', 'e Alpha', 'a Beta', 'b Beta', 'c Beta', 'd Beta', 'e Beta'], $phrases);
    }

    public function test_nothing_is_suggested_once_the_keyword_ceiling_is_reached_and_room_limits_the_list(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['city' => 'Alpha', 'service_area_cities' => ['Beta', 'Gamma']]);
        $this->strategy($this->patterns('a {city}', 'b {city}', 'c {city}'));

        $this->assertSame([], $this->phrases($business, null, null, 50), 'At the 50-keyword ceiling there is no room.');
        $this->assertCount(2, $this->phrases($business, null, null, 48), 'Only two more fit.');
    }

    public function test_a_filled_phrase_with_an_operator_or_markup_is_dropped(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['city' => 'Okay City', 'service_area_cities' => ['site:evil.example', '"Quoted"', 'Plain Town']]);
        $this->strategy($this->patterns('photo booth {city}', 'photo booth OR kiosk'));

        $this->assertSame(['photo booth Okay City', 'photo booth Plain Town'], $this->phrases($business));
    }

    // -----------------------------------------------------------------
    // The Keywords page
    // -----------------------------------------------------------------

    public function test_the_page_shows_suggestions_and_viewing_them_creates_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        app(SeoKeywordManager::class)->create((int) $customer->user_id, $business, 'existing keyword');
        $this->strategy($this->patterns('photo booth rental {city}', 'wedding photo booth {city}'));
        $this->authenticateAsSeoCustomer($customer);
        $before = $this->dbFingerprint(['seo_keywords']);

        $html = $this->get($this->keywordsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-section="suggested-keywords"', $html);
        $this->assertStringContainsString('photo booth rental Naperville', $html);
        $this->assertStringContainsString('wedding photo booth Naperville', $html);
        $this->assertStringContainsString('Nothing is added until you choose it', $html);
        $this->assertSame(2, substr_count($html, 'data-role="suggestion-add"'));
        $this->assertSame($before, $this->dbFingerprint(['seo_keywords']), 'Rendering suggestions never writes or changes a keyword.');
    }

    public function test_pressing_add_is_the_ordinary_keyword_add_and_the_suggestion_then_disappears(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $location = $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['name' => 'Naperville Studio', 'city' => 'Naperville']);
        $this->strategy($this->patterns('photo booth rental {city}'));
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->keywordsUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('action="' . route('customer.workspaces.businesses.seo.keywords.store', [$workspace->uid, $business->uid]) . '"', $html);
        $this->assertStringContainsString('name="phrase" value="photo booth rental Naperville"', $html);
        $this->assertStringContainsString('name="location_uid" value="' . $location->uid . '"', $html);

        $this->post(route('customer.workspaces.businesses.seo.keywords.store', [$workspace->uid, $business->uid]), [
            'phrase' => 'photo booth rental Naperville', 'location_uid' => $location->uid,
        ])->assertRedirect()->assertSessionHas('status', 'success');

        $keyword = SeoKeyword::query()->sole();
        $this->assertSame('photo booth rental Naperville', $keyword->phrase);
        $this->assertSame((int) $location->id, (int) $keyword->business_location_id);
        $this->assertSame('manual', $keyword->source);

        $this->assertStringNotContainsString('data-section="suggested-keywords"', $this->get($this->keywordsUrl($workspace, $business))->getContent(), 'Added, so nothing is left to suggest.');
    }

    public function test_a_viewer_without_manage_seo_is_not_shown_an_add_button(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $this->strategy($this->patterns('photo booth rental {city}'));
        $this->authenticateAsSeoCustomer($customer, ['view_seo']);

        $html = $this->get($this->keywordsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('suggested-keywords', $html);
        $this->assertStringNotContainsString('photo booth rental Naperville', $html);
    }

    public function test_a_selected_scope_member_is_only_offered_the_cities_of_the_locations_they_can_access(): void
    {
        [, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $mine = $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['name' => 'Mine', 'city' => 'Naperville']);
        $this->extraLocation($business, 'Secret Site', ['city' => 'Hiddenville', 'service_area_cities' => ['Shadowtown']]);
        $member = $this->selectedScopeMember($workspace, [$mine]);
        $this->strategy($this->patterns('photo booth {city}'));
        $this->authenticateAsSeoCustomer($member);

        $html = $this->get($this->keywordsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('photo booth Naperville', $html);
        $this->assertStringNotContainsString('Hiddenville', $html);
        $this->assertStringNotContainsString('Shadowtown', $html);
        $this->assertStringNotContainsString('Secret Site', $html);
    }

    public function test_an_archived_location_contributes_no_city(): void
    {
        [, $business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $active = $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['city' => 'Naperville']);
        $archived = $this->extraLocation($business, 'Closed Site', ['city' => 'Oldtown']);
        DB::table('business_locations')->where('id', $archived->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);
        $this->strategy($this->patterns('photo booth {city}'));
        $this->authenticateAsSeoCustomer($business->customer ?? \App\Models\Customer::query()->firstOrFail());

        // Through the controller's own active-Location filter.
        $this->assertSame(
            ['photo booth Naperville'],
            array_map(fn ($s) => $s->phrase, app(SeoKeywordSuggester::class)->suggest(
                $business,
                BusinessLocation::query()->where('business_id', $business->id)->get()->filter(fn ($l) => $l->isActive())->values(),
                collect(),
                0
            ))
        );
        $this->assertSame((int) $active->id, (int) BusinessLocation::query()->where('city', 'Naperville')->value('id'));
    }

    // -----------------------------------------------------------------
    // A5 — the page's coverage badge for a phrase on a hidden page
    // -----------------------------------------------------------------

    public function test_a_keyword_only_on_a_hidden_page_shows_that_instead_of_covered(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publishWebsite($business, [
            $this->snapshotPage('a', 'Home', [], [['type' => 'text', 'data' => ['heading' => null, 'body' => 'We rent photo booths.']]], true),
            $this->snapshotPage('b', 'Private', ['noindex' => true], [['type' => 'text', 'data' => ['heading' => null, 'body' => 'Neon sign hire for parties.']]]),
        ]);
        $manager = app(SeoKeywordManager::class);
        $manager->create((int) $customer->user_id, $business, 'photo booths');
        $hidden = $manager->create((int) $customer->user_id, $business, 'neon sign hire');
        $this->strategy([]);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->keywordsUrl($workspace, $business))->assertOk()->getContent();

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $xp = new \DOMXPath($doc);
        $status = fn (string $uid) => $xp->query("//tr[@data-uid='{$uid}']//*[@data-role='keyword-coverage']/@data-status")->item(0)?->nodeValue;

        $this->assertSame(SeoKeywordCoverageStatus::OnlyOnHiddenPages->value, $status($hidden->uid));
        $this->assertStringContainsString('Only on pages hidden from search', $html);
        $this->assertSame(SeoKeywordCoverageStatus::Covered->value, $status(SeoKeyword::query()->where('phrase', 'photo booths')->value('uid')));
    }

    // -----------------------------------------------------------------
    // Reading the REAL Blueprint seam (Photo Booth V2 strategy)
    // -----------------------------------------------------------------

    public function test_the_real_photo_booth_strategy_reaches_the_page(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $adminId = $this->platformAdminId();
        User::query()->whereKey($adminId)->update(['is_admin' => true]);
        (new WebsiteTemplateSeeder())->run();
        $this->artisan('blueprint:seed-photo-booth', ['--actor' => $adminId])->assertExitCode(0);
        $this->assertSame(0, Artisan::call('documents:seed-photo-booth-templates', ['--actor' => $adminId]), Artisan::output());
        $this->artisan('blueprint:seed-photo-booth-v2', ['--actor' => $adminId])->assertExitCode(0);

        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->assertTrue(
            BusinessBlueprintComponentInstallation::query()->where('business_id', $business->id)->where('component_type', 'seo_strategy')->exists(),
            'The Business was provisioned with the niche SEO strategy.'
        );
        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, ['name' => 'Studio', 'city' => 'Naperville']);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get($this->keywordsUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('photo booth rental Naperville', $html);
        $this->assertStringContainsString('wedding photo booth Naperville', $html);
        $this->assertStringContainsString('how much does a photo booth cost', $html);
        $this->assertSame(0, SeoKeyword::query()->count(), 'Suggestions are never saved on their own.');
        $this->assertNotNull(Workspace::query()->find($workspace->id));
    }
}
