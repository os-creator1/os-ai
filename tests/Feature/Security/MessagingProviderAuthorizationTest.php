<?php

namespace Tests\Feature\Security;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\SendingServer;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Security Remediation Slice 0 §16.A.3 (D-9) — the actual, fail-closed,
 * server-side authorization boundary on MessagingChannelsController's
 * eight public methods. Menu visibility
 * (CustomerMenuBuilder::advancedItems()) was never authorization; this
 * proves the boundary is the controller, independent of navigation, and
 * independent of the pre-existing `view_numbers` permission (default true
 * for every customer).
 */
class MessagingProviderAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private int $platformAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId = User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;

        $this->ensureRequiredAppConfigRowsExist();
    }

    // -----------------------------------------------------------------
    // Positive access — the change must be narrowing, not breaking.
    // -----------------------------------------------------------------

    public function test_an_agency_owner_with_the_entitlement_retains_full_access(): void
    {
        [$owner, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);
        $this->authenticateAsCustomer($owner, ['view_numbers']);

        $response = $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]));

        $this->assertGuardAllowsAccess($response);
    }

    public function test_get_channels_index_does_not_redirect_a_core_actor_into_the_surface(): void
    {
        [$owner, $business] = $this->tenantWithPlan(WorkspacePlanTier::Core);
        $this->authenticateAsCustomer($owner, ['view_numbers']);

        // Exactly one accessible Business existed before this fix, which
        // would have auto-redirected straight into the guarded surface.
        // After the fix, a Core-only actor's filtered accessible list is
        // empty, so entry() must fall through to its own (unguarded)
        // chooser/empty-state branch instead of redirecting. This asserts
        // the redirect decision itself — not the page content — so it
        // holds even in an environment (this sandbox, and unmodified
        // origin/main alike) where the shared customer layout cannot
        // compile because of pre-existing, unrelated missing frontend
        // build artifacts (Mix manifest / BladeUI icon manifest).
        $response = $this->get(route('customer.channels.index'));

        $this->assertFalse(
            $response->isRedirect(),
            'entry() must not auto-redirect a denied actor into a Business it cannot access.',
        );
        $this->assertNotSame(
            route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]),
            $response->headers->get('Location'),
        );
    }

    // -----------------------------------------------------------------
    // Entitlement is checked, not inferred from tier alone.
    // -----------------------------------------------------------------

    public function test_an_agency_owner_whose_plan_does_not_package_the_capability_receives_404(): void
    {
        [$owner, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);

        app(EntitlementManager::class)->createOrChangeOverride(
            $business->workspace,
            PlatformFeature::Conversations,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId,
            'Test: deny messaging entitlement.',
        );

        $this->authenticateAsCustomer($owner, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]))
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Every one of the eight routes, for every denied actor class, GET
    // and mutation alike.
    // -----------------------------------------------------------------

    public static function deniedActorProvider(): array
    {
        return ['core owner' => ['core'], 'growth owner' => ['growth'], 'agency staff without manage rights' => ['agencyStaff'], 'restricted business user' => ['restrictedStaff']];
    }

    /**
     * @dataProvider deniedActorProvider
     */
    public function test_every_read_route_denies_the_actor_by_direct_url(string $actorKind): void
    {
        [$actor, $business, $connection] = $this->deniedActorWithConnection($actorKind);
        $this->authenticateAsCustomer($actor, ['view_numbers']);
        $args = [$business->workspace->uid, $business->uid];

        // entry() itself must not 404 and must not redirect a denied actor
        // into the Business it cannot access — it should fall through to
        // its own (now-filtered) empty/chooser state. Checked at the
        // status/redirect level, not by rendering that state: the shared
        // customer layout cannot compile in this sandbox for reasons
        // unrelated to this change (see the class docblock), and that gap
        // is reproduced identically on unmodified origin/main.
        $entryResponse = $this->get(route('customer.channels.index'));
        $this->assertNotSame(404, $entryResponse->getStatusCode());
        $this->assertFalse($entryResponse->isRedirect());

        $this->get(route('customer.workspaces.businesses.channels.index', $args))->assertStatus(404);
        $this->get(route('customer.workspaces.businesses.channels.connect', array_merge($args, [SendingServer::TYPE_TWILIO])))->assertStatus(404);
        $this->get(route('customer.workspaces.businesses.channels.connections.show', [...$args, $connection->uid]))->assertStatus(404);
    }

    /**
     * @dataProvider deniedActorProvider
     */
    public function test_every_mutation_route_denies_the_actor_and_leaves_state_unchanged(string $actorKind): void
    {
        [$actor, $business, $connection] = $this->deniedActorWithConnection($actorKind);
        $originalServer = SendingServer::find($connection->sending_server)->toArray();
        $originalConnectionStatus = $connection->status;

        $this->authenticateAsCustomer($actor, ['view_numbers']);
        $args = [$business->workspace->uid, $business->uid];

        $this->post(route('customer.workspaces.businesses.channels.connect', array_merge($args, [SendingServer::TYPE_TWILIO])), [
            'account_sid' => 'SHOULD_NOT_BE_SAVED',
            'auth_token' => 'should_not_be_saved',
        ])->assertStatus(404);
        $this->assertDatabaseMissing('sending_servers', ['account_sid' => 'SHOULD_NOT_BE_SAVED']);

        $this->put(route('customer.workspaces.businesses.channels.connections.update', [...$args, $connection->uid]), [
            'account_sid' => 'HACKED',
            'auth_token' => 'hacked_token',
        ])->assertStatus(404);

        $this->post(route('customer.workspaces.businesses.channels.connections.enable', [...$args, $connection->uid]))
            ->assertStatus(404);

        $this->post(route('customer.workspaces.businesses.channels.connections.disable', [...$args, $connection->uid]))
            ->assertStatus(404);

        $this->assertSame($originalServer, SendingServer::find($connection->sending_server)->fresh()->toArray());
        $this->assertSame($originalConnectionStatus, $connection->fresh()->status);
    }

    // -----------------------------------------------------------------
    // T-PROV-1 / T-PROV-2 — inherited from the parent contract §24
    //
    // T-PROV-1: no customer-role response body contains any provider
    //           credential field name or value.
    // T-PROV-2: provider credentials are readable by no customer role, in
    //           any serialization.
    // -----------------------------------------------------------------

    /**
     * Every credential-shaped needle these tests hunt for. The field NAMES
     * matter as much as the values (T-PROV-1 names both), because a response
     * that echoes `"auth_token": null` still tells an attacker the shape of
     * what it is hiding.
     *
     * @return array{names: list<string>, values: list<string>}
     */
    private function credentialNeedles(): array
    {
        return [
            'names' => ['auth_token', 'api_key', 'api_secret', 'secret_access', 'access_token', 'user_token', 'auth_key', 'private_key'],
            'values' => ['AC_PROV_SID', 'prov_auth_token_value', 'prov_api_key_value', 'prov_profile_c1', 'platform_telnyx_api_key', 'platform_webhook_public_key'],
        ];
    }

    private function assertCarriesNoCredential(string $haystack, string $context): void
    {
        $needles = $this->credentialNeedles();

        foreach ([...$needles['names'], ...$needles['values']] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $haystack,
                "{$context} exposed the credential-shaped string [{$needle}].",
            );
        }
    }

    /**
     * T-PROV-1 names both the field NAME and the value, and the distinction
     * between them matters here, so this test draws it explicitly rather
     * than blurring it.
     *
     * A response that DISPLAYS existing connections must carry neither: a
     * field name there would tell a reader what secret is stored even when
     * the value is masked. The connect FORM is the one legitimate exception
     * — it exists so the customer can type their own credential in, and an
     * `<input name="auth_token">` with no value is the mechanism, not a
     * leak. So the form is held to the stricter half of the requirement
     * instead: no credential VALUE anywhere, and every credential-named
     * input provably empty.
     */
    public function test_no_customer_role_response_carries_a_provider_credential_name_or_value(): void
    {
        [$owner, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO, [
            'account_sid' => 'AC_PROV_SID',
            'auth_token' => 'prov_auth_token_value',
        ]);

        // The platform's own managed credentials are configured too, so a
        // leak of EITHER the customer's BYO secret or the platform's Telnyx
        // key would be caught.
        config([
            'services.telnyx.api_key' => 'platform_telnyx_api_key',
            'services.telnyx.webhook_public_key' => 'platform_webhook_public_key',
        ]);

        $this->authenticateAsCustomer($owner, ['view_numbers', 'manage_advanced_provider']);
        $args = [$business->workspace->uid, $business->uid];

        // (a) The pure display surface: neither names nor values. It lists
        //     connections and must not describe what secret each holds.
        $this->assertCarriesNoCredential(
            $this->get(route('customer.workspaces.businesses.channels.index', $args))->getContent() ?? '',
            'the connections index',
        );

        // (b) The two credential-entry forms — connect and manage. Both
        //     legitimately name their inputs so the customer can type a
        //     secret in, so they are held to the stricter half instead: no
        //     credential VALUE anywhere, and every credential-named input
        //     provably empty.
        foreach ([
            'the connect form' => route('customer.workspaces.businesses.channels.connect', [...$args, SendingServer::TYPE_TWILIO]),
            'the manage form' => route('customer.workspaces.businesses.channels.connections.show', [...$args, $connection->uid]),
        ] as $context => $url) {
            $form = $this->get($url)->getContent() ?? '';

            foreach ($this->credentialNeedles()['values'] as $value) {
                $this->assertStringNotContainsString($value, $form, "{$context} leaked the credential value [{$value}].");
            }

            if (preg_match_all('/<input\b[^>]*>/i', $form, $matches)) {
                foreach ($matches[0] as $input) {
                    // Laravel's CSRF field is named `_token` and legitimately
                    // carries a value; it is not a provider credential.
                    if (preg_match('/\bname=["\']_token["\']/i', $input)) {
                        continue;
                    }

                    if (! preg_match('/\bname=["\'][^"\']*(secret|token|api_key|password|auth)[^"\']*["\']/i', $input)) {
                        continue;
                    }

                    $this->assertDoesNotMatchRegularExpression(
                        '/\bvalue=["\'](?!["\'])/i',
                        $input,
                        "{$context} pre-fills a credential input: {$input}",
                    );
                }
            }
        }
    }

    public function test_provider_credentials_survive_no_serialization_reachable_by_a_customer(): void
    {
        [, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TELNYX, [
            'api_key' => 'prov_api_key_value',
            'c1' => 'prov_profile_c1',
        ]);

        // T-PROV-2 is about SERIALIZATION, so this asserts on the shapes a
        // customer-reachable surface could actually hand out — the Slice 3
        // models' own array/JSON forms — rather than only on rendered HTML.
        $identity = $this->sliceThreeIdentityFor($business);

        foreach ([
            'BusinessMessagingIdentity::toArray' => (string) json_encode($identity->toArray()),
            'BusinessMessagingIdentity::toJson' => $identity->toJson(),
            'BusinessMessagingNumber' => (string) json_encode($identity->numbers()->get()->toArray()),
            'MessagingWebhookRejection' => (string) json_encode(\App\Models\MessagingWebhookRejection::query()->get()->toArray()),
            'BusinessUsageMeasurement' => (string) json_encode(\App\Models\BusinessUsageMeasurement::query()->get()->toArray()),
            'business_messaging_operations' => (string) json_encode(\Illuminate\Support\Facades\DB::table('business_messaging_operations')->get()),
        ] as $context => $serialized) {
            $this->assertCarriesNoCredential($serialized, $context);
        }

        // The connection the customer really does own still holds its
        // secret in the database — so the assertions above are proving
        // non-exposure, not merely that no credential exists anywhere.
        $this->assertSame(
            'prov_api_key_value',
            SendingServer::find($connection->sending_server)->api_key,
            'The fixture must genuinely hold a secret for this test to mean anything.',
        );
    }

    public function test_no_slice_three_model_declares_a_credential_shaped_attribute(): void
    {
        // The structural half of T-PROV-2: there is no attribute a
        // credential could be written into in the first place.
        foreach ([
            \App\Models\BusinessMessagingIdentity::class,
            \App\Models\BusinessMessagingNumber::class,
            \App\Models\MessagingWebhookRejection::class,
            \App\Models\BusinessUsageMeasurement::class,
        ] as $model) {
            $instance = new $model();

            foreach ([...$instance->getFillable(), ...array_keys($instance->getCasts()), ...$instance->getHidden()] as $attribute) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(secret|token|api_key|password|credential|auth|private_key)/i',
                    $attribute,
                    "[{$model}] declares the credential-shaped attribute [{$attribute}].",
                );
            }
        }
    }

    public function test_a_customer_cannot_read_the_platform_telnyx_configuration_through_any_messaging_endpoint(): void
    {
        [$owner, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);

        config([
            'messaging.managed_messaging_enabled' => true,
            'services.telnyx.api_key' => 'platform_telnyx_api_key',
            'services.telnyx.webhook_public_key' => 'platform_webhook_public_key',
        ]);

        $this->authenticateAsCustomer($owner, ['view_numbers', 'manage_advanced_provider']);
        $args = [$business->workspace->uid, $business->uid];

        // Every customer-reachable messaging endpoint this slice exposes or
        // relocates, read and mutation alike.
        $probes = [
            ['GET', route('customer.channels.index'), []],
            ['GET', route('customer.workspaces.businesses.channels.index', $args), []],
            ['GET', route('customer.workspaces.businesses.channels.connect', [...$args, SendingServer::TYPE_TELNYX]), []],
            ['POST', route('customer.workspaces.businesses.channels.connect', [...$args, SendingServer::TYPE_TELNYX]), [
                'api_key' => 'prov_api_key_value', 'c1' => 'prov_profile_c1',
            ]],
        ];

        foreach ($probes as [$method, $url, $payload]) {
            $response = $this->call($method, $url, $payload);

            $this->assertStringNotContainsString('platform_telnyx_api_key', $response->getContent() ?? '', "{$method} {$url}");
            $this->assertStringNotContainsString('platform_webhook_public_key', $response->getContent() ?? '', "{$method} {$url}");
        }

        // A credential the customer just submitted is not echoed back either.
        $this->assertStringNotContainsString(
            'prov_api_key_value',
            $this->get(route('customer.workspaces.businesses.channels.index', $args))->getContent() ?? '',
        );
    }

    public function test_the_deterministic_fake_does_not_weaken_the_real_production_binding(): void
    {
        // The fake exists for tests only. Outside a test that binds it, the
        // container must still resolve the REAL adapter — otherwise every
        // credential assertion in this suite would be proving something
        // about a stub rather than about production.
        config([
            'messaging.managed_messaging_enabled' => true,
            'services.telnyx.api_key' => 'platform_telnyx_api_key',
            'services.telnyx.webhook_public_key' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        ]);

        \Illuminate\Support\Facades\Http::fake();

        $adapter = app(\App\Library\Messaging\Contracts\MessagingProviderAdapter::class);

        $this->assertInstanceOf(\App\Library\Messaging\TelnyxMessagingAdapter::class, $adapter);
        $this->assertNotInstanceOf(\App\Library\Messaging\FakeMessagingAdapter::class, $adapter);

        // And the real adapter keeps its credential to itself.
        $this->assertCarriesNoCredential((string) json_encode($adapter), 'the real adapter, serialized');
    }

    // -----------------------------------------------------------------
    // T-SCOPE-1 — inherited from the parent contract §24
    //
    // "No customer-facing surface in any slice offers, prices, provisions
    // or meters a voice call (§10.5)."
    //
    // Scoped, deliberately, to what SLICE 3 exposes. The legacy platform's
    // own unrelated voice functionality is not removed and is not this
    // requirement's subject — the requirement is that this slice offers
    // none of it.
    // -----------------------------------------------------------------

    public function test_slice_three_exposes_no_voice_capability_anywhere(): void
    {
        // 1. The provider allowlist the relocated surface offers.
        $reflection = new \ReflectionClass(\App\Http\Controllers\Customer\Business\MessagingChannelsController::class);
        $allowed = $reflection->getConstant('ALLOWED_PROVIDERS');

        $this->assertIsArray($allowed);
        foreach ($allowed as $provider => $definition) {
            $this->assertDoesNotMatchRegularExpression('/voice|call|dial|sip|ivr/i', (string) $provider);
            $this->assertDoesNotMatchRegularExpression('/voice|call|dial|sip|ivr/i', (string) ($definition['label'] ?? ''));

            foreach (array_keys($definition['credential_fields'] ?? []) as $field) {
                $this->assertDoesNotMatchRegularExpression('/voice|dial|sip|ivr/i', (string) $field);
            }
        }

        // 2. Slice 3's own enums offer no voice case.
        foreach ([
            \App\Enums\Messaging\MessagingProvider::class,
            \App\Enums\Messaging\MessagingTransportMode::class,
            \App\Enums\Messaging\MessageDispatchStatus::class,
            \App\Enums\Messaging\MessagingOperationStatus::class,
            \App\Enums\Messaging\InboundWebhookEventKind::class,
            \App\Enums\Messaging\BusinessMessagingIdentityStatus::class,
            \App\Enums\Messaging\BusinessMessagingNumberStatus::class,
            \App\Enums\Messaging\ProviderErrorCategory::class,
            \App\Enums\Messaging\WebhookRejectionReason::class,
        ] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertDoesNotMatchRegularExpression(
                    '/voice|call|dial|sip|ivr/i',
                    $case->value,
                    "[{$enum}] offers the voice-shaped case [{$case->value}].",
                );
            }
        }

        // 3. The provider adapter is structurally incapable of a voice call.
        $methods = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\App\Library\Messaging\Contracts\MessagingProviderAdapter::class))->getMethods(),
        );

        foreach ($methods as $method) {
            $this->assertDoesNotMatchRegularExpression('/voice|call|dial|sip|ivr/i', $method);
        }

        // 4. Slice 3's routes name no voice surface.
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_contains($uri, 'settings/advanced') && ! str_contains($uri, 'inbound/telnyx-managed')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/voice|dial|sip|ivr/i', $uri, "Slice 3 route [{$uri}] is voice-shaped.");
        }

        // 5. Nothing in Slice 3's own library or views mentions provisioning,
        //    pricing or metering a call.
        foreach ([app_path('Library/Messaging'), resource_path('views/customer/settings/advanced')] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());

                $this->assertDoesNotMatchRegularExpression(
                    '/(voice_sms|voice_sending_server|sendVoiceSMS|voice_call|provision_voice)/i',
                    $source,
                    "[{$relative}] reaches a voice capability.",
                );
            }
        }

        // 6. And no voice usage is metered by this slice.
        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('business_usage_measurements')
                ->where('feature_key', 'like', '%voice%')
                ->count(),
        );
    }

    /**
     * A Slice 3 identity with one number, created through the resolver, so
     * the serialization assertions run against real rows.
     */
    private function sliceThreeIdentityFor(Business $business): \App\Models\BusinessMessagingIdentity
    {
        $identity = app(\App\Library\Messaging\BusinessMessagingIdentityResolver::class)
            ->create($business, 'mp_prov_' . uniqid());

        $identity->numbers()->create([
            'phone_number' => '+14155559800',
            'status' => \App\Enums\Messaging\BusinessMessagingNumberStatus::Active->value,
            'is_primary' => true,
            'activated_at' => now(),
        ]);

        return $identity->fresh();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business}
     */
    private function tenantWithPlan(WorkspacePlanTier $tier): array
    {
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        app(EntitlementManager::class)->assignFirstPlan(
            $business->workspace,
            $tier,
            $this->platformAdminId,
            'Test fixture assignment.',
            true,
            0,
        );

        // createBusinessWithWorkspace() always starts a Business in Draft
        // (real onboarding's transient starting state). The guard also
        // requires active Business state, so every fixture here is
        // activated — denial in the tier/role/tenancy tests below must
        // come from the dimension each test actually targets, not be
        // conflated with an incidentally-Draft fixture.
        app(BusinessRepository::class)->updateStatus($business, BusinessStatus::Active);

        return [$tenant, $business->fresh()];
    }

    /**
     * @return array{0: Customer, 1: Business, 2: CustomerBasedSendingServer}
     */
    private function deniedActorWithConnection(string $kind): array
    {
        return match ($kind) {
            'core' => $this->coreOrGrowthDeniedActor(WorkspacePlanTier::Core),
            'growth' => $this->coreOrGrowthDeniedActor(WorkspacePlanTier::Growth),
            'agencyStaff' => $this->agencyStaffDeniedActor(fullScope: true),
            default => $this->agencyStaffDeniedActor(fullScope: false),
        };
    }

    /**
     * @return array{0: Customer, 1: Business, 2: CustomerBasedSendingServer}
     */
    private function coreOrGrowthDeniedActor(WorkspacePlanTier $tier): array
    {
        [$owner, $business] = $this->tenantWithPlan($tier);
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);

        return [$owner, $business, $connection];
    }

    /**
     * fullScope=true: an Agency-tier Workspace staff member with 'all'
     * business_access_scope but a plain 'staff' role — denied because the
     * guard requires owner-or-active-admin, not merely Business access.
     *
     * fullScope=false ("restricted Business user"): the same 'staff' role,
     * scoped only to a DIFFERENT Business — denied twice over, by role
     * AND by tenancy.
     */
    private function agencyStaffDeniedActor(bool $fullScope): array
    {
        [, $business] = $this->tenantWithPlan(WorkspacePlanTier::Agency);
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);

        $staff = $this->createCustomer();

        if ($fullScope) {
            WorkspaceMembership::create([
                'workspace_id' => $business->workspace_id,
                'user_id' => $staff->user_id,
                'role' => 'staff',
                'business_access_scope' => 'all',
                'is_active' => true,
            ]);
        } else {
            WorkspaceMembership::create([
                'workspace_id' => $business->workspace_id,
                'user_id' => $staff->user_id,
                'role' => 'staff',
                'business_access_scope' => 'selected',
                'is_active' => true,
            ]);
            // No per-Business assignment row links $staff to $business at
            // all — the "selected" scope member has no explicit grant onto
            // it, denied twice over: by role AND by tenancy.
        }

        return [$staff, $business, $connection];
    }

    private function createDedicatedConnection(Business $business, string $provider, array $credentialOverrides = []): CustomerBasedSendingServer
    {
        $defaults = match ($provider) {
            SendingServer::TYPE_TWILIO => ['account_sid' => 'AC_DEFAULT', 'auth_token' => 'default_token'],
            SendingServer::TYPE_TELNYX => ['api_key' => 'default_key', 'c1' => 'default_profile', 'c2' => 'default_connection'],
            default => [],
        };

        $sendingServer = SendingServer::create(array_merge([
            'name' => $provider,
            'settings' => $provider,
            'status' => true,
            'plain' => true,
            'mms' => true,
            'user_id' => $business->customer_id,
        ], $defaults, $credentialOverrides));

        return CustomerBasedSendingServer::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'sending_server' => $sendingServer->id,
            'status' => true,
        ]);
    }

    /**
     * Proves the authorization guard allowed this actor through, without
     * requiring the shared customer layout to actually compile. In this
     * sandbox (and identically on unmodified origin/main — confirmed by
     * `git stash`-based reproduction), rendering the real channels views
     * fails on pre-existing, unrelated missing frontend build artifacts
     * (Laravel Mix manifest entries, the BladeUI icon manifest). A denied
     * actor gets abort(404) before any view is ever touched, so 404 is
     * always the unambiguous "the guard blocked this" signal; anything
     * else — 200, or the specific pre-existing render failure — means the
     * guard let the request through.
     */
    private function assertGuardAllowsAccess(\Illuminate\Testing\TestResponse $response): void
    {
        $this->assertNotSame(404, $response->getStatusCode(), 'The authorization guard denied an actor expected to pass.');

        if ($response->getStatusCode() === 200) {
            return;
        }

        $exception = $response->exception;
        $message = $exception?->getMessage() ?? '';

        $this->assertTrue(
            str_contains($message, 'Unable to locate Mix file') || str_contains($message, 'IconsManifest'),
            "Expected only the pre-existing Mix/IconsManifest asset-build gap reproduced on unmodified origin/main, got: {$message}",
        );
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions = []): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        // Customer Experience Slice 3 §4.7 — the relocated surface stacks a
        // granted manage_advanced_provider permission on top of Workspace
        // ownership. Slice 0's own positive cases predate that permission
        // existing at all, so it is granted here; every negative case in
        // this file still fails on the ownership/tier/entitlement clause
        // it was written to exercise, which is why they all still pass.
        $this->withSession(['permissions' => collect(array_merge(['access_backend', 'manage_advanced_provider'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }
}
