<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Library\GoogleBusinessProfile\FakeGoogleBusinessProfileReadClient;
use App\Library\GoogleBusinessProfile\HttpGoogleBusinessProfileReadClient;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * GBP Slice A contract §14.2 / security criterion G-13 — T-PROV-1.
 *
 * Google exposes exactly ONE Business Profile scope, business.manage, for
 * both reads and writes. There is no read-only scope, so Slice A's
 * read-only property CANNOT be delegated to OAuth (contract §9.2,
 * correction A-3). It is enforced structurally, and this test is the
 * enforcement: a future "harmless" addition of a mutation method fails the
 * suite rather than silently widening Slice A.
 */
class GoogleBusinessProfileReadOnlyTest extends TestCase
{
    /**
     * Contract §14.1 — the interface declares exactly these seven methods
     * and no others.
     */
    private const ALLOWED_METHODS = [
        'authorizationUrl',
        'exchangeAuthorizationCode',
        'exchangeRefreshToken',
        'listAccounts',
        'listLocations',
        'getLocation',
        'getVoiceOfMerchantState',
    ];

    /**
     * Contract §14.2 — no implementation may declare a method whose name
     * suggests, or could perform, a Google mutation.
     */
    private const FORBIDDEN_FRAGMENTS = [
        'patch', 'update', 'create', 'delete', 'destroy', 'set', 'put',
        'publish', 'reply', 'upload', 'verify', 'insert', 'write', 'mutate',
        'post', 'send', 'remove',
    ];

