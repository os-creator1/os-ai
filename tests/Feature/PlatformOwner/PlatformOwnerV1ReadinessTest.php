<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner V1 final — a read-only Platform page must render on a bare
 * install with NO provider credentials: no constructor may boot Stripe, the
 * page says "Not configured" in words, and no secret value is ever printed.
 *
 * Deliberately does NOT call bindFakeStripe()/FakePaymentProviderGateway: the
 * real gateways are resolved with every provider key blank.
 */
class PlatformOwnerV1ReadinessTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();

        config([
            'services.stripe.secret' => '',
            'services.stripe.mode' => '',
            'services.stripe.webhook.secret' => '',
            'services.stripe.platform_subscription_webhook.secret' => '',
            'services.stripe.api_version' => '',
        ]);
    }

    /** @return array<string, string> */
    private function readOnlyPages(): array
    {
        return [
            'Home' => route('admin.platform-owner.overview'),
            'Plans' => route('admin.platform-plans.index'),
            'Edit plan' => route('admin.platform-plans.edit', 'growth'),
            'Billing & Revenue' => route('admin.platform-billing.index'),
            'Safety Limits' => route('admin.usage-billing.safety-limits.index'),
            'AI Usage' => route('admin.ai-usage.index'),
            'Provider Events' => route('admin.provider-events.index'),
            'Legacy Slot Agreements' => route('admin.additional-business-slot-agreements.index'),
            'Users' => route('admin.platform-users.index'),
            'Support' => route('admin.platform-support.index'),
            'Feature Management' => route('admin.platform-features.index'),
            'Announcements' => route('admin.platform-announcements.index'),
            'Audit Logs' => route('admin.platform-owner.audit'),
        ];
    }

    public function test_every_primary_page_renders_with_no_provider_credentials(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view workspace', 'view business', 'view customer', 'view announcement', 'view workspace plans']);

        foreach ($this->readOnlyPages() as $label => $url) {
            $response = $this->get($url);
            $this->assertSame(200, $response->getStatusCode(), "{$label} ({$url}) answered {$response->getStatusCode()} without provider credentials");

            $html = $response->getContent();
            $this->assertStringNotContainsString('locale.menu.', $html, "{$label} shows a raw translation key");
            $this->assertStringNotContainsString('locale.', substr($html, (int) strpos($html, '<section')), "{$label} shows a raw translation key in its content");
        }
    }

    public function test_safety_limits_says_the_provider_is_not_configured_and_prints_no_configuration_value(): void
    {
        $this->actingAsPlatformOwner(['access backend']);
        config(['services.stripe.secret' => 'sk_test_SHOULD_NEVER_BE_PRINTED_123']);
        config(['services.stripe.mode' => 'live']); // mismatch on purpose: a "needs attention" state

        $html = $this->get(route('admin.usage-billing.safety-limits.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('SHOULD_NEVER_BE_PRINTED', $html);
        $this->assertStringContainsString('Payment provider', $html);
    }

    public function test_billing_page_names_the_stripe_state_in_words_and_never_a_key(): void
    {
        $this->actingAsPlatformOwner(['access backend']);

        $html = $this->get(route('admin.platform-billing.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Not configured|Missing/i', $html);

        config(['services.stripe.secret' => 'sk_test_AbCdEf0123456789SECRET', 'services.stripe.mode' => 'test',
            'services.stripe.webhook.secret' => 'whsec_SECRETVALUE', 'services.stripe.api_version' => '2024-06-20']);
        $html = $this->get(route('admin.platform-billing.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('AbCdEf0123456789SECRET', $html);
        $this->assertStringNotContainsString('whsec_SECRETVALUE', $html);
    }

    public function test_readiness_reports_not_configured_connected_and_needs_attention_without_printing_values(): void
    {
        $readiness = fn () => collect(app(\App\Library\PlatformOwner\PlatformProviderReadiness::class)->all())->keyBy('key');

        $this->assertSame('not_configured', $readiness()['stripe']['state']);
        $this->assertSame('Not configured', $readiness()['stripe']['label']);

        // Partly set -> needs attention, naming what is missing but not any value.
        config(['services.stripe.secret' => 'sk_test_PARTIAL_SECRET_VALUE', 'services.stripe.mode' => 'test']);
        $row = $readiness()['stripe'];
        $this->assertSame('attention', $row['state']);
        $this->assertSame('Needs attention', $row['label']);
        $this->assertStringNotContainsString('PARTIAL_SECRET_VALUE', json_encode($row));

        // Fully set and consistent -> connected.
        config([
            'services.stripe.webhook.secret' => 'whsec_x', 'services.stripe.api_version' => '2024-06-20',
            'services.stripe.platform_subscription_webhook.secret' => 'whsec_platform',
        ]);
        $this->assertSame('connected', $readiness()['stripe']['state']);

        // A provider with one of two keys is "needs attention".
        config(['services.telnyx.api_key' => 'KEY_x', 'services.telnyx.webhook_public_key' => '']);
        $this->assertSame('attention', $readiness()['messaging']['state']);
        $this->assertStringContainsString('Webhook public key', $readiness()['messaging']['detail']);
    }

    public function test_home_and_safety_limits_show_the_readiness_states(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view workspace']);

        $home = $this->get(route('admin.platform-owner.overview'))->assertOk()->getContent();
        $this->assertStringContainsString('Provider readiness', $home);
        $this->assertStringContainsString('Not configured', $home);

        $limits = $this->get(route('admin.usage-billing.safety-limits.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-provider="stripe"', $limits);
    }

    public function test_no_sidebar_label_or_permission_matrix_entry_would_render_as_a_raw_translation_key(): void
    {
        $missing = [];

        $walk = function (array $entries) use (&$walk, &$missing): void {
            foreach ($entries as $e) {
                $label = $e['navheader'] ?? $e['name'] ?? null;

                if ($label !== null && __('locale.menu.' . $label) === 'locale.menu.' . $label) {
                    $missing[] = "menu:{$label}";
                }

                if (isset($e['submenu'])) {
                    $walk($e['submenu']);
                }
            }
        };
        $walk(\App\Helpers\Helper::menuData()['admin']);

        foreach ((array) config('permissions') as $key => $def) {
            if (__('locale.menu.' . $def['category']) === 'locale.menu.' . $def['category']) {
                $missing[] = "permission-category:{$def['category']}";
            }

            if (__('locale.permission.' . $def['display_name']) === 'locale.permission.' . $def['display_name']) {
                $missing[] = "permission:{$def['display_name']}";
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    public function test_the_platform_pages_are_closed_to_business_users_even_without_credentials(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);

        foreach ($this->readOnlyPages() as $label => $url) {
            $this->get($url)->assertUnauthorized();
        }
    }
}
