<?php

namespace Tests\Feature\Security;

use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConfigurationException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * Security Remediation Slice 0 §16.A.4 (D-21) — a customer response must
 * never carry an environment-variable name, an operator instruction, or
 * any other internal configuration detail. The operator still gets the
 * exact detail, in logs, via the renamed operatorMessage(); customers get
 * customerMessage()'s plain, setting-free recovery guidance (§14.2's
 * exact required copy).
 */
class ConfigurationLeakageTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    private const FORBIDDEN_SETTING_NAMES = [
        'GOOGLE_BUSINESS_PROFILE_CLIENT_ID',
        'GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET',
        'GOOGLE_BUSINESS_PROFILE_REDIRECT',
    ];

    private const CUSTOMER_COPY = "Google connections aren't available right now. This is something we need to fix on our side — we've been notified.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    public static function reasonProvider(): array
    {
        return [
            'missing client id' => [fn () => config(['services.google_business_profile.client_id' => null])],
            'missing client secret' => [fn () => config(['services.google_business_profile.client_secret' => null])],
            'missing redirect' => [fn () => config(['services.google_business_profile.redirect' => null])],
            'redirect mismatch' => [fn () => config(['services.google_business_profile.redirect' => 'https://elsewhere.test/gbp/oauth/callback'])],
        ];
    }

    /**
     * @dataProvider reasonProvider
     */
    public function test_a_customer_never_sees_a_configuration_setting_name(\Closure $breakConfig): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $breakConfig();

        $response = $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));
        $response->assertSessionHas('status', 'error');
        $response->assertSessionHas('message', self::CUSTOMER_COPY);

        $sessionMessage = (string) session('message');
        foreach (self::FORBIDDEN_SETTING_NAMES as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sessionMessage);
        }

        // Follow through to the rendered page the flash actually appears
        // on — the response body a customer genuinely reads. In this
        // sandbox (and identically on unmodified origin/main — the shared
        // customer layout cannot compile because of a pre-existing,
        // unrelated missing frontend build artifact), a full render of
        // this page cannot succeed at all regardless of this change; when
        // that happens, the scan below still runs against whatever body
        // WAS returned (see test_no_customer_reachable_response_contains_a_configuration_shaped_token,
        // which asserts the same absence unconditionally of status code).
        $rendered = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        if ($rendered->getStatusCode() === 200) {
            $rendered->assertSee(self::CUSTOMER_COPY);
        }

        foreach (self::FORBIDDEN_SETTING_NAMES as $forbidden) {
            $rendered->assertDontSee($forbidden);
        }
    }

    /**
     * The operator detail IS written to the log — the fix must not destroy
     * diagnosability.
     */
    public function test_the_operator_detail_is_still_logged(): void
    {
        Log::spy();

        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        config(['services.google_business_profile.client_id' => null]);

        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));

        Log::shouldHaveReceived('error')->once()->withArgs(
            function (string $message, array $context) {
                return str_contains($context['operator_message'] ?? '', 'GOOGLE_BUSINESS_PROFILE_CLIENT_ID')
                    && ($context['reason'] ?? null) === GoogleBusinessProfileConfigurationException::MISSING_CLIENT_ID;
            },
        );
    }

    /**
     * The exception's own audience-labelled methods, directly: the
     * operator method still names the setting; the customer method never
     * does, for any reason.
     */
    public function test_operator_message_names_the_setting_customer_message_never_does(): void
    {
        $reasons = [
            GoogleBusinessProfileConfigurationException::MISSING_CLIENT_ID,
            GoogleBusinessProfileConfigurationException::MISSING_CLIENT_SECRET,
            GoogleBusinessProfileConfigurationException::MISSING_REDIRECT,
            GoogleBusinessProfileConfigurationException::REDIRECT_MISMATCH,
            GoogleBusinessProfileConfigurationException::REDIRECT_NOT_HTTPS,
        ];

        foreach ($reasons as $reason) {
            $exception = match ($reason) {
                GoogleBusinessProfileConfigurationException::MISSING_CLIENT_ID => GoogleBusinessProfileConfigurationException::missingClientId(),
                GoogleBusinessProfileConfigurationException::MISSING_CLIENT_SECRET => GoogleBusinessProfileConfigurationException::missingClientSecret(),
                GoogleBusinessProfileConfigurationException::MISSING_REDIRECT => GoogleBusinessProfileConfigurationException::missingRedirect(),
                GoogleBusinessProfileConfigurationException::REDIRECT_MISMATCH => GoogleBusinessProfileConfigurationException::redirectMismatch(),
                default => GoogleBusinessProfileConfigurationException::redirectNotHttps(),
            };

            $this->assertSame(self::CUSTOMER_COPY, $exception->customerMessage());

            $operatorMessage = $exception->operatorMessage();
            $this->assertStringNotContainsString($operatorMessage, self::CUSTOMER_COPY);
        }

        $this->assertFalse(
            method_exists(GoogleBusinessProfileConfigurationException::class, 'userMessage'),
            'userMessage() must no longer exist under that name.',
        );
    }

    /**
     * Repository-wide regression guard: no customer-reachable response
     * (scoped to an explicit route list, so this stays deterministic)
     * contains a token shaped like a configuration key. Every route named
     * here is exercised with the configuration deliberately broken.
     */
    public function test_no_customer_reachable_response_contains_a_configuration_shaped_token(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        config(['services.google_business_profile.client_id' => null]);

        $routes = [
            fn () => $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid])),
            fn () => $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid])),
        ];

        foreach ($routes as $makeRequest) {
            $response = $makeRequest();
            $body = $response->getContent();

            preg_match_all('/\b[A-Z][A-Z0-9_]{7,}\b/', (string) $body, $matches);

            $configShaped = array_filter($matches[0], fn ($token) => str_starts_with($token, 'GOOGLE_BUSINESS_PROFILE_'));

            $this->assertSame([], array_values($configShaped), 'No configuration-key-shaped token may reach a customer response.');
        }
    }
}