    public function test_the_provider_interface_declares_exactly_the_seven_read_methods(): void
    {
        $declared = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass(GoogleBusinessProfileReadClient::class))->getMethods(),
        );

        sort($declared);
        $expected = self::ALLOWED_METHODS;
        sort($expected);

        $this->assertSame(
            $expected,
            $declared,
            'The Google Business Profile provider interface must declare exactly the seven contracted read methods.',
        );
    }

    /**
     * The heart of the guarantee: neither implementation may expose a
     * public method outside the allowlist, and no public method name may
     * contain a mutation verb.
     *
     * @dataProvider implementations
     */
    public function test_implementations_expose_no_mutation_capable_method(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if ($method->isConstructor()) {
                continue;
            }

            $name = $method->getName();

            // The Fake carries explicit test-facing builders; they touch
            // only in-memory fixtures and never Google.
            if ($class === FakeGoogleBusinessProfileReadClient::class
                && in_array($name, ['withAccount', 'withLocation', 'callsTo', 'callCount', 'clearCalls'], true)) {
                continue;
            }

            $this->assertContains(
                $name,
                self::ALLOWED_METHODS,
                sprintf('%s::%s() is outside the contracted read-only provider surface.', $class, $name),
            );
        }
    }

    /**
     * @dataProvider implementations
     */
    public function test_no_method_name_contains_a_mutation_verb(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $lowered = strtolower($method->getName());

            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                // postToken() is the OAuth token endpoint, which is not a
                // Business Profile resource; it is private and is named
                // explicitly here so the allowance is visible rather than
                // accidental.
                if ($method->getName() === 'postToken') {
                    continue 2;
                }

                $this->assertStringNotContainsString(
                    $fragment,
                    $lowered,
                    sprintf('%s::%s() name suggests a mutation; Slice A writes nothing to Google.', $class, $method->getName()),
                );
            }
        }
    }

    /**
     * Contract §14.2 — there is no generic request()/call()/send() escape
     * hatch, so product code cannot reach an arbitrary Google endpoint
     * through this seam even if it wanted to.
     *
     * @dataProvider implementations
     */
    public function test_there_is_no_generic_request_method(string $class): void
    {
        foreach (['request', 'call', 'send', 'execute', 'raw', 'api'] as $generic) {
            $this->assertFalse(
                method_exists($class, $generic),
                sprintf('%s must not expose a generic %s() method.', $class, $generic),
            );
        }
    }

    /**
     * Contract §7 — Slice A is 100% current-v1. No v4/v4.9 host or path may
     * appear anywhere in the GBP source tree.
     */
    public function test_no_google_my_business_v4_endpoint_appears_anywhere_in_the_module(): void
    {
        $offenders = [];

        foreach ($this->gbpSourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'mybusiness.googleapis.com')
                || preg_match('/googleapis\.com\/v4/', $contents) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Slice A must not reference any Google My Business v4 endpoint.');
    }

    /**
     * Contract §7 — only the three approved v1 service hosts, plus the two
     * OAuth endpoints, may be contacted.
     */
    public function test_only_the_three_approved_v1_hosts_are_referenced(): void
    {
        $client = (string) file_get_contents(base_path('app/Library/GoogleBusinessProfile/HttpGoogleBusinessProfileReadClient.php'));

        preg_match_all('/https:\/\/([a-z0-9.\-]+)/i', $client, $matches);

        $hosts = array_values(array_unique($matches[1]));
        sort($hosts);

        $this->assertSame([
            'accounts.google.com',
            'mybusinessaccountmanagement.googleapis.com',
            'mybusinessbusinessinformation.googleapis.com',
            'mybusinessverifications.googleapis.com',
            'oauth2.googleapis.com',
            'www.googleapis.com',
        ], $hosts);
    }

    /**
     * Contract §14.4 / §31 / test T-URL-2 — GBP code never performs a
     * server-side fetch of a URL taken from a provider response, a
     * database row or user input. The only HTTP calls are the Http facade
     * calls inside the real client, all of which build a compile-time
     * constant host.
     */
    public function test_no_gbp_code_fetches_a_url_from_data(): void
    {
        $offenders = [];

        foreach ($this->gbpSourceFiles() as $file) {
            if (str_ends_with(str_replace('\\', '/', $file), 'HttpGoogleBusinessProfileReadClient.php')) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            foreach (['Http::', 'file_get_contents(', 'curl_init(', 'fopen(', 'readfile('] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = basename($file) . ' => ' . $needle;
                }
            }
        }

        $this->assertSame([], $offenders, 'Only the real provider client may make an outbound request, and only to constant hosts.');
    }

    /**
     * Contract §31 / test T-CACHE-1 — Slice A introduces no cross-request
     * cache at all, so there is no cache key that could leak between
     * tenants.
     */
    public function test_gbp_code_reaches_no_cache_facade(): void
    {
        $offenders = [];

        foreach ($this->gbpSourceFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'Cache::') || str_contains($contents, 'Illuminate\Support\Facades\Cache')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'GBP Slice A must not introduce a cross-request cache.');
    }

    /**
     * Contract §14.5 / test T-XSS-2 — raw Blade output is forbidden in
     * every GBP view, because every Google-supplied value is untrusted
     * input.
     */
    public function test_no_gbp_blade_view_uses_raw_output(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/customer/business/googleBusinessProfile/*.blade.php')) ?: [] as $view) {
            if (str_contains((string) file_get_contents($view), '{!!')) {
                $offenders[] = basename($view);
            }
        }

        $this->assertSame([], $offenders, 'GBP views must never use raw Blade output.');
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function implementations(): array
    {
        return [
            'real client' => [HttpGoogleBusinessProfileReadClient::class],
            'fake client' => [FakeGoogleBusinessProfileReadClient::class],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function gbpSourceFiles(): array
    {
        $roots = [
            base_path('app/Library/GoogleBusinessProfile'),
            base_path('app/Http/Controllers/Customer/Business/GoogleBusinessProfileController.php'),
            base_path('app/Jobs/GoogleBusinessProfile'),
            base_path('app/Models/BusinessGoogleConnection.php'),
            base_path('app/Models/BusinessGoogleLocation.php'),
            base_path('app/Models/BusinessGoogleOperation.php'),
            base_path('app/DTO/GoogleBusinessProfile'),
            base_path('app/Repositories/Eloquent/EloquentBusinessGoogleConnectionRepository.php'),
            base_path('app/Repositories/Eloquent/EloquentBusinessGoogleLocationRepository.php'),
            base_path('app/Repositories/Eloquent/EloquentBusinessGoogleOperationRepository.php'),
        ];

        $files = [];

        foreach ($roots as $root) {
            if (is_file($root)) {
                $files[] = $root;

                continue;
            }

            foreach (glob($root . '/{,*/}*.php', GLOB_BRACE) ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
