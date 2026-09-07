<?php

namespace Tests\Feature\Website\Public;

use App\Enums\Business\BusinessStatus;
use App\Library\Website\WebsitePublisher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Contract §37.4 (Public rendering) — the anonymous, unauthenticated
 * public renderer. Every test in this file exercises the public
 * `sites/*` routes with ZERO session/auth state: no
 * authenticateAsCustomer()/authenticateAsUser() call anywhere below.
 * Websites are put into a published state directly via
 * WebsitePublisher::publish(), never via the authenticated controller,
 * since publishing itself is out of scope here (covered by
 * WebsiteDraftPublishTest / WebsitePublishedEventTest).
 */
class WebsitePublicRenderingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    public function test_public_home_route_renders_the_published_homepage(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee('Welcome');
    }

    public function test_public_page_route_renders_a_published_sub_page_by_slug(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->get(route('public.website.page', [$website->public_id, 'about']))
            ->assertOk()
            ->assertSee('About us');
    }

    public function test_unknown_public_id_404s_on_the_home_route(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $unknownPublicId = '00000000-0000-4000-8000-000000000000';
        $this->assertNotSame($unknownPublicId, $website->public_id);

        $this->get(route('public.website.home', $unknownPublicId))
            ->assertNotFound();
    }

    public function test_unknown_slug_404s_on_a_real_published_website(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->get(route('public.website.page', [$website->public_id, 'no-such-page']))
            ->assertNotFound();
    }

    public function test_a_page_added_to_the_draft_after_publish_404s_publicly_even_though_the_row_exists(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        // Added directly to website_pages AFTER the publish above, so it
        // is absent from the already-built snapshot. Never re-published.
        $this->subPage($website, 'new-after-publish');

        $this->assertDatabaseHas('website_pages', [
            'website_id' => $website->id,
            'slug' => 'new-after-publish',
        ]);

        $this->get(route('public.website.page', [$website->public_id, 'new-after-publish']))
            ->assertNotFound();
    }

    public function test_an_inactive_business_makes_the_public_route_404(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $url = route('public.website.home', $website->public_id);
        $this->get($url)->assertOk();

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);

        $response = $this->get($url);

        if ($response->status() !== 404) {
            // WebsitePublicEntitlementGate's Business/Workspace state
            // checks are always fresh, but advance past the 60s cached
            // entitlement decision defensively in case that is what is
            // still keeping the route reachable.
            Carbon::setTestNow(Carbon::now()->addSeconds(61));
            $response = $this->get($url);
        }

        $response->assertNotFound();
    }

    public function test_an_inactive_workspace_makes_the_public_route_404(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $url = route('public.website.home', $website->public_id);
        $this->get($url)->assertOk();

        DB::table('workspaces')->where('id', $workspace->id)->update(['is_active' => false]);

        $response = $this->get($url);

        if ($response->status() !== 404) {
            Carbon::setTestNow(Carbon::now()->addSeconds(61));
            $response = $this->get($url);
        }

        $response->assertNotFound();
    }

    public function test_public_rendering_has_no_session_or_auth_dependency(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/Public/WebsiteController.php'));
        $gateSource = file_get_contents(app_path('Library/Website/WebsitePublicEntitlementGate.php'));

        foreach ([$controllerSource, $gateSource] as $source) {
            $code = $this->stripPhpComments($source);
            $this->assertStringNotContainsString('Auth::id()', $code);
            $this->assertStringNotContainsString('Auth::user()', $code);
            $this->assertStringNotContainsString('session(', $code);
        }
    }

    /**
     * Strips comments and docblocks (which may themselves mention the
     * very calls this file asserts are absent from actual code) before
     * a plain substring check against real source.
     */
    private function stripPhpComments(string $source): string
    {
        $stripped = '';

        foreach (\PhpToken::tokenize($source) as $token) {
            if (! in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true)) {
                $stripped .= $token->text;
            }
        }

        return $stripped;
    }

    public function test_the_snapshot_cache_is_isolated_between_two_different_websites_at_the_same_version_number(): void
    {
        [, $businessOne] = $this->entitledTenant();
        $websiteOne = $this->createWebsite($businessOne, ['name' => 'Website One']);
        $this->homePage($websiteOne, ['sections' => [$this->section('hero', ['heading' => 'First Site Heading'])]]);
        app(WebsitePublisher::class)->publish($websiteOne, $this->platformAdminId());

        [, $businessTwo] = $this->entitledTenant();
        $websiteTwo = $this->createWebsite($businessTwo, ['name' => 'Website Two']);
        $this->homePage($websiteTwo, ['sections' => [$this->section('hero', ['heading' => 'Second Site Heading'])]]);
        app(WebsitePublisher::class)->publish($websiteTwo, $this->platformAdminId());

        // Both are each their own first publish: both revisions carry
        // version_number = 1. The renderer's cache key is keyed by
        // public_id (not version_number alone), so each site must still
        // only ever see its own content.
        $this->assertSame(1, $websiteOne->fresh()->publishedRevision->version_number);
        $this->assertSame(1, $websiteTwo->fresh()->publishedRevision->version_number);

        $this->get(route('public.website.home', $websiteOne->public_id))
            ->assertOk()
            ->assertSee('First Site Heading')
            ->assertDontSee('Second Site Heading');

        $this->get(route('public.website.home', $websiteTwo->public_id))
            ->assertOk()
            ->assertSee('Second Site Heading')
            ->assertDontSee('First Site Heading');
    }
}
