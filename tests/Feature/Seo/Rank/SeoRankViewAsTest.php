<?php

namespace Tests\Feature\Seo\Rank;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Seo\Rank\Provider\FakeSeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProvider;
use App\Library\Seo\Rank\SeoRankTargetManager;
use App\Library\Seo\SeoKeywordManager;
use App\Library\ViewAs\ViewAsProhibitedActions;
use App\Library\ViewAs\ViewAsRouteClass;
use App\Library\ViewAs\ViewAsRouteClassification;
use App\Models\SeoKeyword;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankLocation;
use App\Models\SeoRankTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * SEO V1 final (A8) — an Agency View As session can never spend the client's
 * paid rank-check allowance.
 *
 * Starting or resuming tracking and "Check now" commit the viewed client's
 * paid-provider budget, exactly the class of action ViewAsProhibitedActions
 * already closes for Google Ads (every non-GET `ads.*` route). The rank routes
 * are held to the same rule by PATTERN, the add-keyword form's "track rank"
 * box is closed too, and everything that is ordinary SEO editing stays open.
 *
 * Runs against the fake provider: nothing leaves the process.
 */
class SeoRankViewAsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const CHICAGO = 1016367;

    protected function setUp(): void
    {
        parent::setUp();

        FakeSeoRankProvider::reset();
        $this->app->singleton(SeoRankProvider::class, fn () => new FakeSeoRankProvider());
        config(['seo.rank_tracking.enabled' => true]);

        $location = new SeoRankLocation();
        $location->forceFill(['provider' => 'dataforseo', 'location_code' => self::CHICAGO, 'location_name' => 'Chicago,Illinois,United States', 'country_iso' => 'US', 'location_type' => 'City'])->save();
    }

    private function route(string $name): \Illuminate\Routing\Route
    {
        $route = Route::getRoutes()->getByName('customer.workspaces.businesses.seo.' . $name);
        $this->assertNotNull($route, $name);

        return $route;
    }

    /** An ELEMENT carrying the role (the page's own script also names roles in selectors, which are not controls). */
    private function hasElement(string $html, string $role): bool
    {
        return preg_match('/<[a-z]+[^>]*data-role="' . preg_quote($role, '/') . '"/i', $html) === 1;
    }

    private function url(string $name, $workspace, $business, ?string $uid = null): string
    {
        return route('customer.workspaces.businesses.seo.' . $name, array_filter([$workspace->uid, $business->uid, $uid]));
    }

    // ------------------------------------------------------------------
    // The inventory
    // ------------------------------------------------------------------

    public function test_every_rank_spend_route_is_prohibited_by_pattern_and_the_reads_are_not(): void
    {
        $prohibited = app(ViewAsProhibitedActions::class);
        $classification = app(ViewAsRouteClassification::class);

        foreach (['keywords.rank.track', 'rank-targets.stop', 'rank-targets.restart', 'rank-targets.check'] as $name) {
            $this->assertTrue($prohibited->isProhibitedRoute($this->route($name), 'POST'), "{$name} spends or changes paid tracking.");
            $this->assertSame(ViewAsRouteClass::Prohibited, $classification->classify($this->route($name), 'POST'), $name);
        }

        // Reading stays available: the detail page and the (free) location lookup.
        foreach (['rank-targets.show', 'keywords.rank-locations', 'keywords.index'] as $name) {
            $this->assertFalse($prohibited->isProhibitedRoute($this->route($name), 'GET'), "{$name} is a read.");
        }

        // Ordinary SEO edits are not spend: they stay open exactly as before.
        foreach (['keywords.store', 'keywords.update', 'keywords.archive', 'keywords.reactivate', 'audit.rerun'] as $name) {
            $this->assertFalse($prohibited->isProhibitedRoute($this->route($name), 'POST'), "{$name} is an ordinary edit, not paid-provider spend.");
        }
    }

    public function test_the_ads_pattern_is_unchanged_and_the_new_prefixes_are_non_get_only(): void
    {
        $this->assertContains('customer.workspaces.businesses.ads.', ViewAsProhibitedActions::NON_GET_PROHIBITED_PREFIXES);
        $this->assertContains('customer.workspaces.businesses.seo.keywords.rank.', ViewAsProhibitedActions::NON_GET_PROHIBITED_PREFIXES);
        $this->assertContains('customer.workspaces.businesses.seo.rank-targets.', ViewAsProhibitedActions::NON_GET_PROHIBITED_PREFIXES);

        // A hypothetical future POST under the rank targets is covered the moment it exists.
        $future = (new \Illuminate\Routing\Route(['POST'], 'x', fn () => null))->name('customer.workspaces.businesses.seo.rank-targets.refresh-everything');
        $this->assertTrue(app(ViewAsProhibitedActions::class)->isProhibitedRoute($future, 'POST'));
        $this->assertFalse(app(ViewAsProhibitedActions::class)->isProhibitedRoute($future, 'GET'));
    }

    // ------------------------------------------------------------------
    // Behaviour while viewing
    // ------------------------------------------------------------------

    public function test_while_viewing_as_a_client_no_rank_check_can_be_started_or_resumed_or_bought(): void
    {
        [$agency, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $agency->update(['permissions' => json_encode(['view_seo', 'manage_seo', 'website'])]);
        $actor = (int) $agency->user_id;

        $keywords = app(SeoKeywordManager::class);
        $tracked = $keywords->create($actor, $business, 'photo booth rental');
        $other = $keywords->create($actor, $business, 'wedding photo booth');
        $target = app(SeoRankTargetManager::class)->track($actor, $business, $tracked->uid, self::CHICAGO);

        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $business)->assertRedirect(route('user.home'));

        $runsBefore = SeoRankCheckRun::query()->count();
        $targetsBefore = SeoRankTarget::query()->count();

        // Every spend-class route is refused with the plain View As message, and nothing happens.
        $this->post($this->url('keywords.rank.track', $workspace, $business, $other->uid), ['search_location_code' => self::CHICAGO])
            ->assertRedirect(route('user.home'))
            ->assertSessionHas('message', app(ViewAsProhibitedActions::class)->refusalMessage());
        $this->post($this->url('rank-targets.check', $workspace, $business, $target->uid))->assertRedirect(route('user.home'));
        $this->post($this->url('rank-targets.stop', $workspace, $business, $target->uid))->assertRedirect(route('user.home'));
        $this->post($this->url('rank-targets.restart', $workspace, $business, $target->uid))->assertRedirect(route('user.home'));

        $this->assertSame($targetsBefore, SeoRankTarget::query()->count(), 'No slot was taken.');
        $this->assertTrue($target->fresh()->isTracking(), 'Tracking was not stopped either.');
        $this->assertSame($runsBefore, SeoRankCheckRun::query()->count(), 'No paid check was reserved.');
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls, 'And the provider was never asked.');
    }

    public function test_the_add_keyword_form_cannot_start_tracking_while_viewing_but_still_adds_the_keyword(): void
    {
        [$agency, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $agency->update(['permissions' => json_encode(['view_seo', 'manage_seo', 'website'])]);

        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $business)->assertRedirect(route('user.home'));

        $this->post($this->url('keywords.store', $workspace, $business), [
            'phrase' => 'neon sign hire', 'track_rank' => '1', 'search_location_code' => (string) self::CHICAGO,
        ])->assertRedirect($this->url('keywords.index', $workspace, $business));

        $this->assertStringContainsString('Rank tracking was not started', (string) session('message'));
        $this->assertStringContainsString('cannot be started while you are viewing', (string) session('message'));
        $this->assertSame(1, SeoKeyword::query()->where('phrase', 'neon sign hire')->count(), 'Adding the keyword is ordinary editing and still works.');
        $this->assertSame(0, SeoRankTarget::query()->count(), 'No rank target, so no paid check.');
        $this->assertSame(0, SeoRankCheckRun::query()->count());
        $this->assertSame(0, FakeSeoRankProvider::$submitCalls);

        // A plain add works as it always did.
        $this->post($this->url('keywords.store', $workspace, $business), ['phrase' => 'plain keyword'])->assertSessionHas('message', 'Keyword added.');
    }

    public function test_the_pages_stay_readable_but_offer_no_rank_controls_while_viewing(): void
    {
        [$agency, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Viewed Client', 'Northwind Agency');
        $agency->update(['permissions' => json_encode(['view_seo', 'manage_seo', 'website'])]);
        $actor = (int) $agency->user_id;
        $keyword = app(SeoKeywordManager::class)->create($actor, $business, 'photo booth rental');
        $target = app(SeoRankTargetManager::class)->track($actor, $business, $keyword->uid, self::CHICAGO);
        app(SeoKeywordManager::class)->create($actor, $business, 'untracked keyword');

        $this->authenticateAs($agency);

        // Control: outside View As the controls are there.
        $outside = $this->get($this->url('keywords.index', $workspace, $business))->assertOk()->getContent();
        foreach (['track-rank-toggle', 'rank-start', 'rank-stop'] as $role) {
            $this->assertTrue($this->hasElement($outside, $role), "Control: {$role} renders outside View As.");
        }

        $this->startViewAs($workspace, $business)->assertRedirect(route('user.home'));

        $html = $this->get($this->url('keywords.index', $workspace, $business))->assertOk()->getContent();
        foreach (['track-rank-toggle', 'rank-start', 'rank-stop', 'rank-restart', 'rank-location-field'] as $role) {
            $this->assertFalse($this->hasElement($html, $role), "{$role} must not render while viewing as a client.");
        }
        $this->assertTrue($this->hasElement($html, 'keyword-add-form'), 'Ordinary keyword editing stays available.');

        $detail = $this->get($this->url('rank-targets.show', $workspace, $business, $target->uid))->assertOk()->getContent();
        foreach (['check-now', 'rank-stop', 'rank-restart'] as $role) {
            $this->assertFalse($this->hasElement($detail, $role), "{$role} must not render while viewing as a client.");
        }
        $this->assertStringContainsString($keyword->phrase, $detail, 'The history itself is readable.');
    }
}
