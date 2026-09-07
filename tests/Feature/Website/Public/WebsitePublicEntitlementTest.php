<?php

namespace Tests\Feature\Website\Public;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Website\WebsitePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Contract §37.5 (Entitlement / public hosting) — the two independent,
 * differently-scoped caches guarding the public renderer:
 * WebsitePublicEntitlementGate's own 60-second `decide()` cache
 * ('website_public_entitlement_{businessId}') and the controller's
 * separate 300-second snapshot cache
 * ('website_public_{publicId}_v{revisionId}'). Every time-based
 * assertion advances the clock via Date::setTestNow()/Carbon — never
 * sleep().
 */
class WebsitePublicEntitlementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_an_entitled_business_serves_its_published_website_on_a_first_request(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee('Welcome');
    }

    public function test_disabled_for_business_only_takes_effect_once_the_60_second_entitlement_cache_expires(): void
    {
        Date::setTestNow(now());

        [, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $url = route('public.website.home', $website->public_id);

        // Warms the 60s entitlement cache with the OLD, allowed decision.
        $this->get($url)->assertOk();

        app(EntitlementManager::class)->disableBusinessFeature(
            $business,
            PlatformFeature::WebsiteGeneration,
            $workspace->owner_user_id,
        );

        // Still within the 60s window: the stale cached "allowed" decision
        // is what is actually consulted, so the site keeps serving.
        Date::setTestNow(now()->addSeconds(59));
        $this->get($url)->assertOk();

        // Past the window: the very next request revalidates and now
        // observes the disabled toggle.
        Date::setTestNow(now()->addSeconds(2));
        $this->get($url)->assertNotFound();
    }

    public function test_denied_by_workspace_override_only_takes_effect_once_the_60_second_entitlement_cache_expires(): void
    {
        Date::setTestNow(now());

        [, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $url = route('public.website.home', $website->public_id);

        $this->get($url)->assertOk();

        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace,
            PlatformFeature::WebsiteGeneration,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(),
            'Test: deny override for public entitlement TTL regression.',
        );

        Date::setTestNow(now()->addSeconds(59));
        $this->get($url)->assertOk();

        Date::setTestNow(now()->addSeconds(2));
        $this->get($url)->assertNotFound();
    }

    public function test_plan_suspended_only_takes_effect_once_the_60_second_entitlement_cache_expires(): void
    {
        Date::setTestNow(now());

        [, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $url = route('public.website.home', $website->public_id);

        $this->get($url)->assertOk();

        app(EntitlementManager::class)->changePlanStatus(
            $workspace,
            WorkspacePlanAssignmentStatus::Suspended,
            $this->platformAdminId(),
            'Test: suspend plan for public entitlement TTL regression.',
        );

        Date::setTestNow(now()->addSeconds(59));
        $this->get($url)->assertOk();

        Date::setTestNow(now()->addSeconds(2));
        $this->get($url)->assertNotFound();
    }

    /**
     * EntitlementManager is `final` (a deliberately unmodified, core,
     * widely-used class) and cannot be Mockery-mocked by class name, so
     * this is proven the same way WebsiteDraftPageServiceSeamTest proves
     * its own mechanical seam: direct source inspection. Combined with
     * test_public_rendering_has_no_session_or_auth_dependency() (which
     * proves the literal absence of Auth::id()/Auth::user()/session( in
     * both this gate and the public controller), this proves the actor
     * argument is (int) $business->customer_id and nothing session-based.
     */
    public function test_the_gate_passes_the_business_customer_id_as_the_actor_never_auth_id(): void
    {
        $source = file_get_contents(app_path('Library/Website/WebsitePublicEntitlementGate.php'));
        $code = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if (! in_array($token->id, [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= $token->text;
            }
        }

        $this->assertStringContainsString('(int) $business->customer_id', $code);
        $this->assertStringNotContainsString('Auth::id()', $code);
        $this->assertStringNotContainsString('Auth::user()', $code);

        // Behavioral half: an allowed Business's public site still
        // serves correctly with zero session/auth state anywhere in the
        // request (no actingAs() call in this test at all), proving the
        // gate does not implicitly depend on one even though it cannot
        // be intercepted mid-call.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->get(route('public.website.home', $website->public_id))->assertOk();
    }

    public function test_a_fresh_denied_decision_takes_effect_the_instant_the_60_second_window_lapses(): void
    {
        Date::setTestNow(now());

        [, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $url = route('public.website.home', $website->public_id);

        // Warms the entitlement cache with an allowed=true decision.
        $this->get($url)->assertOk();
        $this->assertTrue(Cache::has("website_public_entitlement_{$business->id}"));

        app(EntitlementManager::class)->disableBusinessFeature(
            $business,
            PlatformFeature::WebsiteGeneration,
            $workspace->owner_user_id,
        );

        // Exactly past the 60s TTL (not the 300s snapshot TTL).
        Date::setTestNow(now()->addSeconds(61));

        $this->get($url)->assertNotFound();
    }

    public function test_a_warm_snapshot_cache_never_bypasses_a_freshly_denied_entitlement(): void
    {
        Date::setTestNow(now());

        [, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $revision = app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $url = route('public.website.home', $website->public_id);

        // This single request warms BOTH caches at once: the 60s
        // entitlement cache (allowed=true) and the 300s snapshot cache.
        $this->get($url)->assertOk();
        $this->assertTrue(Cache::has("website_public_entitlement_{$business->id}"));
        $this->assertTrue(Cache::has("website_public_{$website->public_id}_v{$revision->id}"));

        app(EntitlementManager::class)->disableBusinessFeature(
            $business,
            PlatformFeature::WebsiteGeneration,
            $workspace->owner_user_id,
        );

        // Advance past ONLY the 60s entitlement window — the 300s
        // snapshot cache is still fully warm at this point.
        Date::setTestNow(now()->addSeconds(61));
        $this->assertTrue(Cache::has("website_public_{$website->public_id}_v{$revision->id}"));

        // resolveSnapshotOrAbort() calls the gate BEFORE ever touching the
        // snapshot cache (App\Http\Controllers\Public\WebsiteController),
        // so a warm snapshot alone must never leak content past a now-
        // denied entitlement.
        $this->get($url)->assertNotFound();
    }
}
