<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 Slice 6 — ChatBox Security Remediation Contract.
 *
 * Covers: canonical-uid ownership resolution for all six single-record
 * actions (messages, messagesWithNotification, reply, delete, block,
 * pin) plus loadChatUsers's permission gate (§§Part 2-4); the
 * authorize-before-resolve ordering fix, most load-bearing for reply()
 * (§Part 3); identifier consistency between the pinned/AJAX chat lists
 * (§Part 1); block()'s Contacts tenant-isolation fix (§Part 4); the safe
 * message/media rendering helpers introduced in index.blade.php
 * (§§Part 5-6); path-specific structural preservation (§Part 7); and
 * RFC-005 Milestone 5 idempotency/billing preservation under the new
 * ownership boundary (§Part 4/10.H).
 *
 * Sections E, F and G assert facts about client-side JavaScript source
 * text. PHPUnit cannot execute that JavaScript — these are honest,
 * source-level mechanical assertions (exact substrings/regexes against
 * the rendered Blade/JS source), proving the expected code shape exists,
 * not that a browser renders it correctly. Every other section is a real
 * HTTP/model assertion against the actual running application.
 */
class ChatBoxSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        // Consumes user id 1, which EloquentAccountRepository::hasPermission()
        // always treats as an unconditional super admin — without this, a
        // freshly created test customer could accidentally land on id 1 and
        // bypass every permission check this file exists to prove.
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

    // ===================================================================
    // A. Ownership — all six single-record actions
    // ===================================================================

    public function test_messages_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxA = $this->createChatBox($tenantA->user_id, 'AgentA', '15550001001');
        $boxB = $this->createChatBox($tenantB->user_id, 'AgentB', '15550002001');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $ownResponse = $this->postJson(route('customer.chatbox.messages', $boxA->uid));
        $ownResponse->assertOk();
        $ownResponse->assertJson(['status' => 'success']);

        $foreignResponse = $this->postJson(route('customer.chatbox.messages', $boxB->uid));
        $nonexistentResponse = $this->postJson(route('customer.chatbox.messages', 'nonexistent-' . uniqid()));

        // messages() sets no explicit status on its denial branch — 200 with an error body.
        $foreignResponse->assertOk();
        $foreignResponse->assertJson(['status' => 'error', 'data' => [], 'pinned' => 0]);
        $nonexistentResponse->assertOk();
        $nonexistentResponse->assertJson(['status' => 'error', 'data' => [], 'pinned' => 0]);
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent());
    }

    public function test_messages_with_notification_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxA = $this->createChatBox($tenantA->user_id, 'AgentA', '15550001002');
        ChatBoxMessage::create(['box_id' => $boxA->id, 'message' => 'Hi', 'direction' => 'incoming']);
        $boxB = $this->createChatBox($tenantB->user_id, 'AgentB', '15550002002');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $ownResponse = $this->postJson(route('customer.chatbox.notification', $boxA->uid));
        $ownResponse->assertOk();
        $ownResponse->assertJson(['status' => 'success']);

        $foreignResponse = $this->postJson(route('customer.chatbox.notification', $boxB->uid));
        $nonexistentResponse = $this->postJson(route('customer.chatbox.notification', 'nonexistent-' . uniqid()));

        $foreignResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);
        $foreignResponse->assertJson(['status' => 'error', 'message' => 'Chat box not found.']);
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent());
    }

    public function test_reply_foreign_and_nonexistent_denied_identically_before_reaching_any_business_logic(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxB = $this->createChatBox($tenantB->user_id, 'AgentB', '15550002003');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $foreignResponse = $this->postJson(route('customer.chatbox.reply', $boxB->uid), ['message' => 'hi']);
        $nonexistentResponse = $this->postJson(route('customer.chatbox.reply', 'nonexistent-' . uniqid()), ['message' => 'hi']);

        $foreignResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);
        $foreignResponse->assertJson(['status' => 'error', 'message' => 'Chat box not found. Refresh page.']);
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent());

        // Denied before ever reaching the idempotency-token check, spam
        // check, or quickSend()/billing.
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
    }

    public function test_delete_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxA = $this->createChatBox($tenantA->user_id, 'AgentA', '15550001004');
        ChatBoxMessage::create(['box_id' => $boxA->id, 'message' => 'Hi', 'direction' => 'incoming']);
        $boxB = $this->createChatBox($tenantB->user_id, 'AgentB', '15550002004');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $foreignResponse = $this->postJson(route('customer.chatbox.delete', $boxB->uid));
        $nonexistentResponse = $this->postJson(route('customer.chatbox.delete', 'nonexistent-' . uniqid()));

        $foreignResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent());
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $boxB->id)->count());

        $ownResponse = $this->postJson(route('customer.chatbox.delete', $boxA->uid));
        $ownResponse->assertOk();
        $ownResponse->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $boxA->id)->count());
    }

    public function test_block_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxA = $this->createChatBox($tenantA->user_id, 'AgentA', '15550001005');
        $boxB = $this->createChatBox($tenantB->user_id, 'AgentB', '15550002005');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $foreignResponse = $this->postJson(route('customer.chatbox.block', $boxB->uid));
        $nonexistentResponse = $this->postJson(route('customer.chatbox.block', 'nonexistent-' . uniqid()));

        $foreignResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent());
        $this->assertSame(0, DB::table('blacklists')->where('number', $boxB->to)->count());

        $ownResponse = $this->postJson(route('customer.chatbox.block', $boxA->uid));
        $ownResponse->assertOk();
        $ownResponse->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('blacklists')->where('number', $boxA->to)->where('user_id', $tenantA->user_id)->count());
    }

    public function test_pin_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxA = $this->createChatBox($tenantA->user_id, 'AgentA', '15550001006');
        $boxB = $this->createChatBox($tenantB->user_id, 'AgentB', '15550002006');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $foreignResponse = $this->postJson(route('customer.chatbox.pin', $boxB->uid));
        $nonexistentResponse = $this->postJson(route('customer.chatbox.pin', 'nonexistent-' . uniqid()));

        $foreignResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent());
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $boxB->id)->where('pinned', true)->count());

        $ownResponse = $this->postJson(route('customer.chatbox.pin', $boxA->uid));
        $ownResponse->assertOk();
        $ownResponse->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $boxA->id)->where('pinned', true)->count());
    }

    /**
     * resolveOwnedChatBox() matches only the uid column. uid is a uniqid()
     * string, never the numeric primary key, and the resolver never falls
     * back to id — so the box's own numeric id must resolve exactly like a
     * wholly nonexistent identifier, even for its true owner. Proven
     * directly against each of the six single-record actions individually
     * (not inferred from the shared resolver's existence alone), since each
     * action has its own distinct denial shape.
     */
    public function test_numeric_primary_key_cannot_resolve_a_chatbox_for_any_of_the_six_actions(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $box = $this->createChatBox($tenantA->user_id, 'AgentA', '15550001007');
        $numericId = (string) $box->id;

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        // messages(): no explicit status is set on the denial branch — 200 with an error body.
        $messagesResponse = $this->postJson(route('customer.chatbox.messages', $numericId));
        $messagesResponse->assertOk();
        $messagesResponse->assertJson(['status' => 'error', 'data' => [], 'pinned' => 0]);

        // messagesWithNotification(): 404.
        $notificationResponse = $this->postJson(route('customer.chatbox.notification', $numericId));
        $notificationResponse->assertStatus(404);
        $notificationResponse->assertJson(['status' => 'error', 'message' => 'Chat box not found.']);

        // reply(): 404, denied before reaching any reservation/report/send side effect.
        $replyResponse = $this->postJson(route('customer.chatbox.reply', $numericId), ['message' => 'hi']);
        $replyResponse->assertStatus(404);
        $replyResponse->assertJson(['status' => 'error', 'message' => 'Chat box not found. Refresh page.']);
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
        $this->assertSame(0, DB::table('reports')->count());

        // delete(): 404, box remains present.
        $deleteResponse = $this->postJson(route('customer.chatbox.delete', $numericId));
        $deleteResponse->assertStatus(404);
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $box->id)->count());

        // block(): 404, no blacklist mutation.
        $blockResponse = $this->postJson(route('customer.chatbox.block', $numericId));
        $blockResponse->assertStatus(404);
        $this->assertSame(0, DB::table('blacklists')->where('number', $box->to)->count());

        // pin(): 404, pinned state unchanged.
        $pinResponse = $this->postJson(route('customer.chatbox.pin', $numericId));
        $pinResponse->assertStatus(404);
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $box->id)->where('pinned', true)->count());
    }

    // ===================================================================
    // B. Identifier consistency
    // ===================================================================

    public function test_pinned_and_ajax_chat_list_data_id_uses_uid_data_box_id_uses_numeric_id(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $pinnedBox = $this->createChatBox($tenantA->user_id, 'AgentA', '15550001008');
        $pinnedBox->update(['pinned' => true]);
        $unpinnedBox = $this->createChatBox($tenantA->user_id, 'AgentA2', '15550001009');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        // Pinned list — rendered inside index()'s _sidebar include.
        $indexResponse = $this->get(route('customer.chatbox.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee('data-id="' . $pinnedBox->uid . '"', false);
        $indexResponse->assertSee('data-box-id="' . $pinnedBox->id . '"', false);
        $indexResponse->assertDontSee('data-id="' . $pinnedBox->id . '"', false);

        // AJAX unpinned list — loadChatUsers() -> _chat_list.blade.php partial.
        $ajaxResponse = $this->get(route('customer.chatbox.load'));
        $ajaxResponse->assertOk();
        $ajaxResponse->assertSee('data-id="' . $unpinnedBox->uid . '"', false);
        $ajaxResponse->assertSee('data-box-id="' . $unpinnedBox->id . '"', false);
        $ajaxResponse->assertDontSee('data-id="' . $unpinnedBox->id . '"', false);
    }

    // ===================================================================
    // C. Permission — authorize() runs before resolveOwnedChatBox()
    // ===================================================================

    public function test_missing_chat_box_permission_denies_the_six_non_reply_actions(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $box = $this->createChatBox($tenantA->user_id, 'AgentA', '15550001010');

        // access_backend granted (the global routes/customer.php gate),
        // chat_box withheld — isolates the controller's own authorize() call.
        $this->authenticateAsCustomer($tenantA, []);

        // Plain (non-JSON) requests: the app's exception handler returns 200
        // with a JSON error body for requests that wantsJson(), so these
        // must be non-JSON requests to observe the real 401 status, exactly
        // like this repository's existing Security test suite (e.g.
        // ContactsSecurityTest's own missing-permission assertions).
        $this->post(route('customer.chatbox.messages', $box->uid))->assertStatus(401);
        $this->post(route('customer.chatbox.notification', $box->uid))->assertStatus(401);
        $this->post(route('customer.chatbox.delete', $box->uid))->assertStatus(401);
        $this->post(route('customer.chatbox.block', $box->uid))->assertStatus(401);
        $this->post(route('customer.chatbox.pin', $box->uid))->assertStatus(401);
        $this->get(route('customer.chatbox.load'))->assertStatus(401);

        $this->assertSame(1, DB::table('chat_boxes')->where('id', $box->id)->count());
        $this->assertSame(0, DB::table('blacklists')->where('number', $box->to)->count());
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $box->id)->where('pinned', true)->count());
    }

    public function test_reply_denies_actor_missing_permission_identically_regardless_of_uid_validity(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $foreignBox = $this->createChatBox($tenantB->user_id, 'AgentB', '15550002011');

        $this->authenticateAsCustomer($tenantA, []);

        // authorize('chat_box') runs before resolveOwnedChatBox(), so a
        // missing permission produces the identical denial whether the
        // supplied uid belongs to another tenant's real box or does not
        // exist at all — the resolver is never even reached. This is a
        // different case from an authorized actor's ownership 404 (proven
        // separately in section A) — the two are intentionally not the same.
        $foreignResponse = $this->post(route('customer.chatbox.reply', $foreignBox->uid), ['message' => 'hi']);
        $nonexistentResponse = $this->post(route('customer.chatbox.reply', 'nonexistent-' . uniqid()), ['message' => 'hi']);

        $foreignResponse->assertStatus(401);
        $nonexistentResponse->assertStatus(401);
        $this->assertSame($foreignResponse->getContent(), $nonexistentResponse->getContent());
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
    }

    // ===================================================================
    // D. block() Contacts tenant isolation
    // ===================================================================

    public function test_block_unsubscribes_only_the_blocking_tenants_own_contact_row(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $phone = '15550003001';
        $boxA = $this->createChatBox($tenantA->user_id, 'AgentA', $phone);

        $contactA = Contacts::create(['customer_id' => $tenantA->user_id, 'group_id' => null, 'phone' => $phone, 'status' => 'subscribe']);
        $contactB = Contacts::create(['customer_id' => $tenantB->user_id, 'group_id' => null, 'phone' => $phone, 'status' => 'subscribe']);

        // Deterministic, distinguishable updated_at so we can prove tenant
        // B's row is byte-for-byte untouched, not merely "still subscribed".
        DB::table('contacts')->where('id', $contactB->id)->update(['updated_at' => '2020-01-01 00:00:00']);
        $contactBUpdatedAtBefore = DB::table('contacts')->where('id', $contactB->id)->value('updated_at');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $response = $this->postJson(route('customer.chatbox.block', $boxA->uid));
        $response->assertOk();
        $response->assertJson(['status' => 'success']);

        $this->assertSame('unsubscribe', DB::table('contacts')->where('id', $contactA->id)->value('status'));
        $this->assertSame('subscribe', DB::table('contacts')->where('id', $contactB->id)->value('status'));
        $this->assertSame($contactBUpdatedAtBefore, DB::table('contacts')->where('id', $contactB->id)->value('updated_at'));

        $this->assertSame(1, DB::table('blacklists')->where('number', $phone)->where('user_id', $tenantA->user_id)->count());
        $this->assertSame(0, DB::table('blacklists')->where('number', $phone)->where('user_id', $tenantB->user_id)->count());
    }

    // ===================================================================
    // E. XSS / safe message source assertions
    // ===================================================================

    public function test_safe_message_paragraph_helper_and_its_three_call_sites(): void
    {
        $source = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(1, substr_count($source, 'function safeMessageParagraph(value) {'));
        $this->assertStringContainsString('return $("<p></p>").text(value);', $source);

        // Exactly 1 definition + 3 call sites = 4 occurrences of the name.
        $this->assertSame(4, substr_count($source, 'safeMessageParagraph('));

        // The helper's own body: text() only, never html(), no presence condition.
        $helperStart = strpos($source, 'function safeMessageParagraph(value) {');
        $helperEnd = strpos($source, 'function safeTypedMediaParagraph', $helperStart);
        $this->assertNotFalse($helperStart);
        $this->assertNotFalse($helperEnd);
        $helperBody = substr($source, $helperStart, $helperEnd - $helperStart);
        $this->assertStringNotContainsString('if (', $helperBody);
        $this->assertStringNotContainsString('.html(', $helperBody);

        // History: presence-gated — a falsy history message renders no <p>.
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.message\)\s*\{\s*\$content\.append\(safeMessageParagraph\(sms\.message\)\);/',
            $source
        );

        // Optimistic: unconditional — always renders one <p>, no guard.
        $this->assertStringContainsString('$content.append(safeMessageParagraph(messageValue));', $source);
        $this->assertStringNotContainsString('if (messageValue)', $source);

        // Echo: strict !== null — an empty string still renders an empty <p>.
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.message\s*!==\s*null\)\s*\{\s*\$content\.append\(safeMessageParagraph\(sms\.message\)\);/',
            $source
        );

        // No leftover unsafe raw interpolation of message text into markup.
        $this->assertStringNotContainsString('${sms.message}', $source);
        $this->assertStringNotContainsString('"<p>" + messageValue + "</p>"', $source);
    }

    /**
     * The above assertions read the raw .blade.php source directly.
     * This test instead renders customer.chatbox.index over a real
     * authenticated HTTP request and inspects the actual response body, so
     * the safe-rendering seam is proven to survive real Blade compilation,
     * not just exist in the source file. PHPUnit still cannot execute the
     * client-side JavaScript this HTML ships — this remains a source-shape
     * assertion against rendered output, not a browser-behavior proof.
     */
    public function test_rendered_index_response_contains_the_safe_seam_and_not_the_original_unsafe_patterns(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $response = $this->get(route('customer.chatbox.index'));
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('function safeMessageParagraph(value)', $html);
        $this->assertStringContainsString('function safeTypedMediaParagraph(url, imgAlt)', $html);
        $this->assertStringContainsString('$content.append(safeMessageParagraph(sms.message));', $html);
        $this->assertStringContainsString('$content.append(safeMessageParagraph(messageValue));', $html);
        $this->assertStringContainsString('$content.append(safeTypedMediaParagraph(sms.media_url, "media"));', $html);

        $this->assertStringNotContainsString('${sms.message}', $html);
        $this->assertStringNotContainsString('${sms.media_url}', $html);
        $this->assertStringNotContainsString('"<p>" + messageValue + "</p>"', $html);
    }

    // ===================================================================
    // F. Media source assertions
    // ===================================================================

    public function test_safe_typed_media_paragraph_helper_and_its_call_sites(): void
    {
        $source = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(1, substr_count($source, 'function safeTypedMediaParagraph(url, imgAlt) {'));

        // Exactly 1 definition + 2 call sites (history + Echo) = 3 occurrences.
        $this->assertSame(3, substr_count($source, 'safeTypedMediaParagraph('));

        $helperStart = strpos($source, 'function safeTypedMediaParagraph(url, imgAlt) {');
        $helperEnd = strpos($source, '// RFC-005 Milestone 5', $helperStart);
        $this->assertNotFalse($helperStart);
        $this->assertNotFalse($helperEnd);
        $helperBody = substr($source, $helperStart, $helperEnd - $helperStart);
        $this->assertStringContainsString('isImageOrVideo(url)', $helperBody);
        $this->assertStringContainsString('.attr("src", url)', $helperBody);
        $this->assertStringNotContainsString('if (url', $helperBody);
        $this->assertStringNotContainsString('if (!url', $helperBody);

        // History: imgAlt='media', still gated on !== null.
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.media_url\s*!==\s*null\)\s*\{\s*\$content\.append\(safeTypedMediaParagraph\(sms\.media_url,\s*"media"\)\);/',
            $source
        );

        // Echo: imgAlt='', still gated on !== null.
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.media_url\s*!==\s*null\)\s*\{\s*\$content\.append\(safeTypedMediaParagraph\(sms\.media_url,\s*""\)\);/',
            $source
        );

        // Optimistic media: separate, safe, image-only construction.
        $this->assertStringContainsString('if (response.media_url) {', $source);
        $this->assertStringContainsString('.attr("src", response.media_url)', $source);
        $this->assertStringContainsString('.attr("alt", "media")', $source);
        $this->assertStringContainsString('.attr("style", "max-width:200px; max-height:200px;")', $source);
        $this->assertSame(1, substr_count($source, 'max-width:200px; max-height:200px;'));

        // The optimistic block must not use the typed helper or isImageOrVideo().
        $optimisticStart = strpos($source, 'if (response.media_url) {');
        $optimisticEnd = strpos($source, 'chatHistory.append($chat);', $optimisticStart);
        $this->assertNotFalse($optimisticStart);
        $this->assertNotFalse($optimisticEnd);
        $optimisticBlock = substr($source, $optimisticStart, $optimisticEnd - $optimisticStart);
        $this->assertStringNotContainsString('safeTypedMediaParagraph', $optimisticBlock);
        $this->assertStringNotContainsString('isImageOrVideo', $optimisticBlock);

        // No leftover unsafe raw interpolation of a media URL into markup.
        $this->assertStringNotContainsString('${sms.media_url}', $source);
    }

    // ===================================================================
    // G. Structure/order preservation
    // ===================================================================

    public function test_structure_and_order_are_preserved_per_rendering_path(): void
    {
        $source = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        // History load & optimistic send share the identical small (36x36)
        // box-shadow avatar with a static profile image — exactly twice.
        $this->assertSame(2, substr_count($source, 'avatar box-shadow-1 cursor-pointer'));
        $this->assertSame(2, substr_count($source, 'height="36" width="36"'));

        // Echo listener uses the larger (40x40) avatar wrapper — exactly
        // twice (incoming + outgoing branches).
        $this->assertSame(2, substr_count($source, 'class="avatar m-0" href="#"'));
        $this->assertSame(2, substr_count($source, 'height="40" width="40"'));
        $this->assertStringContainsString("route('user.avatar', Auth::user()->uid)", $source);

        $this->assertSame(2, substr_count($source, 'chat-time'));
        $this->assertStringContainsString('$counter.html(response.notification);', $source);
        $this->assertStringContainsString('$counter.removeAttr("hidden");', $source);

        // Both broadcasting guards remain, unmodified, exactly twice each.
        $this->assertSame(2, substr_count($source, "@if(config('broadcasting.connections.pusher.app_id'))"));
        $this->assertSame(2, substr_count($source, '@endif'));

        // ---- HISTORY region: the cwData.forEach(...) loop body ----
        $historyStart = strpos($source, 'cwData.forEach((sms) => {');
        $historyEnd = strpos($source, 'chatContainer.animate({ scrollTop: chatContainer[0].scrollHeight }, 400);', $historyStart);
        $this->assertNotFalse($historyStart, 'History loop start anchor not found.');
        $this->assertNotFalse($historyEnd, 'History loop end anchor not found.');
        $historyRegion = substr($source, $historyStart, $historyEnd - $historyStart);

        $historyMediaPos = strpos($historyRegion, 'safeTypedMediaParagraph(');
        $historyMessagePos = strpos($historyRegion, 'safeMessageParagraph(');
        $historyTimePos = strpos($historyRegion, 'chat-time');
        $this->assertNotFalse($historyMediaPos);
        $this->assertNotFalse($historyMessagePos);
        $this->assertNotFalse($historyTimePos);
        $this->assertTrue($historyMediaPos < $historyMessagePos, 'History: media must be appended before the message.');
        $this->assertTrue($historyMessagePos < $historyTimePos, 'History: message must be appended before chat-time.');
        $this->assertStringContainsString('if (sms.media_url !== null)', $historyRegion);
        $this->assertStringContainsString('if (sms.message)', $historyRegion);

        // ---- OPTIMISTIC region: enter_chat()'s success-branch bubble build ----
        $optimisticStart = strpos($source, 'let chatHistory = $(".chat_history");');
        $optimisticEnd = strpos($source, 'message.val("");', $optimisticStart);
        $this->assertNotFalse($optimisticStart, 'Optimistic block start anchor not found.');
        $this->assertNotFalse($optimisticEnd, 'Optimistic block end anchor not found.');
        $optimisticRegion = substr($source, $optimisticStart, $optimisticEnd - $optimisticStart);

        $optimisticMessagePos = strpos($optimisticRegion, 'safeMessageParagraph(messageValue)');
        $optimisticMediaPos = strpos($optimisticRegion, '.attr("src", response.media_url)');
        $this->assertNotFalse($optimisticMessagePos);
        $this->assertNotFalse($optimisticMediaPos);
        $this->assertTrue($optimisticMessagePos < $optimisticMediaPos, 'Optimistic: message must be appended before the optional media.');
        $this->assertStringNotContainsString('chat-time', $optimisticRegion);
        $this->assertStringContainsString('if (response.media_url) {', $optimisticRegion);
        $this->assertStringNotContainsString('safeTypedMediaParagraph', $optimisticRegion);
        $this->assertStringNotContainsString('isImageOrVideo', $optimisticRegion);

        // ---- ECHO region: the MessageReceived listener's per-message bubble build ----
        $echoStart = strpos($source, 'const sms = response.data;');
        $echoEnd = strpos($source, '@endif', $echoStart);
        $this->assertNotFalse($echoStart, 'Echo block start anchor not found.');
        $this->assertNotFalse($echoEnd, 'Echo block end anchor not found.');
        $echoRegion = substr($source, $echoStart, $echoEnd - $echoStart);

        $echoMediaPos = strpos($echoRegion, 'safeTypedMediaParagraph(');
        $echoMessagePos = strpos($echoRegion, 'safeMessageParagraph(');
        $echoTimePos = strpos($echoRegion, 'chat-time');
        $echoActiveChatPos = strpos($echoRegion, 'if (chat_id === activeChatID)');
        $this->assertNotFalse($echoMediaPos);
        $this->assertNotFalse($echoMessagePos);
        $this->assertNotFalse($echoTimePos);
        $this->assertNotFalse($echoActiveChatPos);
        $this->assertTrue($echoMediaPos < $echoMessagePos, 'Echo: media must be appended before the message.');
        $this->assertTrue($echoMessagePos < $echoTimePos, 'Echo: message must be appended before chat-time.');
        $this->assertTrue($echoTimePos < $echoActiveChatPos, 'Echo: the activeChatID branch must run only after the bubble is fully constructed.');
        $this->assertStringContainsString('if (sms.media_url !== null)', $echoRegion);
        $this->assertStringContainsString('if (sms.message !== null)', $echoRegion);
        $this->assertStringContainsString('sms.direction === "incoming"', $echoRegion);
    }

    // ===================================================================
    // H. RFC-005 reply preservation
    // ===================================================================

    public function test_reply_foreign_chatbox_with_valid_idempotency_token_denied_before_any_billing_side_effect(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $foreignBox = $this->createChatBox($tenantB->user_id, 'AgentB', '15550005001');

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $response = $this->postJson(route('customer.chatbox.reply', $foreignBox->uid), [
            'message' => 'A real message with a valid token.',
            'idempotency_token' => (string) Str::uuid(),
        ]);

        $response->assertStatus(404);
        $response->assertJson(['status' => 'error', 'message' => 'Chat box not found. Refresh page.']);

        $this->assertSame(0, DB::table('business_usage_reservations')->count());
        $this->assertSame(0, DB::table('reports')->count());
        $this->assertSame(0, ChatBoxMessage::where('box_id', $foreignBox->id)->count());
    }

    // ===================================================================
    // I. messages() raw serialization preservation
    // ===================================================================

    public function test_messages_preserves_raw_db_serialization_and_ascending_order(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $box = $this->createChatBox($tenantA->user_id, 'AgentA', '15550004001');

        $firstCreatedAt = '2024-01-01 10:00:00';
        $secondCreatedAt = '2024-01-01 10:05:00';

        // Inserted out of chronological order to prove the ORDER BY
        // created_at asc clause, not insertion order, drives the response.
        DB::table('chat_box_messages')->insert([
            [
                'box_id' => $box->id, 'message' => 'Second chronologically', 'media_url' => null,
                'sms_type' => 'sms', 'send_by' => 'to', 'direction' => 'incoming',
                'created_at' => $secondCreatedAt, 'updated_at' => $secondCreatedAt,
            ],
            [
                'box_id' => $box->id, 'message' => 'First chronologically', 'media_url' => null,
                'sms_type' => 'sms', 'send_by' => 'from', 'direction' => 'outgoing',
                'created_at' => $firstCreatedAt, 'updated_at' => $firstCreatedAt,
            ],
        ]);

        $this->authenticateAsCustomer($tenantA, ['chat_box']);

        $response = $this->postJson(route('customer.chatbox.messages', $box->uid));
        $response->assertOk();

        $data = $response->json();
        $this->assertSame('success', $data['status']);
        $this->assertCount(2, $data['data']);
        $this->assertArrayHasKey('pinned', $data);

        // Ascending order by created_at, not insertion order.
        $this->assertSame($firstCreatedAt, $data['data'][0]['created_at']);
        $this->assertSame($secondCreatedAt, $data['data'][1]['created_at']);
        $this->assertSame('First chronologically', $data['data'][0]['message']);
        $this->assertSame('Second chronologically', $data['data'][1]['message']);

        // Raw DB query-builder datetime string ("Y-m-d H:i:s"), not an
        // Eloquent/Carbon ISO-8601 representation — the exact defect the
        // post-round exception preserved by keeping the raw \DB::table()
        // retrieval instead of switching to ChatBoxMessage::where(...).
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['data'][0]['created_at']);

        // Raw stdClass-from-query-builder rows expose every physical
        // column, unlike a ->select(...)-limited Eloquent read — assert the
        // complete raw chat_box_messages row shape.
        $this->assertArrayHasKey('id', $data['data'][0]);
        $this->assertArrayHasKey('box_id', $data['data'][0]);
        $this->assertArrayHasKey('message', $data['data'][0]);
        $this->assertArrayHasKey('media_url', $data['data'][0]);
        $this->assertArrayHasKey('sms_type', $data['data'][0]);
        $this->assertArrayHasKey('direction', $data['data'][0]);
        $this->assertArrayHasKey('sending_server_id', $data['data'][0]);
        $this->assertArrayHasKey('send_by', $data['data'][0]);
        $this->assertArrayHasKey('created_at', $data['data'][0]);
        $this->assertArrayHasKey('updated_at', $data['data'][0]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Customer}
     */
    private function twoTenantCustomers(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $tenantA = $this->createCustomer();
        $tenantB = $this->createCustomer();

        return [$tenantA, $tenantB];
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function createChatBox(int $userId, string $from, string $to, array $overrides = []): ChatBox
    {
        return ChatBox::create(array_merge([
            'user_id' => $userId,
            'from' => $from,
            'to' => $to,
            'reply_by_customer' => true,
        ], $overrides));
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
