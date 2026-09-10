<?php

namespace Tests\Feature\Outreach;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Country;
use App\Models\CustomerBasedSendingServer;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Redesign — Slice 1B (contract §3 / §6b, the 27 paths
 * enumerated in Correction 2 §7b).
 *
 * The surviving legacy Outreach/Campaign/Template/ChatBox/Keyword/
 * ContactGroup/SenderID-checkout/Developers screens rendered three
 * ADMIN-SHARED locale keys — `labels.sending_server`, `labels.originator`
 * and `labels.sender_id`. Slice 1B repoints the CUSTOMER usages to the two
 * customer-only keys Slice 1A introduced (`labels.messaging_provider` =
 * "Messaging provider", `labels.sender_identity` = "Sender identity") and
 * leaves the shared keys, their English values, and every admin consumer
 * exactly as they were.
 *
 * That is the whole change. It is copy repointing only: no locale key is
 * renamed, no posted field name moves, no route name changes, no column
 * changes, and provider selection, campaign semantics, tenancy and
 * authorization are untouched. The tests below are arranged to prove each
 * of those separately, so a future edit cannot quietly break one while
 * keeping the others green.
 *
 * ON ASSERTION MECHANICS. "Sender identity" contains the substring
 * "Sender id". Every negative assertion here is therefore CASE-SENSITIVE and
 * targets the exact legacy display casing ("Sender ID", "Sending Server",
 * "Originator"), which the new copy cannot produce. A naive
 * case-insensitive check would report a false leak on the very term the
 * contract requires.
 *
 * KNOWN OUT-OF-SCOPE RESIDUALS, reported rather than silently fixed. These
 * screens still render three forbidden strings that arrive through keys the
 * Slice 1B allowlist does not authorize, and `resources/lang/en/locale.php`
 * is a Slice 1A path whose permitted mutation is explicitly "no other
 * existing value":
 *
 *   - `locale.labels.single_sender_id` = "You can insert only one sender
 *     id." — a JS validation message on 18 of the 27 screens.
 *   - `locale.sender_id.payment_for_sender_id` = "Payment for Sender ID" —
 *     `SenderID/checkout.blade.php` lines 88 and 92.
 *   - `locale.templates.dlt_description` = "…Sender ID fields will be
 *     available for TRAI DLT feature only" — `Templates/create.blade.php`
 *     line 33.
 *
 * These tests therefore assert what this slice actually owns — the labels
 * fed by the three repointed keys — and do not claim a page-wide absence of
 * the legacy nouns, which would be untrue.
 */
class LegacyOutreachTerminologyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    /** The exact 27 production paths of contract §6b / Correction 2 §7b. */
    private const SLICE_1B_PATHS = [
        'resources/views/customer/Outreach/_originator.blade.php',
        'resources/views/customer/Campaigns/campaignBuilder.blade.php',
        'resources/views/customer/Campaigns/import.blade.php',
        'resources/views/customer/Campaigns/mmsCampaignBuilder.blade.php',
        'resources/views/customer/Campaigns/mmsImport.blade.php',
        'resources/views/customer/Campaigns/mmsQuickSend.blade.php',
        'resources/views/customer/Campaigns/otpCampaignBuilder.blade.php',
        'resources/views/customer/Campaigns/otpImport.blade.php',
        'resources/views/customer/Campaigns/otpQuickSend.blade.php',
        'resources/views/customer/Campaigns/quickSend.blade.php',
        'resources/views/customer/Campaigns/updateCampaignBuilder.blade.php',
        'resources/views/customer/Campaigns/viberCampaignBuilder.blade.php',
        'resources/views/customer/Campaigns/viberImport.blade.php',
        'resources/views/customer/Campaigns/viberQuickSend.blade.php',
        'resources/views/customer/Campaigns/voiceCampaignBuilder.blade.php',
        'resources/views/customer/Campaigns/voiceImport.blade.php',
        'resources/views/customer/Campaigns/voiceQuickSend.blade.php',
        'resources/views/customer/Campaigns/whatsAppCampaignBuilder.blade.php',
        'resources/views/customer/Campaigns/whatsAppImport.blade.php',
        'resources/views/customer/Campaigns/whatsAppQuickSend.blade.php',
        'resources/views/customer/ChatBox/new.blade.php',
        'resources/views/customer/Developers/settings.blade.php',
        'resources/views/customer/SenderID/checkout.blade.php',
        'resources/views/customer/Templates/create.blade.php',
        'resources/views/customer/contactGroups/_settings.blade.php',
        'resources/views/customer/keywords/create.blade.php',
        'resources/views/customer/keywords/show.blade.php',
    ];

    /** The legacy display strings customer copy must no longer produce. */
    private const FORBIDDEN_DISPLAY = ['Sending Server', 'Sender ID', 'Originator'];

    // =================================================================
    // 1. The repoint itself, exhaustively over all 27 paths
    // =================================================================

    public function test_no_slice_1b_surface_still_references_an_admin_shared_key(): void
    {
        $offenders = [];

        foreach (self::SLICE_1B_PATHS as $relative) {
            $source = file_get_contents(base_path($relative));

            $this->assertNotFalse($source, "{$relative} must exist.");

            foreach (["locale.labels.sending_server'", "locale.labels.originator'", "locale.labels.sender_id'"] as $key) {
                if (str_contains($source, $key)) {
                    $offenders[] = "{$relative} still uses {$key}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_every_slice_1b_surface_now_uses_a_customer_only_key(): void
    {
        foreach (self::SLICE_1B_PATHS as $relative) {
            $source = file_get_contents(base_path($relative));

            $this->assertTrue(
                str_contains($source, 'locale.labels.messaging_provider')
                || str_contains($source, 'locale.labels.sender_identity'),
                "{$relative} must render at least one customer-only terminology key.",
            );
        }
    }

    /**
     * The repoint must not have disturbed the form contract. `for=`/`id=`/
     * `name=` attributes carry the posted field names, and they are the
     * reason the label text could be changed safely at all.
     */
    public function test_the_repoint_left_every_posted_field_name_in_place(): void
    {
        $expectations = [
            'resources/views/customer/Outreach/_originator.blade.php' => ['sending_server', 'sender_id'],
            'resources/views/customer/Campaigns/quickSend.blade.php' => ['sender_id'],
            'resources/views/customer/keywords/create.blade.php' => ['sender_id'],
            'resources/views/customer/ChatBox/new.blade.php' => ['sending_server'],
        ];

        foreach ($expectations as $relative => $fields) {
            $source = file_get_contents(base_path($relative));

            foreach ($fields as $field) {
                $this->assertMatchesRegularExpression(
                    '/(?:name|id|for)="' . preg_quote($field, '/') . '/',
                    $source,
                    "{$relative} must still carry the {$field} field identifier.",
                );
            }
        }
    }

    // =================================================================
    // 2. Rendered customer surfaces, driven through the real routes
    // =================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function slice1bRouteProvider(): array
    {
        return [
            'quick send' => ['customer.sms.quick_send'],
            'campaign builder' => ['customer.sms.campaign_builder'],
            'import' => ['customer.sms.import'],
            'keyword create' => ['customer.keywords.create'],
            'template create' => ['customer.templates.create'],
            'chat box new' => ['customer.chatbox.new'],
            'developer settings' => ['customer.developer.settings'],
        ];
    }

    /**
     * These legacy screens redirect to the subscription page without an
     * active subscription, so the fixture mirrors the depth the existing
     * Outreach suite already establishes for the same controllers.
     */
    private function subscribedCustomer(): Customer
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);

        $country = Country::firstOrCreate(
            ['country_code' => '1', 'iso_code' => 'US'],
            ['name' => 'United States', 'status' => 1],
        );
        $currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'format' => '$', 'status' => true],
        );

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Terminology Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode(['sender_id_verification' => 'yes']),
            'status' => true,
        ]);

        PlansCoverageCountries::create([
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'status' => true,
            'options' => json_encode(['plain' => true, 'mms' => true, 'plain_sms' => 0.05, 'mms_sms' => 0.10]),
        ]);

        Subscription::create([
            'user_id' => $customer->user_id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        // The provider label renders only when the customer actually has a
        // provider to choose, and the sender block only under the plan option
        // above — so both are given here rather than asserting on a screen
        // that legitimately renders neither.
        $server = SendingServer::create([
            'name' => 'Terminology Fixture Server',
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
        ]);

        CustomerBasedSendingServer::create([
            'user_id' => $customer->user_id,
            'sending_server' => $server->id,
            'status' => 1,
        ]);

        Senderid::create([
            'user_id' => $customer->user_id,
            'sender_id' => 'FIXTURE',
            'status' => 'active',
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
        ]);

        $this->authenticateAs($customer);

        return $customer;
    }

    /**
     * @dataProvider slice1bRouteProvider
     */
    public function test_a_slice_1b_screen_never_renders_the_legacy_customer_terminology(string $routeName): void
    {
        $this->subscribedCustomer();

        $response = $this->get(route($routeName))->assertOk();
        $html = $response->getContent();

        foreach (self::FORBIDDEN_DISPLAY as $term) {
            $this->assertStringNotContainsString(
                $term,
                $html,
                "[{$routeName}] must not render the legacy term \"{$term}\".",
            );
        }
    }

    /**
     * The positive half: the screens do not merely omit the old words, they
     * render the contracted new ones.
     */
    public function test_the_send_screens_render_the_contracted_customer_terms(): void
    {
        $this->subscribedCustomer();

        foreach (['customer.sms.quick_send', 'customer.sms.campaign_builder'] as $routeName) {
            $html = $this->get(route($routeName))->assertOk()->getContent();

            $this->assertStringContainsString('Messaging provider', $html, "[{$routeName}] must name the provider in customer terms.");
            $this->assertStringContainsString('Sender identity', $html, "[{$routeName}] must name the sender in customer terms.");
        }
    }

    public function test_the_keyword_and_chatbox_screens_render_the_contracted_customer_terms(): void
    {
        $this->subscribedCustomer();

        $this->assertStringContainsString(
            'Sender identity',
            $this->get(route('customer.keywords.create'))->assertOk()->getContent(),
        );

        $this->assertStringContainsString(
            'Messaging provider',
            $this->get(route('customer.chatbox.new'))->assertOk()->getContent(),
        );

        $this->assertStringContainsString(
            'Messaging provider',
            $this->get(route('customer.developer.settings'))->assertOk()->getContent(),
        );
    }

    /**
     * The form still posts what it always posted. Rendered proof, not a
     * source grep: the label moved, the field did not.
     */
    public function test_a_rendered_send_screen_still_carries_its_original_field_names(): void
    {
        $this->subscribedCustomer();

        $html = $this->get(route('customer.sms.quick_send'))->assertOk()->getContent();

        foreach (['sender_id', 'sending_server'] as $field) {
            $this->assertMatchesRegularExpression(
                '/(?:name|id|for)="' . $field . '/',
                $html,
                "The rendered form must still carry the {$field} identifier.",
            );
        }
    }

    // =================================================================
    // 3. Admin/customer separation — the point of the two new keys
    // =================================================================

    public function test_the_admin_shared_locale_values_are_untouched(): void
    {
        $this->assertSame('Sending Server', __('locale.labels.sending_server'));
        $this->assertSame('Originator', __('locale.labels.originator'));
        $this->assertSame('Sender ID', __('locale.labels.sender_id'));
    }

    public function test_the_customer_only_locale_values_are_the_contracted_ones(): void
    {
        $this->assertSame('Messaging provider', __('locale.labels.messaging_provider'));
        $this->assertSame('Sender identity', __('locale.labels.sender_identity'));
    }

    /**
     * Admin screens must still consume the shared keys. If a later edit
     * "helpfully" repointed admin too, the admin vocabulary would silently
     * change — which the contract forbids.
     */
    public function test_admin_views_still_consume_the_shared_keys(): void
    {
        $adminConsumers = [];

        foreach (glob(base_path('resources/views/admin/**/*.blade.php'), GLOB_BRACE) ?: [] as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, "locale.labels.sending_server'")
                || str_contains($source, "locale.labels.originator'")
                || str_contains($source, "locale.labels.sender_id'")) {
                $adminConsumers[] = $file;
            }
        }

        $this->assertNotEmpty(
            $adminConsumers,
            'Admin copy must still be driven by the shared keys this slice deliberately left alone.',
        );
    }

    public function test_no_admin_view_was_repointed_to_a_customer_only_key(): void
    {
        $repointed = [];

        foreach (glob(base_path('resources/views/admin/**/*.blade.php'), GLOB_BRACE) ?: [] as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, 'locale.labels.messaging_provider')
                || str_contains($source, 'locale.labels.sender_identity')) {
                $repointed[] = $file;
            }
        }

        $this->assertSame([], $repointed, 'Customer-only terminology must not leak into admin copy.');
    }

    // =================================================================
    // 4. Nothing else moved
    // =================================================================

    public function test_the_slice_1b_route_names_still_resolve_to_their_original_handlers(): void
    {
        $expected = [
            'customer.sms.quick_send' => 'CampaignController@quickSend',
            'customer.sms.campaign_builder' => 'CampaignController@campaignBuilder',
            'customer.sms.import' => 'CampaignController@import',
            'customer.keywords.create' => 'KeywordController@create',
            'customer.keywords.show' => 'KeywordController@show',
            'customer.templates.create' => 'TemplateController@create',
            'customer.chatbox.new' => 'ChatBoxController@new',
            'customer.developer.settings' => 'DeveloperController@settings',
        ];

        foreach ($expected as $name => $handler) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] must still be registered.");
            $this->assertStringEndsWith($handler, $route->getActionName(), "Route [{$name}] must still reach {$handler}.");
        }
    }

    /**
     * Copy repointing must not have moved an authorization boundary: an
     * unauthenticated visitor is still turned away from every screen this
     * slice touched.
     */
    public function test_the_slice_1b_screens_remain_closed_to_an_unauthenticated_visitor(): void
    {
        foreach (array_column(self::slice1bRouteProvider(), 0) as $routeName) {
            $status = $this->get(route($routeName))->getStatusCode();

            $this->assertContains(
                $status,
                [302, 401, 403],
                "[{$routeName}] must refuse an unauthenticated visitor, got {$status}.",
            );
        }
    }
}
