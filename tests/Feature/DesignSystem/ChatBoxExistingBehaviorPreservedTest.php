<?php

namespace Tests\Feature\DesignSystem;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Design System M2 Slice 6 (ChatBox / Conversations) — behavior-preservation
 * matrix (§6/§8 of the Slice 6 contract, post-security baseline at
 * 98d67f198784320b97b6f10f6852d8d7b025e693). Proves the restyle left every
 * route, AJAX endpoint, DOM/JS/plugin hook, and the merged PR #182 security
 * remediation's tenant-scoping/permission-check/safe-rendering behavior
 * untouched. ChatBoxController.php is intentionally outside this slice's
 * write allowlist, so its own preservation is proven here as deterministic
 * source/HTTP assertions, not by re-deriving the full security suite
 * (already exhaustively covered by the pinned tests/Feature/Security/
 * ChatBoxSecurityTest.php, run first per §15/Part 15 ordering). This file
 * makes zero assertion that would require an app/database/routes change to
 * satisfy, and never asserts a stale 403 — this application's own
 * pre-existing Handler.php maps AuthorizationException to 401 outside
 * local mode, unchanged by this presentation pass.
 *
 * Customer Experience Redesign Slice 2B moved the inbox to `Account →
 * selected Business → Conversations`. The tests pinning the old flat routes,
 * endpoint strings and per-login resolver are updated in place — never
 * deleted — to the Business-scoped equivalents; each says so in its own
 * docblock. The presentation hooks this file protects are unchanged.
 */
class ChatBoxExistingBehaviorPreservedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    // -----------------------------------------------------------------
    // Routes — the same 10 actions remain available
    // -----------------------------------------------------------------

    /**
     * Superseded by Customer Experience Redesign Slice 2B §7/§8, stated
     * rather than hidden: the ten actions this test pinned now live in the
     * Business-scoped family `customer.workspaces.businesses.conversations.*`.
     * The eight flat POST names are retired; `customer.chatbox.index` and
     * `customer.chatbox.new` survive only as GET compatibility redirectors.
     * The property is unchanged — every one of the ten actions resolves.
     */
    public function test_all_10_chatbox_route_names_still_resolve(): void
    {
        [, $box, $business, $workspace] = $this->authenticatedCustomerWithChatBox();
        $pair = [$workspace->uid, $business->uid];

        $named = [
            'customer.workspaces.businesses.conversations.index' => $pair,
            'customer.workspaces.businesses.conversations.new' => $pair,
            'customer.workspaces.businesses.conversations.sent' => $pair,
            'customer.workspaces.businesses.conversations.messages' => [...$pair, $box->uid],
            'customer.workspaces.businesses.conversations.notification' => [...$pair, $box->uid],
            'customer.workspaces.businesses.conversations.reply' => [...$pair, $box->uid],
            'customer.workspaces.businesses.conversations.delete' => [...$pair, $box->uid],
            'customer.workspaces.businesses.conversations.block' => [...$pair, $box->uid],
            'customer.workspaces.businesses.conversations.pin' => [...$pair, $box->uid],
            'customer.workspaces.businesses.conversations.load' => $pair,
        ];

        foreach ($named as $name => $params) {
            // route() throws if the name is not registered — reaching the
            // assertion at all is itself the proof.
            $this->assertIsString(route($name, $params));
        }

        $this->assertCount(10, $named);

        // The two compatibility redirectors, and nothing else, keep the
        // old names.
        $this->assertSame(url('/chat-box'), route('customer.chatbox.index'));
        $this->assertSame(url('/chat-box/new'), route('customer.chatbox.new'));
    }

    // -----------------------------------------------------------------
    // AJAX endpoint URL construction — source-level
    // -----------------------------------------------------------------

    /**
     * Superseded by Slice 2B §7: every endpoint is now rendered by the
     * server from the Business-scoped route family (one `conversationRoutes`
     * map, uid substituted client-side) instead of being concatenated onto
     * `url('/chat-box')`. The template picker uses B1's Business-scoped
     * template endpoint.
     */
    public function test_ajax_endpoint_url_constructions_are_unchanged(): void
    {
        $index = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        foreach (['messages', 'notification', 'reply', 'delete', 'block', 'pin'] as $action) {
            $this->assertStringContainsString(
                "{$action}: \"{{ route('customer.workspaces.businesses.conversations.{$action}', [\$workspaceUid, \$businessUid, '__UID__']) }}\"",
                $index,
            );
        }

        $this->assertStringContainsString("load: \"{{ route('customer.workspaces.businesses.conversations.load', [\$workspaceUid, \$businessUid]) }}\"", $index);
        $this->assertStringContainsString("const conversationUrl = (name, uid) => conversationRoutes[name].replace('__UID__', encodeURIComponent(uid));", $index);

        foreach (["conversationUrl('messages', chat_id)", "conversationUrl('reply', chatBoxId)", "conversationUrl('delete', sms_id)", "conversationUrl('block', sms_id)", "conversationUrl('pin', sms_id)", "conversationUrl('notification', chat_id)", 'url: conversationRoutes.load'] as $call) {
            $this->assertStringContainsString($call, $index);
        }

        $this->assertStringNotContainsString("url('/chat-box", $index);
        $this->assertStringNotContainsString("url('templates/show-data')", $index);
        $this->assertStringContainsString("route('customer.workspaces.businesses.outreach.templates.show_data', [\$workspaceUid, \$businessUid, '__ID__'])", $index);

        $new = file_get_contents(base_path('resources/views/customer/ChatBox/new.blade.php'));
        $this->assertStringNotContainsString("url('templates/show-data')", $new);
        $this->assertStringContainsString("route('customer.workspaces.businesses.outreach.templates.show_data', [\$workspaceUid, \$businessUid, '__ID__'])", $new);
        $this->assertStringContainsString("route('customer.workspaces.businesses.conversations.sent', [\$workspaceUid, \$businessUid])", $new);
    }

    // -----------------------------------------------------------------
    // Composer / attachment / SMS-template picker structure
    // -----------------------------------------------------------------

    public function test_composer_structure_ids_names_and_accept_attribute_preserved(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        foreach (['id="message"', 'id="mms-icon"', 'id="media_image"', 'id="sms_template"', 'name="media_image"'] as $hook) {
            $this->assertStringContainsString($hook, $contents, "Expected {$hook} to survive.");
        }

        $this->assertStringContainsString('accept="image/*,video/*"', $contents);
        $this->assertStringContainsString('class="form-control message"', $contents);
        $this->assertStringContainsString('form-send-message', $contents);
    }

    public function test_new_blade_hidden_idempotency_token_binding_preserved_byte_for_byte(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/new.blade.php'));

        $this->assertStringContainsString(
            '<input type="hidden" name="idempotency_token" value="{{ $idempotencyToken }}">',
            $contents
        );
        $this->assertStringContainsString('<input type="hidden" name="sms_type" value="plain">', $contents);
    }

    public function test_sms_template_picker_present_and_functionally_identical_in_both_files(): void
    {
        $index = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));
        $this->assertStringContainsString('id="sms_template"', $index);
        $this->assertStringContainsString('$("#sms_template").on("change", function', $index);

        $new = file_get_contents(base_path('resources/views/customer/ChatBox/new.blade.php'));
        $this->assertStringContainsString('id="sms_template"', $new);
        $this->assertStringContainsString("\$(\"#sms_template\").on('change', function", $new);
    }

    // -----------------------------------------------------------------
    // Both Echo/Pusher guards — unmodified, independent
    // -----------------------------------------------------------------

    public function test_both_echo_pusher_guards_remain_independent_and_unmodified(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertMatchesRegularExpression(
            '/@if\(config\(\'broadcasting\.connections\.pusher\.app_id\'\)\)\s*<script src="\{\{ asset\(mix\(\'js\/scripts\/echo\.js\'\)\) \}\}"><\/script>/',
            $contents
        );
        $this->assertStringContainsString('window.Echo = new Echo({', $contents);
        $this->assertStringContainsString('Echo.private("chat").listen("MessageReceived"', $contents);
    }

    // -----------------------------------------------------------------
    // Significant class/id/data-* JS-selector hooks
    // -----------------------------------------------------------------

    public function test_every_significant_js_selector_hook_survives(): void
    {
        $index = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        foreach ([
            '.tab-button', '.send', '.message', '.counter', '.notification_count',
            '.active', 'chat-left', 'chat-time', '.add-to-pin', '.add-to-blacklist',
            '.remove-btn', '.start-chat-area', '.active-chat',
        ] as $selector) {
            $this->assertStringContainsString($selector, $index, "Expected {$selector} to survive in index.blade.php.");
        }
    }

    // -----------------------------------------------------------------
    // Post-remediation UID identifier boundary — real HTTP proof
    // -----------------------------------------------------------------

    public function test_pinned_and_ajax_lists_both_use_uid_for_data_id_and_retain_numeric_data_box_id(): void
    {
        [$customer, $box, $business, $workspace] = $this->authenticatedCustomerWithChatBox();
        $box->update(['pinned' => true]);
        $unpinnedBox = $this->businessChatBox($business, 'AgentB', '15550009003');
        $pair = [$workspace->uid, $business->uid];

        $indexResponse = $this->get(route('customer.workspaces.businesses.conversations.index', $pair));
        $indexResponse->assertOk();
        $indexResponse->assertSee('data-id="' . $box->uid . '"', false);
        $indexResponse->assertSee('data-box-id="' . $box->id . '"', false);
        $indexResponse->assertDontSee('data-id="' . $box->id . '"', false);

        // Slice 2B: the list endpoint is POST-only (§7).
        $ajaxResponse = $this->post(route('customer.workspaces.businesses.conversations.load', $pair));
        $ajaxResponse->assertOk();
        $ajaxResponse->assertSee('data-id="' . $unpinnedBox->uid . '"', false);
        $ajaxResponse->assertSee('data-box-id="' . $unpinnedBox->id . '"', false);
        $ajaxResponse->assertDontSee('data-id="' . $unpinnedBox->id . '"', false);
    }

    // -----------------------------------------------------------------
    // Post-remediation security boundary survives the presentation pass
    // (targeted spot-checks — the exhaustive proof lives in the pinned
    // tests/Feature/Security/ChatBoxSecurityTest.php, run before this file)
    // -----------------------------------------------------------------

    /**
     * Superseded by Slice 2B §9: the controller is no longer untouched —
     * the per-login resolveOwnedChatBox() became the Business-first chain
     * (Workspace → Business → access → chat_box → entitlement → uid AND
     * business_id). The same guarantees are asserted against the new
     * shape: one resolver, the permission gate still present, block()'s
     * Contacts update scoped (now by Business), and no raw chat_boxes query.
     */
    public function test_resolve_owned_chatbox_and_authorize_gate_still_present_in_the_untouched_controller(): void
    {
        $contents = file_get_contents(base_path('app/Http/Controllers/Customer/ChatBoxController.php'));

        $this->assertSame(0, substr_count($contents, 'function resolveOwnedChatBox'));
        $this->assertSame(1, substr_count($contents, 'private function resolveBusinessChatBox('));
        $this->assertSame(1, substr_count($contents, "authorize('chat_box')"), 'One gate, inside the single resolver every Business action runs through.');
        $this->assertMatchesRegularExpression("/->where\('uid', \\\$uid\)\s*->where\('business_id', \\\$business->id\)/", $contents);
        $this->assertMatchesRegularExpression("/Contacts::query\(\)\s*->where\('business_id', \\\$business->id\)/", $contents);
        $this->assertSame(0, substr_count($contents, "DB::table('chat_boxes')"));
    }

    public function test_foreign_chatbox_pin_is_still_denied_after_the_presentation_restyle(): void
    {
        [, , $business, $workspace] = $this->authenticatedCustomerWithChatBox();
        $foreignBox = ChatBox::create([
            'user_id' => $this->anotherCustomerId(), 'from' => 'AgentC', 'to' => '15550009004',
            'reply_by_customer' => true,
        ]);

        $response = $this->postJson(route('customer.workspaces.businesses.conversations.pin', [$workspace->uid, $business->uid, $foreignBox->uid]));

        $response->assertStatus(404);
    }

    public function test_missing_chat_box_permission_still_denies_messages_with_401(): void
    {
        [, $box, $business, $workspace] = $this->authenticatedCustomerWithChatBox();
        $this->withSession(['permissions' => collect(['access_backend'])]);

        // Plain (non-JSON) request — this app's exception handler returns
        // 200 with a JSON error body for wantsJson() requests, so a
        // non-JSON request is required to observe the real 401 status.
        $response = $this->post(route('customer.workspaces.businesses.conversations.messages', [$workspace->uid, $business->uid, $box->uid]));

        $response->assertStatus(401);
    }

    public function test_safe_message_and_media_helpers_and_their_presence_rules_survive(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(1, substr_count($contents, 'function safeMessageParagraph(value) {'));
        $this->assertSame(1, substr_count($contents, 'function safeTypedMediaParagraph(url, imgAlt) {'));
        $this->assertStringNotContainsString('${sms.message}', $contents);
        $this->assertStringNotContainsString('${sms.media_url}', $contents);
        $this->assertStringNotContainsString('"<p>" + messageValue + "</p>"', $contents);

        // History
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.message\)\s*\{\s*\$content\.append\(safeMessageParagraph\(sms\.message\)\);/',
            $contents
        );
        // Optimistic — unconditional
        $this->assertStringContainsString('$content.append(safeMessageParagraph(messageValue));', $contents);
        $this->assertStringNotContainsString('if (messageValue)', $contents);
        // Echo
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.message\s*!==\s*null\)\s*\{\s*\$content\.append\(safeMessageParagraph\(sms\.message\)\);/',
            $contents
        );

        // Media presence rules
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.media_url\s*!==\s*null\)\s*\{\s*\$content\.append\(safeTypedMediaParagraph\(sms\.media_url,\s*"media"\)\);/',
            $contents
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.media_url\s*!==\s*null\)\s*\{\s*\$content\.append\(safeTypedMediaParagraph\(sms\.media_url,\s*""\)\);/',
            $contents
        );
        $this->assertStringContainsString('if (response.media_url) {', $contents);
    }

    public function test_child_order_and_optimistic_200px_image_only_constraint_preserved(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        // History: media -> message -> chat-time.
        $historyStart = strpos($contents, 'cwData.forEach((sms) => {');
        $historyEnd = strpos($contents, 'chatContainer.animate({ scrollTop: chatContainer[0].scrollHeight }, 400);', $historyStart);
        $historyRegion = substr($contents, $historyStart, $historyEnd - $historyStart);
        $this->assertTrue(
            strpos($historyRegion, 'safeTypedMediaParagraph(') < strpos($historyRegion, 'safeMessageParagraph(')
            && strpos($historyRegion, 'safeMessageParagraph(') < strpos($historyRegion, 'chat-time')
        );

        // Optimistic: message always -> optional media -> no chat-time; exact 200px sizing.
        $optimisticStart = strpos($contents, 'let chatHistory = $(".chat_history");');
        $optimisticEnd = strpos($contents, 'message.val("");', $optimisticStart);
        $optimisticRegion = substr($contents, $optimisticStart, $optimisticEnd - $optimisticStart);
        $this->assertTrue(strpos($optimisticRegion, 'safeMessageParagraph(messageValue)') < strpos($optimisticRegion, '.attr("src", response.media_url)'));
        $this->assertStringNotContainsString('chat-time', $optimisticRegion);
        $this->assertSame(1, substr_count($contents, 'max-width:200px; max-height:200px;'));

        // Echo: media -> message -> chat-time, before the activeChatID branch.
        $echoStart = strpos($contents, 'const sms = response.data;');
        $echoEnd = strpos($contents, '@endif', $echoStart);
        $echoRegion = substr($contents, $echoStart, $echoEnd - $echoStart);
        $this->assertTrue(
            strpos($echoRegion, 'safeTypedMediaParagraph(') < strpos($echoRegion, 'safeMessageParagraph(')
            && strpos($echoRegion, 'safeMessageParagraph(') < strpos($echoRegion, 'chat-time')
            && strpos($echoRegion, 'chat-time') < strpos($echoRegion, 'if (chat_id === activeChatID)')
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Slice 2B: a conversation now belongs to a Business, so the fixture is
     * a customer owning one Workspace + Business with a Business-scoped box.
     *
     * @return array{0: Customer, 1: ChatBox, 2: Business, 3: Workspace}
     */
    private function authenticatedCustomerWithChatBox(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Preserved Co ' . uniqid(), 'Preserved WS ' . uniqid());

        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();

        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $box = $this->businessChatBox($business, 'AgentA', '15550009005');

        $this->actingAs($user);

        return [$customer, $box, $business, $workspace];
    }

    private function businessChatBox(Business $business, string $from, string $to): ChatBox
    {
        $box = new ChatBox([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => $from,
            'to' => $to,
            'reply_by_customer' => true,
        ]);
        $box->uid = (string) Str::uuid();
        $box->save();

        return $box;
    }

    private function anotherCustomerId(): int
    {
        $user = User::create([
            'first_name' => 'Other',
            'last_name' => 'Customer',
            'email' => 'other-customer' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        Customer::create(['user_id' => $user->id]);

        return $user->id;
    }
}
