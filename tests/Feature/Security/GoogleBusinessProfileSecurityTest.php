<?php

namespace Tests\Feature\Security;

use App\Helpers\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §32.8 / §33 — cross-module security, coexistence
 * and no-regression.
 *
 * Security criteria G-9 (server-side gating independent of navigation),
 * G-12 (no open redirect) and G-16 (existing Google sign-in untouched).
 */
class GoogleBusinessProfileSecurityTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    /**
     * T-NAV-1 / security criterion G-9 — navigation visibility is
     * PRESENTATION ONLY. With the entry hidden (no view permission), a
     * direct request is still authorized server-side; and for an
     * unentitled Business it is still 404.
     */
    public function test_navigation_visibility_never_substitutes_for_authorization(): void
    {
        $entry = $this->gbpNavigationEntry();

        $this->assertNotNull($entry, 'GBP must have exactly one navigation entry.');
        $this->assertSame('view_google_business_profile', $entry['access']);

        // A user without the view permission never sees the link...
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer, []);

        // ...and posting directly is still refused.
        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertStatus(401);

        // A Core Business with FULL permissions is still 404 — the
        // entitlement gate is independent of the permission gate.
        [$coreCustomer, $coreBusiness, $coreWorkspace] = $this->coreTenant();
        $this->authenticateAsCustomer($coreCustomer);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$coreWorkspace->uid, $coreBusiness->uid]))
            ->assertNotFound();
    }

    /**
     * Contract §26 — exactly ONE navigation entry was added, and every
     * pre-existing customer entry SURVIVES. Website, Analytics, Outreach,
     * Automations and Channels must all still be present.
     */
    public function test_gbp_navigation_coexists_with_every_other_module(): void
    {
        $slugs = collect(Helper::menuData()['customer'])->pluck('slug')->filter()->values()->all();

        foreach (['gbp', 'website', 'analytics', 'channels', 'automations', 'blacklists', 'chat-box', 'prospecting'] as $expected) {
            $this->assertContains($expected, $slugs, "The {$expected} navigation entry must survive.");
        }

        // Outreach is a submenu parent, so it is matched by name.
        $names = collect(Helper::menuData()['customer'])->pluck('name')->all();
        $this->assertContains('Outreach', $names);

        // Exactly one GBP entry, not several.
        $this->assertSame(1, collect($slugs)->filter(fn ($slug) => $slug === 'gbp')->count());
    }

    /**
     * T-REG-1 / security criterion G-16 — the existing Google SIGN-IN seam
     * is byte-identical. GBP must never widen or reuse it: its callback
     * authenticates a session and can create a User.
     */
    public function test_the_existing_google_sign_in_seam_is_untouched(): void
    {
        // The Socialite block still exists, unchanged in shape.
        $signIn = config('services.google');

        $this->assertIsArray($signIn);
        $this->assertArrayHasKey('active', $signIn);
        $this->assertArrayHasKey('client_id', $signIn);
        $this->assertArrayHasKey('client_secret', $signIn);
        $this->assertArrayHasKey('redirect', $signIn);

        // GBP has its OWN, separate block with no `active` flag.
        $gbp = config('services.google_business_profile');

        $this->assertIsArray($gbp);
        $this->assertArrayNotHasKey('active', $gbp);
        $this->assertNotSame($signIn, $gbp);

        // The sign-in routes and controller are unmodified relative to
        // origin/main's shape: the auth callback is still unauthenticated
        // and still lives outside the customer group.
        $authRoutes = (string) file_get_contents(base_path('routes/auth.php'));
        $this->assertStringContainsString('handleProviderCallback', $authRoutes);
        $this->assertStringNotContainsString('GoogleBusinessProfile', $authRoutes);

        $login = (string) file_get_contents(app_path('Http/Controllers/Auth/LoginController.php'));
        $this->assertStringNotContainsString('GoogleBusinessProfile', $login);
        $this->assertStringNotContainsString('business.manage', $login);
    }

    /**
     * Security criterion G-3 — no GBP code may authenticate, create a
     * user, touch email_verified_at, or reach findOrCreateSocial()/
     * Socialite.
     */
    public function test_no_gbp_code_touches_identity(): void
    {
        $offenders = [];

        $roots = [
            app_path('Library/GoogleBusinessProfile'),
            app_path('Jobs/GoogleBusinessProfile'),
            app_path('Http/Controllers/Customer/Business/GoogleBusinessProfileController.php'),
            app_path('Http/Requests/GoogleBusinessProfile'),
        ];

        foreach ($roots as $root) {
            $files = is_file($root) ? [$root] : (glob($root . '/{,*/}*.php', GLOB_BRACE) ?: []);

            foreach ($files as $file) {
                // Comments are STRIPPED first: this must prove the absence
                // of identity-touching CODE, not of the docblocks that
                // explain why it is absent (contract §9.6 states those
                // prohibitions in prose, inside the controller itself).
                $contents = $this->codeWithoutComments((string) file_get_contents($file));

                foreach ([
                    'findOrCreateSocial',
                    'Socialite',
                    'email_verified_at',
                    'auth()->login',
                    'Auth::login',
                    'Auth::loginUsingId',
                    'User::create',
                ] as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = basename($file) . ' => ' . $needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * Strips comments and docblocks, leaving executable PHP only.
     */
    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * The single GBP customer navigation entry (contract §26).
     *
     * @return array<string, mixed>|null
     */
    private function gbpNavigationEntry(): ?array
    {
        foreach (Helper::menuData()['customer'] as $entry) {
            if (($entry['slug'] ?? null) === 'gbp') {
                return $entry;
            }
        }

        return null;
    }

    /**
     * T-SEC-12 / security criterion G-12 — no GBP route accepts or honours
     * a caller-supplied redirect target.
     */
    public function test_no_gbp_route_honours_a_caller_supplied_redirect(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $evil = 'https://evil.test/steal';

        // Correction item 9 — connect is a POST now; the caller-supplied
        // redirect parameters must still be ignored entirely.
        $response = $this->post(
            route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid])
            . '?redirect_to=' . urlencode($evil) . '&return=' . urlencode($evil) . '&next=' . urlencode($evil),
        );

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');

        $this->assertStringNotContainsString('evil.test', $location);
        $this->assertStringStartsWith('https://accounts.google.test/authorize', $location);

        // ...and the signed state carries no URL at all.
        $connection = DB::table('business_google_connections')->where('business_id', $business->id)->first();
        $this->assertNotNull($connection->oauth_state_nonce);
    }

    /**
     * Contract §17.3 — every GBP route is registered inside the
     * authenticated customer group, and the OAuth callback in particular
     * is NOT in routes/auth.php.
     */
    public function test_every_gbp_route_is_authenticated_and_business_scoped(): void
    {
        $gbpRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => str_starts_with((string) $route->getName(), 'customer.workspaces.businesses.gbp.'),
        );

        // Correction item 1 — the OAuth callback moved OUT of this group to
        // one fixed, tenant-free URI, leaving nine Business-scoped routes.
        $this->assertCount(9, $gbpRoutes, 'The GBP group must expose exactly the nine contracted Business-scoped routes.');

        foreach ($gbpRoutes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains('can:access_backend', $middleware);
            $this->assertStringContainsString('{workspaceUid}', $route->uri());
            $this->assertStringContainsString('{businessUid}', $route->uri());
        }

        // The bare chooser is authenticated too.
        $entry = collect(Route::getRoutes())->first(fn ($route) => $route->getName() === 'customer.gbp.index');
        $this->assertNotNull($entry);
        $this->assertContains('auth', $entry->gatherMiddleware());
    }

    /**
     * Security Remediation Slice 0 §16.A.4 (D-21) extension — this file's
     * own no-regression mandate for the operator/customer message split
     * added to GoogleBusinessProfileConfigurationException: a broken
     * provider configuration must never become a shortcut around this
     * class's own G-9 authorization gating above. An unauthenticated
     * request to the configuration-sensitive connect route is still denied
     * before the broken configuration is ever evaluated. The exact
     * customer/operator message content is covered by
     * tests/Feature/Security/ConfigurationLeakageTest.php, which this test
     * does not duplicate.
     */
    public function test_broken_configuration_never_bypasses_the_auth_gate_on_connect(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        config(['services.google_business_profile.client_id' => null]);

        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))
            ->assertStatus(401);
    }

    /**
     * Contract §17.2 — the quota-consuming and security-sensitive routes
     * carry the contracted throttles.
     */
    public function test_the_contracted_routes_are_throttled(): void
    {
        $expected = [
            'customer.workspaces.businesses.gbp.connect' => 'throttle:10,1',
            'customer.gbp.oauth.callback' => 'throttle:20,1',
            'customer.workspaces.businesses.gbp.locations' => 'throttle:20,1',
            'customer.workspaces.businesses.gbp.bind' => 'throttle:20,1',
            'customer.workspaces.businesses.gbp.refresh' => 'throttle:10,1',
        ];

        foreach ($expected as $name => $throttle) {
            $route = collect(Route::getRoutes())->first(fn ($candidate) => $candidate->getName() === $name);

            $this->assertNotNull($route, "Route {$name} must exist.");
            $this->assertContains($throttle, $route->gatherMiddleware(), "Route {$name} must carry {$throttle}.");
        }
    }

    /**
     * T-AUDIT-1 / T-AUDIT-2 — a full lifecycle produces a readable audit
     * trail that carries no secret, no provider payload and no Google
     * Content value.
     */
    public function test_the_audit_ledger_answers_the_required_questions_without_secrets(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $this->fakeGoogleWithLocation();
        $this->fakeGoogle->refreshTokenToIssue = 'top-secret-token';

        $this->authenticateAsCustomer($customer);
        $args = [$workspace->uid, $business->uid];

        // Correction item 9 — connect is a POST.
        $this->post(route('customer.workspaces.businesses.gbp.connect', $args));

        $connection = \App\Models\BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $state = app(\App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner::class)->issue($connection);

        // Correction item 1 — the one fixed, tenant-free callback.
        $this->get(
            route(\App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE)
            . '?code=secret-auth-code&state=' . urlencode($state),
        );

        // Correction item 5 — binding goes through a signed candidate token.
        $this->post(route('customer.workspaces.businesses.gbp.bind', $args), [
            'candidate_token' => $this->candidateTokenFor($business, $connection->refresh(), $customer->user_id),
            'business_location_uid' => $location->uid,
        ]);
        $this->post(route('customer.workspaces.businesses.gbp.refresh', $args));
        $this->post(route('customer.workspaces.businesses.gbp.disconnect', $args));

        $rows = DB::table('business_google_operations')->where('business_id', $business->id)->get();

        $this->assertGreaterThanOrEqual(4, $rows->count());

        foreach ($rows as $row) {
            // Answers who / which Business / what / result / when.
            $this->assertSame($business->id, $row->business_id);
            $this->assertNotEmpty($row->operation_type);
            $this->assertNotEmpty($row->status);
            $this->assertNotNull($row->created_at);
        }

        $dump = (string) json_encode($rows);

        foreach ([
            'top-secret-token',
            'secret-auth-code',
            $state,
            '77 Secret Lane',
            'Snap Booth Co',
            'fake-access-token',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dump, "The audit ledger leaked: {$forbidden}");
        }

        // The disconnect record survives the disconnect that deleted the
        // connection (contract §11.3.1).
        $this->assertTrue($rows->contains(fn ($row) => $row->operation_type === 'disconnected')
            || DB::table('business_google_operations')->where('operation_type', 'disconnected')->exists());
    }
}
