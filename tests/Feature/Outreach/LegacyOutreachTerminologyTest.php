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
 * "Sender id". Sections 1–4 below assert CASE-SENSITIVELY on the exact legacy
 * display casing ("Sender ID", "Sending Server", "Originator"), which the new
 * copy cannot produce; section 5's absolute sweep matches case-insensitively
 * and then discards hits that are really part of the approved term. Either
 * way, a naive case-insensitive `str_contains` would report a false leak on
 * the very term the contract requires.
 *
 * SECTION 5 — FINAL CLOSURE. The first pass through this slice reported three
 * residual forbidden strings as out of allowlist scope, and PR #243 carried
 * two of its own. T-TERM-2 admits no reachable-screen exception, so a later
 * correction authorized the minimum extra scope and closed all five, plus a
 * further set an absolute repo-wide audit turned up. Section 5 holds that
 * closure, including the delegated-access invitation email — the one customer
 * surface whose copy lives in the database rather than in locale.php.
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
     * Customer Experience Redesign Slice 2B moved the ChatBox compose screen
     * into the Business-scoped route family. The screen and its copy are the
     * same `ChatBox/new.blade.php` this slice repointed; only the route that
     * reaches it changed (the old flat `customer.chatbox.new` is now a GET
     * compatibility redirector). So the entry is updated, not removed.
     *
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
            'chat box new' => ['customer.workspaces.businesses.conversations.new'],
            'developer settings' => ['customer.developer.settings'],
        ];
    }

    /** The subscribed customer's own Workspace/Business pair, for 2B's routes. */
    private array $terminologyPair = ['no-workspace', 'no-business'];

    private function terminologyUrl(string $routeName): string
    {
        return str_starts_with($routeName, 'customer.workspaces.businesses.')
            ? route($routeName, $this->terminologyPair)
            : route($routeName);
    }

    /**
     * These legacy screens redirect to the subscription page without an
     * active subscription, so the fixture mirrors the depth the existing
     * Outreach suite already establishes for the same controllers.
     */
    private function subscribedCustomer(): Customer
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->terminologyPair = [$workspace->uid, $business->uid];

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

        // business_id: Slice 2B's compose screen offers only the providers
        // assigned to the selected Business (§6).
        CustomerBasedSendingServer::create([
            'user_id' => $customer->user_id,
            'business_id' => $business->id,
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

        $response = $this->get($this->terminologyUrl($routeName))->assertOk();
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
            $this->get($this->terminologyUrl('customer.workspaces.businesses.conversations.new'))->assertOk()->getContent(),
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
            // Slice 2B: the compose screen's route moved into the Business
            // family; the old flat name survives as a GET redirector.
            'customer.workspaces.businesses.conversations.new' => 'ChatBoxController@new',
            'customer.chatbox.new' => 'ChatBoxController@legacyNew',
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
            $status = $this->get($this->terminologyUrl($routeName))->getStatusCode();

            $this->assertContains(
                $status,
                [302, 401, 403],
                "[{$routeName}] must refuse an unauthenticated visitor, got {$status}.",
            );
        }
    }

    // =================================================================
    // 5. Final terminology closure — T-TERM-2 is absolute
    //
    // The contract admits no reachable-customer-screen exception, so the
    // residuals the first pass reported are closed here rather than carried.
    // Two mechanisms, chosen per key by who actually consumes it: a
    // customer-only key has its English value corrected in place, and a key
    // shared with admin keeps its value and gains a customer-only sibling, so
    // admin vocabulary is preserved exactly.
    //
    // The one customer surface whose copy does not come from locale.php is
    // the delegated-access invitation email; it lives in an `email_templates`
    // row and is corrected by an additive, idempotent data migration.
    // =================================================================

    /** Every forbidden noun, as the contract lists them. */
    private const T_TERM_2 = [
        'Sending Server', 'Sender ID', 'Originator',
        'Sub Account', 'Sub-Account', 'Account SID', 'Auth Token', 'API Key',
    ];

    /**
     * Case-insensitive, but "Sender identity"/"Sender identities" are the
     * approved replacements and contain "sender id" — so a hit that is really
     * part of the approved term is not a leak.
     */
    private function forbiddenTermsIn(string $text): array
    {
        $found = [];

        foreach (self::T_TERM_2 as $term) {
            $offset = 0;

            while (($pos = stripos($text, $term, $offset)) !== false) {
                $offset = $pos + 1;

                if (preg_match('/^sender[ -]?identit/i', substr($text, $pos, 20)) === 1) {
                    continue;
                }

                $found[$term] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * What a person actually reads.
     *
     * T-TERM-2 governs rendered customer COPY, not HTML source. Markup the
     * customer never sees is stripped first — attributes (`name="originator"`),
     * URL paths (`href=".../sub-accounts"`, an identifier the contract keeps
     * on purpose), HTML comments and JavaScript comments.
     *
     * JavaScript STRING literals are deliberately kept: the confirm dialogs on
     * these screens are Blade-rendered JS messages, and the contract names
     * them explicitly as customer-visible copy. `strip_tags()` keeps the body
     * of a <script> block while discarding every tag and attribute around it,
     * which is exactly the line we want.
     */
    private function visibleText(string $html): string
    {
        $text = preg_replace('/<!--.*?-->/s', '', $html);
        $text = preg_replace('#^\s*//.*$#m', '', $text);
        $text = preg_replace('#/\*.*?\*/#s', '', $text);
        $text = strip_tags($text);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    }

    public function test_the_visible_text_helper_drops_markup_but_keeps_rendered_javascript_copy(): void
    {
        $html = '<a href="http://x/sub-accounts">Team members</a>'
            . '<input name="originator" value="sender_id"/>'
            . '<!-- Sender ID in an HTML comment -->'
            . "<script>\n"
            . "    // sender id in a JS comment\n"
            . '    Swal.fire({text: "Delete selected sender identities?"});' . "\n"
            . '</script>';

        $visible = $this->visibleText($html);

        $this->assertStringNotContainsString('sub-accounts', $visible, 'URL paths are not copy.');
        $this->assertStringNotContainsString('originator', $visible, 'Attributes are not copy.');
        $this->assertStringNotContainsString('HTML comment', $visible);
        $this->assertStringNotContainsString('JS comment', $visible);

        // …but the message a customer actually sees survives.
        $this->assertStringContainsString('Delete selected sender identities?', $visible);
        $this->assertStringContainsString('Team members', $visible);
    }

    public function test_the_helper_does_not_mistake_the_approved_term_for_a_leak(): void
    {
        // Guards the guard: if this ever starts flagging, every assertion
        // below becomes meaningless.
        $this->assertSame([], $this->forbiddenTermsIn('Sender identity'));
        $this->assertSame([], $this->forbiddenTermsIn('Delete selected sender identities?'));
        $this->assertSame(['Sender ID'], $this->forbiddenTermsIn('Payment for Sender ID'));
        $this->assertSame(['Sub-Account'], $this->forbiddenTermsIn('join as a Sub-Account'));
    }

    /** The three residuals the first pass reported. */
    public function test_the_three_reported_residuals_are_clean(): void
    {
        $this->assertSame('You can select only one sender identity.', __('locale.labels.single_sender_id'));
        $this->assertSame('Payment for sender identity', __('locale.sender_id.payment_for_sender_id'));

        $this->assertSame([], $this->forbiddenTermsIn(__('locale.templates.dlt_description_customer')));
        $this->assertStringContainsString('TRAI DLT', __('locale.templates.dlt_description_customer'), 'The sentence must be preserved.');
    }

    /** …and the further leaks the absolute audit turned up. */
    public function test_every_customer_only_value_the_audit_found_is_clean(): void
    {
        $keys = [
            'locale.customer.sender_id_verification',
            'locale.developers.select_sending_server_for_api_messages',
            'locale.labels.new_sender_id_notification',
            'locale.plans.need_sender_id_verification_customer',
            'locale.plans.sending_server_for_sms_customer',
            'locale.sending_servers.have_no_sending_server_customer',
            'locale.sender_id.delete_senderids_customer',
        ];

        foreach ($keys as $key) {
            $value = __($key);

            $this->assertNotSame($key, $value, "{$key} must resolve.");
            $this->assertSame([], $this->forbiddenTermsIn($value), "{$key} renders \"{$value}\".");
        }
    }

    /** The admin half of every shared key is deliberately untouched. */
    public function test_admin_keeps_the_legacy_vocabulary_on_every_shared_key(): void
    {
        $this->assertSame('Sending Server', __('locale.labels.sending_server'));
        $this->assertSame('Originator', __('locale.labels.originator'));
        $this->assertSame('Sender ID', __('locale.labels.sender_id'));
        $this->assertSame('Sender ID', __('locale.menu.Sender ID'));
        $this->assertSame('API Key', __('locale.labels.api_key'));
        $this->assertSame('Need Sender ID Verification', __('locale.plans.need_sender_id_verification'));
        $this->assertSame('Sending server for :sms_type sms', __('locale.plans.sending_server_for_sms'));
        $this->assertSame('You have no sending server', __('locale.sending_servers.have_no_sending_server'));
        $this->assertSame('Delete selected sender IDs?', __('locale.sender_id.delete_senderids'));
        $this->assertStringContainsString('Sender ID', __('locale.templates.dlt_description'));
    }

    /** Task B1 — the customer breadcrumb, driven through the real routes. */
    public function test_the_customer_sender_identity_screens_render_a_clean_breadcrumb(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        foreach (['customer.senderid.index', 'customer.senderid.request'] as $routeName) {
            $html = $this->get(route($routeName))->assertOk()->getContent();

            $this->assertSame(
                [],
                $this->forbiddenTermsIn($this->visibleText($html)),
                "[{$routeName}] still renders a forbidden term.",
            );

            $this->assertStringContainsString('Sender identities', $html);
        }
    }

    /** The admin controller's own breadcrumb key was NOT moved. */
    public function test_the_admin_sender_id_controller_still_uses_the_legacy_menu_key(): void
    {
        $admin = file_get_contents(base_path('app/Http/Controllers/Admin/SenderIDController.php'));

        $this->assertStringContainsString("locale.menu.Sender ID'", $admin);

        $customer = file_get_contents(base_path('app/Http/Controllers/Customer/SenderIDController.php'));

        $this->assertStringNotContainsString("locale.menu.Sender ID'", $customer);
        $this->assertStringContainsString("locale.menu.Sender identities'", $customer);
    }

    // -----------------------------------------------------------------
    // Task B2 — the invitation email, whose copy lives in the database
    // -----------------------------------------------------------------

    private function invitationTemplate(): object
    {
        $row = \Illuminate\Support\Facades\DB::table('email_templates')
            ->where('slug', 'subaccount_invitation_notification')
            ->first();

        $this->assertNotNull($row, 'The invitation template row must exist after migrating.');

        return $row;
    }

    public function test_the_invitation_email_row_carries_no_forbidden_noun_after_migrating(): void
    {
        $row = $this->invitationTemplate();

        $this->assertSame([], $this->forbiddenTermsIn((string) $row->subject), "subject: {$row->subject}");
        $this->assertSame([], $this->forbiddenTermsIn((string) $row->content));
    }

    /** The correction must not damage what the mailable substitutes. */
    public function test_the_invitation_email_keeps_every_placeholder_and_its_identifiers(): void
    {
        $row = $this->invitationTemplate();

        $this->assertStringContainsString('{app_name}', (string) $row->subject);

        foreach (['{first_name}', '{last_name}', '{invitation_link}'] as $placeholder) {
            $this->assertStringContainsString($placeholder, (string) $row->content);
        }

        $this->assertSame('subaccount_invitation_notification', $row->slug, 'The slug is an identifier and must not move.');
        $this->assertTrue((bool) $row->status);
    }

    /**
     * Replay safety: the migration keys its substitution on the forbidden
     * noun, so running it again finds nothing to change.
     */
    public function test_the_invitation_correction_is_idempotent_on_replay(): void
    {
        $before = $this->invitationTemplate();

        $migration = require base_path('database/migrations/2026_09_13_100001_correct_sub_account_invitation_email_terminology.php');
        $migration->up();
        $migration->up();

        $after = $this->invitationTemplate();

        $this->assertSame($before->subject, $after->subject);
        $this->assertSame($before->content, $after->content);
        $this->assertSame($before->uid, $after->uid, 'Replay must not reissue the row.');
    }

    /**
     * Rollback is a deliberate no-op — reversing would reintroduce copy the
     * contract forbids — and must neither error nor disturb the row.
     */
    public function test_the_invitation_correction_rollback_is_an_inert_no_op(): void
    {
        $before = $this->invitationTemplate();

        $migration = require base_path('database/migrations/2026_09_13_100001_correct_sub_account_invitation_email_terminology.php');
        $migration->down();

        $after = $this->invitationTemplate();

        $this->assertSame($before->subject, $after->subject);
        $this->assertSame($before->content, $after->content);
    }

    /** A fresh installation converges on the same clean row. */
    public function test_a_freshly_seeded_row_is_corrected_by_the_migration(): void
    {
        \Illuminate\Support\Facades\DB::table('email_templates')
            ->where('slug', 'subaccount_invitation_notification')
            ->update([
                'subject' => 'You are invited to join as a Sub-Account on {app_name}',
                'content' => 'Hi {first_name} {last_name},<br><br>You have been invited to join as a sub-account. Please click {invitation_link}<br><br>',
            ]);

        $migration = require base_path('database/migrations/2026_09_13_100001_correct_sub_account_invitation_email_terminology.php');
        $migration->up();

        $row = $this->invitationTemplate();

        $this->assertSame([], $this->forbiddenTermsIn((string) $row->subject));
        $this->assertSame([], $this->forbiddenTermsIn((string) $row->content));
        $this->assertStringContainsString('{invitation_link}', (string) $row->content);
    }

    // -----------------------------------------------------------------
    // The absolute sweep, over the surfaces this correction can reach
    // -----------------------------------------------------------------

    public function test_no_reachable_customer_screen_renders_a_forbidden_term(): void
    {
        $this->subscribedCustomer();

        $routes = array_merge(
            array_column(self::slice1bRouteProvider(), 0),
            ['customer.senderid.index', 'customer.senderid.request'],
        );

        foreach ($routes as $routeName) {
            $html = $this->get($this->terminologyUrl($routeName))->assertOk()->getContent();

            $this->assertSame(
                [],
                $this->forbiddenTermsIn($this->visibleText($html)),
                "[{$routeName}] renders a T-TERM-2 forbidden term.",
            );
        }
    }

    /** Identifiers stay put, however much the copy moved. */
    public function test_internal_identifiers_are_unchanged_by_the_closure(): void
    {
        // Locale KEY names survive; only English values moved.
        $locale = require base_path('resources/lang/en/locale.php');

        foreach ([['labels', 'sender_id'], ['labels', 'sending_server'], ['labels', 'originator'],
            ['labels', 'single_sender_id'], ['sender_id', 'payment_for_sender_id'],
            ['templates', 'dlt_description'], ['menu', 'Sender ID']] as [$block, $key]) {
            $this->assertArrayHasKey($key, $locale[$block], "locale.{$block}.{$key} must still exist.");
        }

        // Routes still resolve to their original handlers.
        foreach ([
            'customer.senderid.index' => 'SenderIDController@index',
            'customer.senderid.request' => 'SenderIDController@request',
        ] as $name => $handler) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] must still be registered.");
            $this->assertStringEndsWith($handler, $route->getActionName());
        }

        // The email template is addressed by slug, and the column names the
        // mailable reads are untouched.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('email_templates', ['slug', 'subject', 'content', 'status']));
    }
}
