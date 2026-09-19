<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Seo\SeoKeywordManager;
use App\Library\Seo\SeoOverview;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\SeoKeyword;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice D — the SEO keyword surface over HTTP: fail-closed
 * while SeoBasicVisibility is Planned (real controller), and — through the
 * entitlement-bypass subclass — capability gates, Location ACL, guessed uids,
 * aggregate inference, output safety, side effects and query budgets.
 */
class SeoKeywordsHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoFixtures;

    private function manager(): SeoKeywordManager
    {
        return app(SeoKeywordManager::class);
    }

    private function url(string $action, Workspace $workspace, Business $business, ?SeoKeyword $keyword = null): string
    {
        $parameters = [$workspace->uid, $business->uid];

        if ($keyword !== null) {
            $parameters[] = $keyword->uid;
        }

        return route("customer.workspaces.businesses.seo.keywords.{$action}", $parameters);
    }

    private function keywordSnapshot(): string
    {
        return md5(DB::table('seo_keywords')->orderBy('id')->get()->toJson());
    }

    private function make(Customer $actor, Business $business, string $phrase, ?BusinessLocation $location = null): SeoKeyword
    {
        return $this->manager()->create((int) $actor->user_id, $business, $phrase, $location);
    }

    // -----------------------------------------------------------------
    // FAIL CLOSED while SeoBasicVisibility is Planned (the REAL controller).
    // -----------------------------------------------------------------

    public function test_every_keyword_route_is_a_404_for_every_tier_while_the_feature_is_planned(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$owner, $business, $workspace] = $this->entitledTenant($tier);
            $location = $this->createLocation($business);
            $keyword = $this->make($owner, $business, 'best bakery');
            $this->authenticateAsSeoCustomer($owner);
            $before = $this->keywordSnapshot();

            $this->get($this->url('index', $workspace, $business))->assertNotFound();

            // A VALID payload and an INVALID one must both be 404: validation
            // must never run ahead of the tenancy/entitlement chain.
            foreach ([['phrase' => 'new phrase'], ['phrase' => ''], [], ['phrase' => str_repeat('x', 500)], ['phrase' => 'p', 'location_uid' => $location->uid]] as $payload) {
                $this->post($this->url('store', $workspace, $business), $payload)->assertNotFound();
                $this->post($this->url('update', $workspace, $business, $keyword), $payload)->assertNotFound();
            }

            $this->post($this->url('archive', $workspace, $business, $keyword))->assertNotFound();
            $this->post($this->url('reactivate', $workspace, $business, $keyword))->assertNotFound();

            $this->assertSame($before, $this->keywordSnapshot(), 'Nothing may be written while the feature is Planned.');
        }
    }

    public function test_a_planned_feature_answers_the_same_404_with_or_without_the_capabilities(): void
    {
        [$owner, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($business);
        $keyword = $this->make($owner, $business, 'best bakery');

        foreach ([$this->seoPermissions(), ['view_google_business_profile'], []] as $permissions) {
            $this->authenticateAsSeoCustomer($owner, $permissions);

            $this->get($this->url('index', $workspace, $business))->assertNotFound();
            $this->post($this->url('store', $workspace, $business), ['phrase' => 'x'])->assertNotFound();
            $this->post($this->url('archive', $workspace, $business, $keyword))->assertNotFound();
        }
    }

    public function test_a_stranger_gets_the_same_404_while_planned(): void
    {
        [$owner, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $keyword = $this->make($owner, $business, 'best bakery');
        $this->authenticateAsSeoCustomer($this->createCustomer());

        $this->get($this->url('index', $workspace, $business))->assertNotFound();
        $this->post($this->url('update', $workspace, $business, $keyword), ['phrase' => 'hacked'])->assertNotFound();
        $this->assertSame('best bakery', $keyword->fresh()->phrase);
    }

    // -----------------------------------------------------------------
    // Reading — through the entitlement bypass (everything else is production code).
    // -----------------------------------------------------------------

    public function test_the_index_lists_keywords_with_coverage_and_the_manage_forms(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $this->make($owner, $business, 'Best Bakery');
        $this->make($owner, $business, 'plumber', $location);
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', ['seo_title' => 'The best bakery'], [], true)]);
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->get($this->url('index', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Search keywords', $html);
        $this->assertStringContainsString('Best Bakery', $html);
        $this->assertStringContainsString('Found on your website', $html);
        $this->assertStringContainsString('Not found on your website yet', $html);
        $this->assertStringContainsString('In 1 page title', $html);
        $this->assertStringContainsString('Whole business', $html);
        $this->assertStringContainsString($location->name, $html);
        $this->assertStringContainsString('data-role="keyword-add-form"', $html);
        $this->assertStringContainsString('data-role="keyword-archive"', $html);
        $this->assertStringContainsString('This does not show search rankings.', $html);
    }

    public function test_without_a_published_website_coverage_says_to_publish(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        $this->make($owner, $business, 'best bakery');
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->get($this->url('index', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Publish your website to check', $html);
    }

    public function test_view_seo_alone_reads_but_cannot_see_or_use_the_write_controls(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        $keyword = $this->make($owner, $business, 'best bakery');
        $this->authenticateAsSeoCustomer($owner, ['view_seo']);

        $html = $this->get($this->url('index', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('best bakery', $html);
        $this->assertStringNotContainsString('data-role="keyword-add-form"', $html);
        $this->assertStringNotContainsString('data-role="keyword-archive"', $html);
        $this->assertStringNotContainsString('data-role="keyword-edit-form"', $html);

        $this->post($this->url('store', $workspace, $business), ['phrase' => 'x'])->assertUnauthorized();
        $this->post($this->url('update', $workspace, $business, $keyword), ['phrase' => 'x'])->assertUnauthorized();
        $this->post($this->url('archive', $workspace, $business, $keyword))->assertUnauthorized();
        $this->post($this->url('reactivate', $workspace, $business, $keyword))->assertUnauthorized();
        $this->assertTrue($keyword->fresh()->isActive());
        $this->assertSame(1, SeoKeyword::query()->count());
    }

    public function test_without_view_seo_the_index_is_refused(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        $this->authenticateAsSeoCustomer($owner, ['manage_seo', 'website']);

        $this->get($this->url('index', $workspace, $business))->assertUnauthorized();
    }

    public function test_the_legacy_keywords_permission_grants_nothing_here(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        $keyword = $this->make($owner, $business, 'best bakery');
        $this->authenticateAsSeoCustomer($owner, ['view_keywords']);

        $this->get($this->url('index', $workspace, $business))->assertUnauthorized();
        $this->post($this->url('archive', $workspace, $business, $keyword))->assertUnauthorized();
        $this->assertTrue(Route::has('customer.keywords.index'), 'The legacy route is untouched.');
    }

    public function test_the_page_speaks_of_search_keywords_never_text_in_keywords(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->get($this->url('index', $workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsStringIgnoringCase('text in', $html);
        $this->assertStringNotContainsString('Words people can text in', $html);
        $this->assertStringNotContainsString(route('customer.keywords.index'), substr($html, (int) strpos($html, 'data-section="keywords"')));
    }

    public function test_customer_supplied_strings_are_escaped(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $location] = $this->growthTenantWithLocation();
        DB::table('business_locations')->where('id', $location->id)->update(['name' => '<img src=x onerror=alert(2)>']);
        $this->make($owner, $business, '<script>alert(1)</script>', $location->fresh());
        $this->authenticateAsSeoCustomer($owner);

        $html = $this->get($this->url('index', $workspace, $business))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // -----------------------------------------------------------------
    // Writing — happy paths and refusals.
    // -----------------------------------------------------------------

    public function test_store_creates_a_keyword_and_returns_to_the_index(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('store', $workspace, $business), ['phrase' => '  Best  Bakery ', 'location_uid' => $location->uid])
            ->assertRedirect($this->url('index', $workspace, $business))
            ->assertSessionHas('status', 'success');

        $keyword = SeoKeyword::query()->sole();
        $this->assertSame('Best  Bakery', $keyword->phrase);
        $this->assertSame('best bakery', $keyword->phrase_normalized);
        $this->assertSame((int) $location->id, (int) $keyword->business_location_id);
        $this->assertSame((int) $business->id, (int) $keyword->business_id);
    }

    public function test_store_business_wide_when_no_location_is_chosen(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('store', $workspace, $business), ['phrase' => 'best bakery', 'location_uid' => ''])->assertRedirect();

        $this->assertNull(SeoKeyword::query()->sole()->business_location_id);
    }

    public function test_input_is_validated_after_the_chain_and_writes_nothing(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('store', $workspace, $business), ['phrase' => ''])->assertSessionHasErrors('phrase');
        $this->post($this->url('store', $workspace, $business), ['phrase' => str_repeat('a', 121)])->assertSessionHasErrors('phrase');
        $this->post($this->url('store', $workspace, $business), [])->assertSessionHasErrors('phrase');
        $this->post($this->url('store', $workspace, $business), ['phrase' => ['array']])->assertSessionHasErrors('phrase');

        // A phrase the manager rejects (control characters) is a calm flash, not a 500.
        $this->post($this->url('store', $workspace, $business), ['phrase' => "bad\x07phrase"])
            ->assertRedirect($this->url('index', $workspace, $business))
            ->assertSessionHas('status', 'error');

        $this->assertSame(0, SeoKeyword::query()->count());
    }

    public function test_a_duplicate_and_the_ceiling_are_calm_refusals(): void
    {
        $this->bypassSeoEntitlementForTest();
        config(['seo.keywords.max_active_per_business' => 2]);
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('store', $workspace, $business), ['phrase' => 'one'])->assertSessionHas('status', 'success');
        $this->post($this->url('store', $workspace, $business), ['phrase' => 'ONE'])
            ->assertRedirect($this->url('index', $workspace, $business))
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', 'You already have this keyword for that location. It may be archived; if so, reactivate it instead.');

        $this->post($this->url('store', $workspace, $business), ['phrase' => 'two'])->assertSessionHas('status', 'success');
        $this->post($this->url('store', $workspace, $business), ['phrase' => 'three'])
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', 'You have reached the limit of active keywords. Archive one to add another.');

        $this->assertSame(2, SeoKeyword::query()->count());
    }

    public function test_update_archive_and_reactivate_work_through_http(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $keyword = $this->make($owner, $business, 'best bakery');
        $this->authenticateAsSeoCustomer($owner);

        $this->post($this->url('update', $workspace, $business, $keyword), ['phrase' => 'Best Bakery Shop', 'location_uid' => $location->uid])
            ->assertRedirect($this->url('index', $workspace, $business))->assertSessionHas('status', 'success');
        $this->assertSame('best bakery shop', $keyword->fresh()->phrase_normalized);
        $this->assertSame((int) $location->id, (int) $keyword->fresh()->business_location_id);

        $this->post($this->url('archive', $workspace, $business, $keyword))->assertSessionHas('status', 'success');
        $this->assertFalse($keyword->fresh()->isActive());

        $html = $this->get($this->url('index', $workspace, $business))->getContent();
        $this->assertStringContainsString('Archived', $html);
        $this->assertStringContainsString('data-role="keyword-reactivate"', $html);
        $this->assertStringNotContainsString('data-role="keyword-edit-form"', $html, 'An archived keyword cannot be edited.');

        $this->post($this->url('update', $workspace, $business, $keyword), ['phrase' => 'nope'])
            ->assertSessionHas('status', 'error');

        $this->post($this->url('reactivate', $workspace, $business, $keyword))->assertSessionHas('status', 'success');
        $this->assertTrue($keyword->fresh()->isActive());
    }

    // -----------------------------------------------------------------
    // Guessed identifiers: indistinguishable from missing ones; nothing changes.
    // -----------------------------------------------------------------

    public function test_a_guessed_foreign_keyword_uid_is_a_404_and_changes_nothing(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace] = $this->growthTenantWithLocation();
        [$otherOwner, $otherBusiness] = $this->growthTenantWithLocation();
        $foreign = $this->make($otherOwner, $otherBusiness, 'their phrase');
        $this->authenticateAsSeoCustomer($owner);
        $before = $this->keywordSnapshot();

        $this->post($this->url('update', $workspace, $business, $foreign), ['phrase' => 'mine now'])->assertNotFound();
        $this->post($this->url('archive', $workspace, $business, $foreign))->assertNotFound();
        $this->post($this->url('reactivate', $workspace, $business, $foreign))->assertNotFound();

        // The foreign keyword addressed through the FOREIGN Business is also refused for this actor.
        $foreignWorkspace = Workspace::query()->findOrFail($otherBusiness->workspace_id);
        $this->post($this->url('archive', $foreignWorkspace, $otherBusiness, $foreign))->assertNotFound();

        $this->post(route('customer.workspaces.businesses.seo.keywords.archive', [$workspace->uid, $business->uid, 'no-such-keyword']))->assertNotFound();

        $this->assertSame($before, $this->keywordSnapshot());
    }

    public function test_a_guessed_or_inaccessible_location_uid_is_a_404_and_creates_nothing(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $granted] = $this->growthTenantWithLocation();
        $ungranted = $this->extraLocation($business, 'Ungranted Site');
        [, $otherBusiness] = $this->growthTenantWithLocation();
        $foreignLocation = $this->createLocation($otherBusiness);

        $member = $this->selectedScopeMember($workspace, [$granted]);
        $this->authenticateAsSeoCustomer($member);

        foreach ([$ungranted->uid, $foreignLocation->uid, 'no-such-location'] as $uid) {
            $this->post($this->url('store', $workspace, $business), ['phrase' => 'sneaky', 'location_uid' => $uid])->assertNotFound();
        }

        $this->assertSame(0, SeoKeyword::query()->count());

        // The granted Location works.
        $this->post($this->url('store', $workspace, $business), ['phrase' => 'fine', 'location_uid' => $granted->uid])->assertSessionHas('status', 'success');
        $this->assertSame(1, SeoKeyword::query()->count());
    }

    public function test_an_ungranted_locations_keyword_cannot_be_mutated_by_guessing_its_uid(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $granted] = $this->growthTenantWithLocation();
        $ungranted = $this->extraLocation($business, 'Ungranted Site');
        $hidden = $this->make($owner, $business, 'hidden phrase', $ungranted);
        $mine = $this->make($owner, $business, 'my phrase', $granted);

        $member = $this->selectedScopeMember($workspace, [$granted]);
        $this->authenticateAsSeoCustomer($member);
        $before = $this->keywordSnapshot();

        $this->post($this->url('update', $workspace, $business, $hidden), ['phrase' => 'renamed'])->assertNotFound();
        $this->post($this->url('archive', $workspace, $business, $hidden))->assertNotFound();
        $this->post($this->url('reactivate', $workspace, $business, $hidden))->assertNotFound();
        // Moving a keyword the member CAN see onto the ungranted Location is refused as well.
        $this->post($this->url('update', $workspace, $business, $mine), ['phrase' => 'my phrase', 'location_uid' => $ungranted->uid])->assertNotFound();

        $this->assertSame($before, $this->keywordSnapshot(), 'A guessed uid must never change a row.');
    }

    // -----------------------------------------------------------------
    // Aggregate inference: filter first, count second.
    // -----------------------------------------------------------------

    public function test_an_ungranted_locations_keywords_and_name_never_reach_the_page(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $granted] = $this->growthTenantWithLocation();
        $ungranted = $this->extraLocation($business, 'Secret Vault Branch');
        $this->make($owner, $business, 'wide phrase');
        $this->make($owner, $business, 'granted phrase', $granted);
        $this->make($owner, $business, 'top secret phrase', $ungranted);

        $member = $this->selectedScopeMember($workspace, [$granted]);
        $this->authenticateAsSeoCustomer($member);

        $html = $this->get($this->url('index', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('wide phrase', $html);
        $this->assertStringContainsString('granted phrase', $html);
        $this->assertStringNotContainsString('top secret phrase', $html);
        $this->assertStringNotContainsString('Secret Vault Branch', $html);
        $this->assertStringNotContainsString($ungranted->uid, $html, 'Neither its name nor its uid may appear, not even in the Location selector.');
        $this->assertSame(2, substr_count($html, 'data-role="keyword"'), 'Only the two visible keywords are listed.');
    }

    public function test_the_overview_keyword_item_counts_only_visible_keywords(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $granted] = $this->growthTenantWithLocation();
        $ungranted = $this->extraLocation($business, 'Ungranted Site');
        $this->make($owner, $business, 'hidden only', $ungranted);

        $item = fn (SeoOverview $o) => collect($o->readiness)->firstWhere('key', 'keywords_defined');

        // The owner sees the keyword: met.
        $this->authenticateAsSeoCustomer($owner);
        $ownerOverview = $this->get(route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]))->assertOk()->viewData('overview');
        $this->assertSame('met', $item($ownerOverview)->state->value);

        // A member with no access to that Location sees NONE: the only keyword
        // is hidden, and the count must not betray that it exists.
        $member = $this->selectedScopeMember($workspace, [$granted]);
        $this->authenticateAsSeoCustomer($member);
        $memberOverview = $this->get(route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]))->assertOk()->viewData('overview');
        $this->assertSame('not_met', $item($memberOverview)->state->value);
        $this->assertStringContainsString('Add search keywords', $this->get(route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]))->getContent());
    }

    // -----------------------------------------------------------------
    // Side effects and cost.
    // -----------------------------------------------------------------

    public function test_keyword_management_writes_only_seo_keywords_and_calls_nothing_external(): void
    {
        $this->bypassSeoEntitlementForTest();
        [$owner, $business, $workspace, $location] = $this->growthTenantWithLocation();
        $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', [], [], true)]);
        $this->bindGoogleLocation($business, $location, $this->activeConnection($business), true);
        $this->authenticateAsSeoCustomer($owner);

        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();
        $before = $this->dbFingerprint($this->seoProtectedTables());

        $this->post($this->url('store', $workspace, $business), ['phrase' => 'best bakery', 'location_uid' => $location->uid]);
        $keyword = SeoKeyword::query()->sole();
        $this->post($this->url('update', $workspace, $business, $keyword), ['phrase' => 'best bakery shop']);
        $this->post($this->url('archive', $workspace, $business, $keyword));
        $this->post($this->url('reactivate', $workspace, $business, $keyword));
        $this->get($this->url('index', $workspace, $business))->assertOk();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame($before, $this->dbFingerprint($this->seoProtectedTables()), 'No Business, Location, Website or Google row may change.');
    }

    public function test_the_index_costs_the_same_for_1_and_25_keywords_and_locations(): void
    {
        $this->bypassSeoEntitlementForTest();

        $build = function (int $keywordCount, int $locationCount) {
            [$owner, $business, $workspace, $primary] = $this->growthTenantWithLocation();
            $locations = [$primary];
            for ($i = 2; $i <= $locationCount; $i++) {
                $locations[] = $this->extraLocation($business, "Site {$i}");
            }
            for ($i = 1; $i <= $keywordCount; $i++) {
                // Every keyword is Location-attributed, so both sizes run the
                // same (single, constant) eager-load of locations.
                $this->make($owner, $business, "phrase {$i}", $locations[$i % count($locations)]);
            }
            $this->publishWebsite($business, [$this->snapshotPage('a', 'Home', ['seo_title' => 'phrase 1'], [], true)]);

            return [$owner, $business, $workspace];
        };

        [$warmOwner, $warmBusiness, $warmWorkspace] = $build(2, 2);
        $this->authenticateAsSeoCustomer($warmOwner);
        $this->get($this->url('index', $warmWorkspace, $warmBusiness))->assertOk();

        [$smallOwner, $smallBusiness, $smallWorkspace] = $build(1, 1);
        [$largeOwner, $largeBusiness, $largeWorkspace] = $build(25, 25);

        $this->authenticateAsSeoCustomer($smallOwner);
        $small = $this->capturedQueries(fn () => $this->get($this->url('index', $smallWorkspace, $smallBusiness))->assertOk());

        $this->authenticateAsSeoCustomer($largeOwner);
        $large = $this->capturedQueries(fn () => $this->get($this->url('index', $largeWorkspace, $largeBusiness))->assertOk());

        $this->assertSame(count($small), count($large), 'Query count must not grow with keywords or Locations (no N+1).');
    }
}
