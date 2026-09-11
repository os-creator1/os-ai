<?php

namespace Tests\Feature\Security;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Http\Controllers\Customer\DLRController;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\Currency;
use App\Models\CustomerBasedPricingPlan;
use App\Models\CustomerBasedSendingServer;
use App\Models\PhoneNumbers;
use App\Models\Plan;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Subscription;
use App\Models\Templates;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Design System M2 Slice 6 — ChatBox Security Remediation Contract, extended
 * by Customer Experience Redesign Slice 2B with the Business dimension.
 *
 * Slice 6 proved the inbox safe per LOGIN: canonical-uid resolution for all
 * six single-record actions, authorize-before-resolve, block()'s Contacts
 * isolation, safe message/media rendering, and RFC-005 preservation.
 *
 * Slice 2B moves the inbox from `Customer → Conversations` to `Account →
 * selected Business → Conversations`, so every one of those properties is
 * re-proven here against the Business-scoped route family, and the Business
 * dimension is added on top: Business A never reaches Business B, a
 * NULL-business legacy conversation is reachable from no Business route, and
 * the view-as boundary holds.
 *
 * One deliberate change of expectation, stated rather than hidden: Slice 6's
 * per-action denial shapes (messages() answered 200 with an error body, the
 * others 404 with their own message text) are superseded by 2B §9 — a
 * tenancy failure at ANY link is the same 404. The property those tests
 * protected is unchanged and still asserted: a foreign conversation and a
 * nonexistent one are indistinguishable, and neither has a side effect.
 *
 * Sections E, F and G assert facts about client-side JavaScript source text.
 * PHPUnit cannot execute that JavaScript — they are honest source-level
 * assertions. Every other section is a real HTTP/model assertion.
 */
class ChatBoxSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

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
    // A. Ownership — all six single-record actions, now Business-scoped
    // ===================================================================

    public function test_messages_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();
        $boxA = $this->box($businessA, '15550001001', '15550009001');
        $boxB = $this->box($businessB, '15550002001', '15550009002');

        $this->authenticateAs($customerA, ['chat_box']);

        $own = $this->postJson($this->conversationUrl('messages', $workspaceA, $businessA, $boxA->uid));
        $own->assertOk()->assertJson(['status' => 'success']);

        $foreign = $this->postJson($this->conversationUrl('messages', $workspaceA, $businessA, $boxB->uid));
        $nonexistent = $this->postJson($this->conversationUrl('messages', $workspaceA, $businessA, 'nonexistent-' . uniqid()));

        $foreign->assertNotFound();
        $nonexistent->assertNotFound();
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
    }

    public function test_messages_with_notification_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();
        $boxA = $this->box($businessA, '15550001002', '15550009003');
        ChatBoxMessage::create(['box_id' => $boxA->id, 'message' => 'Hi', 'direction' => 'incoming']);
        $boxB = $this->box($businessB, '15550002002', '15550009004');

        $this->authenticateAs($customerA, ['chat_box']);

        $this->postJson($this->conversationUrl('notification', $workspaceA, $businessA, $boxA->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $foreign = $this->postJson($this->conversationUrl('notification', $workspaceA, $businessA, $boxB->uid));
        $nonexistent = $this->postJson($this->conversationUrl('notification', $workspaceA, $businessA, 'nonexistent-' . uniqid()));

        $foreign->assertNotFound();
        $nonexistent->assertNotFound();
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
    }

    public function test_reply_foreign_and_nonexistent_denied_identically_before_reaching_any_business_logic(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();
        $boxB = $this->box($businessB, '15550002003', '15550009005');

        $this->authenticateAs($customerA, ['chat_box']);

        $foreign = $this->postJson($this->conversationUrl('reply', $workspaceA, $businessA, $boxB->uid), ['message' => 'hi']);
        $nonexistent = $this->postJson($this->conversationUrl('reply', $workspaceA, $businessA, 'nonexistent-' . uniqid()), ['message' => 'hi']);

        $foreign->assertNotFound();
        $nonexistent->assertNotFound();
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());

        // Denied before the idempotency-token check, the spam check, or
        // quickSend()/billing.
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
    }

    public function test_delete_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();
        $boxA = $this->box($businessA, '15550001004', '15550009006');
        ChatBoxMessage::create(['box_id' => $boxA->id, 'message' => 'Hi', 'direction' => 'incoming']);
        $boxB = $this->box($businessB, '15550002004', '15550009007');

        $this->authenticateAs($customerA, ['chat_box']);

        $foreign = $this->postJson($this->conversationUrl('delete', $workspaceA, $businessA, $boxB->uid));
        $nonexistent = $this->postJson($this->conversationUrl('delete', $workspaceA, $businessA, 'nonexistent-' . uniqid()));

        $foreign->assertNotFound();
        $nonexistent->assertNotFound();
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $boxB->id)->count());

        $this->postJson($this->conversationUrl('delete', $workspaceA, $businessA, $boxA->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $boxA->id)->count());
    }

    public function test_block_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();
        $boxA = $this->box($businessA, '15550001005', '15550009008');
        $boxB = $this->box($businessB, '15550002005', '15550009009');

        $this->authenticateAs($customerA, ['chat_box']);

        $foreign = $this->postJson($this->conversationUrl('block', $workspaceA, $businessA, $boxB->uid));
        $nonexistent = $this->postJson($this->conversationUrl('block', $workspaceA, $businessA, 'nonexistent-' . uniqid()));

        $foreign->assertNotFound();
        $nonexistent->assertNotFound();
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
        $this->assertSame(0, DB::table('blacklists')->where('number', $boxB->to)->count());

        $this->postJson($this->conversationUrl('block', $workspaceA, $businessA, $boxA->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertSame(1, DB::table('blacklists')
            ->where('number', $boxA->to)
            ->where('business_id', $businessA->id)
            ->where('user_id', $businessA->customer_id)
            ->count());
    }

    public function test_pin_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();
        $boxA = $this->box($businessA, '15550001006', '15550009010');
        $boxB = $this->box($businessB, '15550002006', '15550009011');

        $this->authenticateAs($customerA, ['chat_box']);

        $foreign = $this->postJson($this->conversationUrl('pin', $workspaceA, $businessA, $boxB->uid));
        $nonexistent = $this->postJson($this->conversationUrl('pin', $workspaceA, $businessA, 'nonexistent-' . uniqid()));

        $foreign->assertNotFound();
        $nonexistent->assertNotFound();
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $boxB->id)->where('pinned', true)->count());

        $this->postJson($this->conversationUrl('pin', $workspaceA, $businessA, $boxA->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $boxA->id)->where('pinned', true)->count());
    }

    /**
     * The conversation is resolved by `uid` only. A numeric primary key must
     * resolve exactly like a nonexistent identifier — even for the
     * conversation's true owner, in its own Business — for every action.
     */
    public function test_numeric_primary_key_cannot_resolve_a_chatbox_for_any_of_the_six_actions(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $box = $this->box($businessA, '15550001007', '15550009012');
        $numericId = (string) $box->id;

        $this->authenticateAs($customerA, ['chat_box']);

        foreach (['messages', 'notification', 'delete', 'block', 'pin'] as $action) {
            $this->postJson($this->conversationUrl($action, $workspaceA, $businessA, $numericId))
                ->assertNotFound();
        }

        $this->postJson($this->conversationUrl('reply', $workspaceA, $businessA, $numericId), ['message' => 'hi'])
            ->assertNotFound();

        $this->assertSame(1, DB::table('chat_boxes')->where('id', $box->id)->count());
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $box->id)->where('pinned', true)->count());
        $this->assertSame(0, DB::table('blacklists')->where('number', $box->to)->count());
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
        $this->assertSame(0, DB::table('reports')->count());
    }

    // ===================================================================
    // B. Identifier consistency
    // ===================================================================

    public function test_pinned_and_ajax_chat_list_data_id_uses_uid_data_box_id_uses_numeric_id(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $pinnedBox = $this->box($businessA, '15550001008', '15550009013', ['pinned' => true]);
        $unpinnedBox = $this->box($businessA, '15550001009', '15550009014');

        $this->authenticateAs($customerA, ['chat_box']);

        $index = $this->get($this->conversationUrl('index', $workspaceA, $businessA));
        $index->assertOk();
        $index->assertSee('data-id="' . $pinnedBox->uid . '"', false);
        $index->assertSee('data-box-id="' . $pinnedBox->id . '"', false);
        $index->assertDontSee('data-id="' . $pinnedBox->id . '"', false);

        $ajax = $this->post($this->conversationUrl('load', $workspaceA, $businessA));
        $ajax->assertOk();
        $ajax->assertSee('data-id="' . $unpinnedBox->uid . '"', false);
        $ajax->assertSee('data-box-id="' . $unpinnedBox->id . '"', false);
        $ajax->assertDontSee('data-id="' . $unpinnedBox->id . '"', false);
    }

    // ===================================================================
    // C. Permission — chat_box is checked before any conversation lookup
    // ===================================================================

    public function test_missing_chat_box_permission_denies_the_six_non_reply_actions(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $box = $this->box($businessA, '15550001010', '15550009015');

        // access_backend granted, chat_box withheld — isolates the
        // controller's own authorize() call. Non-JSON requests, so the real
        // 401 status is observable (the app renders JSON errors as 200).
        $this->authenticateAs($customerA, []);

        foreach (['messages', 'notification', 'delete', 'block', 'pin'] as $action) {
            $this->post($this->conversationUrl($action, $workspaceA, $businessA, $box->uid))->assertStatus(401);
        }

        $this->post($this->conversationUrl('load', $workspaceA, $businessA))->assertStatus(401);

        $this->assertSame(1, DB::table('chat_boxes')->where('id', $box->id)->count());
        $this->assertSame(0, DB::table('blacklists')->where('number', $box->to)->count());
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $box->id)->where('pinned', true)->count());
    }

    /**
     * authorize('chat_box') runs before the conversation is resolved, so a
     * missing permission is the identical denial whether the uid names a real
     * conversation or none at all — the resolver is never reached.
     */
    public function test_reply_denies_actor_missing_permission_identically_regardless_of_uid_validity(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $realBox = $this->box($businessA, '15550001011', '15550009016');

        $this->authenticateAs($customerA, []);

        $real = $this->post($this->conversationUrl('reply', $workspaceA, $businessA, $realBox->uid), ['message' => 'hi']);
        $nonexistent = $this->post($this->conversationUrl('reply', $workspaceA, $businessA, 'nonexistent-' . uniqid()), ['message' => 'hi']);

        $real->assertStatus(401);
        $nonexistent->assertStatus(401);
        $this->assertSame($real->getContent(), $nonexistent->getContent());
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
    }

    // ===================================================================
    // D. block() — same Business only
    // ===================================================================

    public function test_block_unsubscribes_only_the_blocking_businesss_own_contact_rows(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();
        $phone = '15550003001';
        $boxA = $this->box($businessA, '15550001012', $phone);

        $contactA = $this->contact($businessA, $phone);
        $contactB = $this->contact($businessB, $phone);

        // A distinguishable updated_at proves Business B's row is untouched
        // byte for byte, not merely "still subscribed".
        DB::table('contacts')->where('id', $contactB->id)->update(['updated_at' => '2020-01-01 00:00:00']);
        $contactBBefore = DB::table('contacts')->where('id', $contactB->id)->value('updated_at');

        $this->authenticateAs($customerA, ['chat_box']);

        $this->postJson($this->conversationUrl('block', $workspaceA, $businessA, $boxA->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertSame('unsubscribe', DB::table('contacts')->where('id', $contactA->id)->value('status'));
        $this->assertSame('subscribe', DB::table('contacts')->where('id', $contactB->id)->value('status'));
        $this->assertSame($contactBBefore, DB::table('contacts')->where('id', $contactB->id)->value('updated_at'));

        $this->assertSame(1, DB::table('blacklists')->where('number', $phone)->where('business_id', $businessA->id)->count());
        $this->assertSame(0, DB::table('blacklists')->where('number', $phone)->where('business_id', $businessB->id)->count());
    }

    // ===================================================================
    // E. XSS / safe message source assertions (unchanged by 2B)
    // ===================================================================

    public function test_safe_message_paragraph_helper_and_its_three_call_sites(): void
    {
        $source = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(1, substr_count($source, 'function safeMessageParagraph(value) {'));
        $this->assertStringContainsString('return $("<p></p>").text(value);', $source);

        // Exactly 1 definition + 3 call sites = 4 occurrences of the name.
        $this->assertSame(4, substr_count($source, 'safeMessageParagraph('));

        $helperStart = strpos($source, 'function safeMessageParagraph(value) {');
        $helperEnd = strpos($source, 'function safeTypedMediaParagraph', $helperStart);
        $this->assertNotFalse($helperStart);
        $this->assertNotFalse($helperEnd);
        $helperBody = substr($source, $helperStart, $helperEnd - $helperStart);
        $this->assertStringNotContainsString('if (', $helperBody);
        $this->assertStringNotContainsString('.html(', $helperBody);

        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.message\)\s*\{\s*\$content\.append\(safeMessageParagraph\(sms\.message\)\);/',
            $source
        );

        $this->assertStringContainsString('$content.append(safeMessageParagraph(messageValue));', $source);
        $this->assertStringNotContainsString('if (messageValue)', $source);

        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.message\s*!==\s*null\)\s*\{\s*\$content\.append\(safeMessageParagraph\(sms\.message\)\);/',
            $source
        );

        $this->assertStringNotContainsString('${sms.message}', $source);
        $this->assertStringNotContainsString('"<p>" + messageValue + "</p>"', $source);
    }

    /**
     * Renders the real, Business-scoped inbox over an authenticated request,
     * so the safe-rendering seam is proven to survive Blade compilation.
     */
    public function test_rendered_index_response_contains_the_safe_seam_and_not_the_original_unsafe_patterns(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $this->authenticateAs($customerA, ['chat_box']);

        $response = $this->get($this->conversationUrl('index', $workspaceA, $businessA));
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
    // F. Media source assertions (unchanged by 2B)
    // ===================================================================

    public function test_safe_typed_media_paragraph_helper_and_its_call_sites(): void
    {
        $source = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(1, substr_count($source, 'function safeTypedMediaParagraph(url, imgAlt) {'));
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

        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.media_url\s*!==\s*null\)\s*\{\s*\$content\.append\(safeTypedMediaParagraph\(sms\.media_url,\s*"media"\)\);/',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(sms\.media_url\s*!==\s*null\)\s*\{\s*\$content\.append\(safeTypedMediaParagraph\(sms\.media_url,\s*""\)\);/',
            $source
        );

        $this->assertStringContainsString('if (response.media_url) {', $source);
        $this->assertStringContainsString('.attr("src", response.media_url)', $source);
        $this->assertStringContainsString('.attr("alt", "media")', $source);
        $this->assertStringContainsString('.attr("style", "max-width:200px; max-height:200px;")', $source);
        $this->assertSame(1, substr_count($source, 'max-width:200px; max-height:200px;'));

        $optimisticStart = strpos($source, 'if (response.media_url) {');
        $optimisticEnd = strpos($source, 'chatHistory.append($chat);', $optimisticStart);
        $this->assertNotFalse($optimisticStart);
        $this->assertNotFalse($optimisticEnd);
        $optimisticBlock = substr($source, $optimisticStart, $optimisticEnd - $optimisticStart);
        $this->assertStringNotContainsString('safeTypedMediaParagraph', $optimisticBlock);
        $this->assertStringNotContainsString('isImageOrVideo', $optimisticBlock);

        $this->assertStringNotContainsString('${sms.media_url}', $source);
    }

    // ===================================================================
    // G. Structure/order preservation (unchanged by 2B)
    // ===================================================================

    public function test_structure_and_order_are_preserved_per_rendering_path(): void
    {
        $source = file_get_contents(base_path('resources/views/customer/ChatBox/index.blade.php'));

        $this->assertSame(2, substr_count($source, 'avatar box-shadow-1 cursor-pointer'));
        $this->assertSame(2, substr_count($source, 'height="36" width="36"'));
        $this->assertSame(2, substr_count($source, 'class="avatar m-0" href="#"'));
        $this->assertSame(2, substr_count($source, 'height="40" width="40"'));
        $this->assertStringContainsString("route('user.avatar', Auth::user()->uid)", $source);

        $this->assertSame(2, substr_count($source, 'chat-time'));
        $this->assertStringContainsString('$counter.html(response.notification);', $source);
        $this->assertStringContainsString('$counter.removeAttr("hidden");', $source);

        $this->assertSame(2, substr_count($source, "@if(config('broadcasting.connections.pusher.app_id'))"));
        $this->assertSame(2, substr_count($source, '@endif'));

        $historyStart = strpos($source, 'cwData.forEach((sms) => {');
        $historyEnd = strpos($source, 'chatContainer.animate({ scrollTop: chatContainer[0].scrollHeight }, 400);', $historyStart);
        $this->assertNotFalse($historyStart, 'History loop start anchor not found.');
        $this->assertNotFalse($historyEnd, 'History loop end anchor not found.');
        $historyRegion = substr($source, $historyStart, $historyEnd - $historyStart);

        $this->assertTrue(strpos($historyRegion, 'safeTypedMediaParagraph(') < strpos($historyRegion, 'safeMessageParagraph('));
        $this->assertTrue(strpos($historyRegion, 'safeMessageParagraph(') < strpos($historyRegion, 'chat-time'));
        $this->assertStringContainsString('if (sms.media_url !== null)', $historyRegion);
        $this->assertStringContainsString('if (sms.message)', $historyRegion);

        $optimisticStart = strpos($source, 'let chatHistory = $(".chat_history");');
        $optimisticEnd = strpos($source, 'message.val("");', $optimisticStart);
        $this->assertNotFalse($optimisticStart, 'Optimistic block start anchor not found.');
        $this->assertNotFalse($optimisticEnd, 'Optimistic block end anchor not found.');
        $optimisticRegion = substr($source, $optimisticStart, $optimisticEnd - $optimisticStart);

        $this->assertTrue(strpos($optimisticRegion, 'safeMessageParagraph(messageValue)') < strpos($optimisticRegion, '.attr("src", response.media_url)'));
        $this->assertStringNotContainsString('chat-time', $optimisticRegion);
        $this->assertStringContainsString('if (response.media_url) {', $optimisticRegion);
        $this->assertStringNotContainsString('safeTypedMediaParagraph', $optimisticRegion);
        $this->assertStringNotContainsString('isImageOrVideo', $optimisticRegion);

        $echoStart = strpos($source, 'const sms = response.data;');
        $echoEnd = strpos($source, '@endif', $echoStart);
        $this->assertNotFalse($echoStart, 'Echo block start anchor not found.');
        $this->assertNotFalse($echoEnd, 'Echo block end anchor not found.');
        $echoRegion = substr($source, $echoStart, $echoEnd - $echoStart);

        $this->assertTrue(strpos($echoRegion, 'safeTypedMediaParagraph(') < strpos($echoRegion, 'safeMessageParagraph('));
        $this->assertTrue(strpos($echoRegion, 'safeMessageParagraph(') < strpos($echoRegion, 'chat-time'));
        $this->assertTrue(strpos($echoRegion, 'chat-time') < strpos($echoRegion, 'if (chat_id === activeChatID)'));
        $this->assertStringContainsString('if (sms.media_url !== null)', $echoRegion);
        $this->assertStringContainsString('if (sms.message !== null)', $echoRegion);
        $this->assertStringContainsString('sms.direction === "incoming"', $echoRegion);
    }

    // ===================================================================
    // H. RFC-005 reply preservation
    // ===================================================================

    public function test_reply_foreign_chatbox_with_valid_idempotency_token_denied_before_any_billing_side_effect(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();
        $foreignBox = $this->box($businessB, '15550002005', '15550005001');

        $this->authenticateAs($customerA, ['chat_box']);

        $this->postJson($this->conversationUrl('reply', $workspaceA, $businessA, $foreignBox->uid), [
            'message' => 'A real message with a valid token.',
            'idempotency_token' => (string) Str::uuid(),
        ])->assertNotFound();

        $this->assertSame(0, DB::table('business_usage_reservations')->count());
        $this->assertSame(0, DB::table('reports')->count());
        $this->assertSame(0, ChatBoxMessage::where('box_id', $foreignBox->id)->count());
    }

    // ===================================================================
    // I. messages() raw serialization preservation
    // ===================================================================

    public function test_messages_preserves_raw_db_serialization_and_ascending_order(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $box = $this->box($businessA, '15550001013', '15550004001');

        $firstCreatedAt = '2024-01-01 10:00:00';
        $secondCreatedAt = '2024-01-01 10:05:00';

        // Out of chronological order, to prove ORDER BY created_at drives it.
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

        $this->authenticateAs($customerA, ['chat_box']);

        $data = $this->postJson($this->conversationUrl('messages', $workspaceA, $businessA, $box->uid))
            ->assertOk()
            ->json();

        $this->assertSame('success', $data['status']);
        $this->assertCount(2, $data['data']);
        $this->assertArrayHasKey('pinned', $data);
        $this->assertSame($firstCreatedAt, $data['data'][0]['created_at']);
        $this->assertSame($secondCreatedAt, $data['data'][1]['created_at']);

        // Raw query-builder "Y-m-d H:i:s", not a Carbon ISO-8601 string.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['data'][0]['created_at']);

        foreach (['id', 'box_id', 'message', 'media_url', 'sms_type', 'direction', 'sending_server_id', 'send_by', 'created_at', 'updated_at'] as $column) {
            $this->assertArrayHasKey($column, $data['data'][0]);
        }
    }

    // ===================================================================
    // J. Slice 2B tenancy — every role, and every way out of the Business
    // ===================================================================

    // Every role that may work a Business's inbox runs EVERY action in it —
    // index, load, messages, notification, pin, reply, block, delete — not
    // a sample. assertEveryActionWorks() is the single definition.

    public function test_the_owner_can_run_every_conversation_action(): void
    {
        $fx = $this->sendableBusiness();
        $this->authenticateAs($fx['customer'], ['chat_box']);

        $this->assertEveryActionWorks($fx);
    }

    public function test_a_workspace_admin_can_work_the_business_inbox(): void
    {
        $fx = $this->sendableBusiness();

        $admin = $this->createCustomer();
        $this->member($fx['workspace'], $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($admin, ['chat_box']);

        $this->assertEveryActionWorks($fx);
    }

    public function test_selected_staff_can_run_every_conversation_action_in_their_business(): void
    {
        $fx = $this->sendableBusiness();

        $staff = $this->createCustomer();
        $membership = $this->member($fx['workspace'], $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $fx['business']);
        $this->authenticateAs($staff, ['chat_box']);

        $this->assertEveryActionWorks($fx);
    }

    /**
     * While viewing, every action works inside the viewed Business except
     * delete: the view-as contract prohibits deleting data, and its route
     * classifier refuses any non-GET `.delete` route — this one included.
     */
    public function test_view_as_can_run_every_non_deleting_action_in_the_viewed_business(): void
    {
        $fx = $this->sendableBusiness('14155550100', WorkspacePlanTier::Agency);
        $this->authenticateAs($fx['customer']);
        $this->startViewAs($fx['workspace'], $fx['business'], 'Slice 2B every-action check.')->assertRedirect();

        $this->assertEveryActionWorks($fx, canDelete: false);
    }

    public function test_business_scoped_staff_work_only_their_assigned_business(): void
    {
        [$owner, $businessA, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A ' . uniqid(), 'Agency ' . uniqid());
        $businessB = $this->addBusiness($owner, $workspace, 'Client B ' . uniqid());
        $boxA = $this->box($businessA, '15550102001', '15550109002');
        $boxB = $this->box($businessB, '15550102002', '15550109003');

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $businessA);
        $this->authenticateAs($staff, ['chat_box']);

        $this->postJson($this->conversationUrl('messages', $workspace, $businessA, $boxA->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        // The unassigned sibling is refused even with its own correct pair.
        $this->postJson($this->conversationUrl('messages', $workspace, $businessB, $boxB->uid))->assertNotFound();
    }

    /**
     * The Agency case the slice exists for: one actor, two client
     * Businesses, two completely isolated inboxes.
     */
    public function test_the_same_actor_inside_business_a_cannot_reach_business_bs_conversation(): void
    {
        [$owner, $businessA, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A ' . uniqid(), 'Agency ' . uniqid());
        $businessB = $this->addBusiness($owner, $workspace, 'Client B ' . uniqid());
        $boxB = $this->box($businessB, '15550103001', '15550109004');

        $this->authenticateAs($owner, ['chat_box']);

        foreach (['messages', 'notification', 'delete', 'block', 'pin'] as $action) {
            $this->postJson($this->conversationUrl($action, $workspace, $businessA, $boxB->uid))->assertNotFound();
        }

        $this->postJson($this->conversationUrl('reply', $workspace, $businessA, $boxB->uid), ['message' => 'x'])->assertNotFound();

        // B's own inbox, addressed as B, still works for the same actor.
        $this->postJson($this->conversationUrl('messages', $workspace, $businessB, $boxB->uid))->assertOk();

        $this->assertSame(1, DB::table('chat_boxes')->where('id', $boxB->id)->count());
        $this->assertSame(0, DB::table('blacklists')->count());
    }

    public function test_a_foreign_workspace_and_a_mismatched_pair_are_both_refused(): void
    {
        [[$customerA, , $workspaceA], [, $businessB, $workspaceB]] = $this->twoTenantBusinesses();
        $boxB = $this->box($businessB, '15550104001', '15550109005');

        $this->authenticateAs($customerA, ['chat_box']);

        // B's genuine pair: A has no access to that Workspace.
        $this->postJson($this->conversationUrl('messages', $workspaceB, $businessB, $boxB->uid))->assertNotFound();

        // A's Workspace with B's Business uid: the Business is not inside it.
        $this->postJson($this->conversationUrl('messages', $workspaceA, $businessB, $boxB->uid))->assertNotFound();
    }

    /**
     * §3 — a NULL-business legacy conversation is preserved and reachable
     * from NO Business route, even by the customer who owns it.
     */
    public function test_a_null_business_legacy_conversation_is_refused_by_every_business_route(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $legacy = $this->box($businessA, '15550105001', '15550109006', ['business_id' => null, 'pinned' => true]);
        $legacyUnpinned = $this->box($businessA, '15550105002', '15550109007', ['business_id' => null]);

        $this->authenticateAs($customerA, ['chat_box']);

        foreach (['messages', 'notification', 'delete', 'block', 'pin'] as $action) {
            $this->postJson($this->conversationUrl($action, $workspaceA, $businessA, $legacy->uid))->assertNotFound();
        }

        $this->postJson($this->conversationUrl('reply', $workspaceA, $businessA, $legacy->uid), ['message' => 'x'])->assertNotFound();

        $this->get($this->conversationUrl('index', $workspaceA, $businessA))->assertOk()->assertDontSee($legacy->uid, false);
        $this->post($this->conversationUrl('load', $workspaceA, $businessA))->assertOk()->assertDontSee($legacyUnpinned->uid, false);

        // Preserved, never deleted.
        $this->assertSame(2, DB::table('chat_boxes')->whereNull('business_id')->count());
    }

    /**
     * §9 — while viewing a client, only that client's conversations exist.
     * Non-JSON requests: the view-as middleware's own 404 is observed
     * directly, not through the JSON exception renderer.
     */
    public function test_view_as_reaches_only_the_viewed_business(): void
    {
        [$owner, $businessA, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A ' . uniqid(), 'Agency ' . uniqid());
        $businessB = $this->addBusiness($owner, $workspace, 'Client B ' . uniqid());
        $boxA = $this->box($businessA, '15550106001', '15550109008');
        $boxB = $this->box($businessB, '15550106002', '15550109009');

        $this->authenticateAs($owner);
        $this->startViewAs($workspace, $businessA, 'Slice 2B conversations check.')->assertRedirect();

        $this->post($this->conversationUrl('messages', $workspace, $businessA, $boxA->uid))->assertOk();
        $this->post($this->conversationUrl('messages', $workspace, $businessB, $boxB->uid))->assertNotFound();
        $this->get($this->conversationUrl('index', $workspace, $businessB))->assertNotFound();

        // The menu's Inbox, while viewing, is the viewed Business's inbox.
        $links = $this->menuLinks($this->home()->assertOk()->getContent());
        $this->assertContains($this->conversationUrl('index', $workspace, $businessA), $links);
        $this->assertNotContains($this->conversationUrl('index', $workspace, $businessB), $links);

        // The flat redirector is outside the viewed Business and denied.
        $this->get('/chat-box')->assertNotFound();
    }

    // ===================================================================
    // K. §10 — who a conversation is with, without leaking
    // ===================================================================

    // Every display name below is at most 15 characters: the list truncates
    // names at 15, so a longer "must not see" name would pass vacuously.

    public function test_a_same_phone_contact_in_another_business_never_leaks_its_name(): void
    {
        [[$customerA, $businessA, $workspaceA], [, $businessB]] = $this->twoTenantBusinesses();

        $pinnedPhone = '15550201001';
        $listedPhone = '15550201002';
        $controlPhone = '15550201003';
        $this->box($businessA, '15550201900', $pinnedPhone, ['pinned' => true]);
        $this->box($businessA, '15550201901', $listedPhone);
        $this->box($businessA, '15550201902', $controlPhone);

        $this->namedContact($businessB, $pinnedPhone, 'LeakPinned');
        $this->namedContact($businessB, $listedPhone, 'LeakListed');

        // Positive control: the same list DOES render a name when it is
        // this Business's own — so the absences below are real.
        $this->namedContact($businessA, $controlPhone, 'OwnControl');

        $this->authenticateAs($customerA, ['chat_box']);

        $this->get($this->conversationUrl('index', $workspaceA, $businessA))
            ->assertOk()
            ->assertDontSee('LeakPinned')
            ->assertSee($pinnedPhone);

        $this->post($this->conversationUrl('load', $workspaceA, $businessA))
            ->assertOk()
            ->assertSee('OwnControl')
            ->assertDontSee('LeakListed')
            ->assertSee($listedPhone);
    }

    public function test_exactly_one_same_business_contact_shows_its_name(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $phone = '15550202001';
        $this->box($businessA, '15550202900', $phone, ['pinned' => true]);
        $this->namedContact($businessA, $phone, 'AlphaName');

        $this->authenticateAs($customerA, ['chat_box']);

        $this->get($this->conversationUrl('index', $workspaceA, $businessA))
            ->assertOk()
            ->assertSee('AlphaName');
    }

    /**
     * Two same-Business Contacts on one number: neither name is guessed.
     */
    public function test_duplicate_same_business_contacts_fall_back_to_the_phone_number(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $phone = '15550203001';
        $this->box($businessA, '15550203900', $phone, ['pinned' => true]);
        $this->box($businessA, '15550203901', $phone);
        $this->namedContact($businessA, $phone, 'DupFirst');
        $this->namedContact($businessA, $phone, 'DupSecond');

        $this->authenticateAs($customerA, ['chat_box']);

        $this->get($this->conversationUrl('index', $workspaceA, $businessA))
            ->assertOk()
            ->assertDontSee('DupFirst')
            ->assertDontSee('DupSecond')
            ->assertSee($phone);

        $this->post($this->conversationUrl('load', $workspaceA, $businessA))
            ->assertOk()
            ->assertDontSee('DupFirst')
            ->assertDontSee('DupSecond')
            ->assertSee($phone);
    }

    public function test_a_conversation_with_no_contact_still_renders_its_number(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $phone = '15550204001';
        $this->box($businessA, '15550204900', $phone, ['pinned' => true]);

        $this->authenticateAs($customerA, ['chat_box']);

        $this->get($this->conversationUrl('index', $workspaceA, $businessA))->assertOk()->assertSee($phone);
        $this->assertSame(0, Contacts::query()->count(), 'No Contact is ever created for a conversation.');
    }

    // ===================================================================
    // L. §11 — block() targets the external party, in one Business
    // ===================================================================

    /**
     * The regression the task names: a conversation that STARTED with an
     * inbound message must block the external sender — never the Business's
     * own receiving number.
     */
    public function test_blocking_an_inbound_created_conversation_blocks_the_external_sender(): void
    {
        $fx = $this->sendableBusiness();
        $external = '14155557001';

        DLRController::inboundDLR($external, 'hello', $fx['server'], 0, $fx['number']->number);

        $box = ChatBox::query()->where('business_id', $fx['business']->id)->firstOrFail();
        $this->assertSame($fx['number']->number, $box->from, 'from is the Business receiving number.');
        $this->assertSame($external, $box->to, 'to is the external sender.');

        $this->authenticateAs($fx['customer'], ['chat_box']);

        $this->postJson($this->conversationUrl('block', $fx['workspace'], $fx['business'], $box->uid))->assertOk();

        $this->assertSame(1, DB::table('blacklists')->where('number', $external)->count());
        $this->assertSame(0, DB::table('blacklists')->where('number', $fx['number']->number)->count(), 'The Business number is never a blacklist target.');
    }

    public function test_block_persists_the_business_owner_not_the_acting_staff_member(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Owner Co ' . uniqid(), 'Owner WS ' . uniqid());
        $box = $this->box($business, '15550301001', '15550309001');

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $business);
        $this->authenticateAs($staff, ['chat_box']);

        $this->postJson($this->conversationUrl('block', $workspace, $business, $box->uid))->assertOk();

        $row = DB::table('blacklists')->where('number', $box->to)->first();
        $this->assertSame((int) $business->customer_id, (int) $row->user_id, 'Persisted against the Business owner.');
        $this->assertNotSame((int) $staff->user_id, (int) $row->user_id, 'Never the staff member.');
        $this->assertSame((int) $business->id, (int) $row->business_id);
    }

    public function test_block_unsubscribes_every_duplicate_contact_inside_the_same_business(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $phone = '15550302001';
        $box = $this->box($businessA, '15550302900', $phone);
        $first = $this->contact($businessA, $phone);
        $second = $this->contact($businessA, $phone);

        $this->authenticateAs($customerA, ['chat_box']);
        $this->postJson($this->conversationUrl('block', $workspaceA, $businessA, $box->uid))->assertOk();

        $this->assertSame('unsubscribe', DB::table('contacts')->where('id', $first->id)->value('status'));
        $this->assertSame('unsubscribe', DB::table('contacts')->where('id', $second->id)->value('status'));
    }

    // ===================================================================
    // M. §6 — compose accepts only this Business's own resources
    // ===================================================================

    public function test_a_foreign_sending_server_is_rejected(): void
    {
        $fxA = $this->sendableBusiness();
        $fxB = $this->sendableBusiness('14155550200');
        $this->stubProviderDelivers();

        $this->authenticateAs($fxA['customer'], ['chat_box']);

        $this->post($this->conversationUrl('sent', $fxA['workspace'], $fxA['business']), $this->composePayload($fxA, [
            'sending_server' => $fxB['server']->id,
        ]))->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertSame(0, ChatBox::query()->count());
    }

    public function test_a_foreign_phone_number_is_rejected_as_the_sender(): void
    {
        $fxA = $this->sendableBusiness();
        $fxB = $this->sendableBusiness('14155550200');
        $this->stubProviderDelivers();

        $this->authenticateAs($fxA['customer'], ['chat_box']);

        $this->post($this->conversationUrl('sent', $fxA['workspace'], $fxA['business']), $this->composePayload($fxA, [
            'sender_id' => $fxB['number']->number,
        ]))->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertSame(0, ChatBox::query()->count());
    }

    public function test_a_foreign_sender_identity_is_rejected(): void
    {
        $fxA = $this->sendableBusiness();
        $fxB = $this->sendableBusiness('14155550200');
        $this->stubProviderDelivers();

        Senderid::create([
            'user_id' => $fxB['owner']->id,
            'business_id' => $fxB['business']->id,
            'sender_id' => '14155550777',
            'status' => 'active',
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
        ]);

        $this->authenticateAs($fxA['customer'], ['chat_box']);

        $this->post($this->conversationUrl('sent', $fxA['workspace'], $fxA['business']), $this->composePayload($fxA, [
            'sender_id' => '14155550777',
        ]))->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertSame(0, ChatBox::query()->count());
    }

    /**
     * The compose screen loads template text through B1's Business-scoped
     * endpoint, which finds only the selected Business's own templates.
     */
    public function test_a_foreign_template_is_not_found_through_the_compose_template_fetch(): void
    {
        // A is sendable so its compose page (which needs an active
        // subscription) renders rather than redirecting.
        $fxA = $this->sendableBusiness();
        [$customerA, $businessA, $workspaceA] = [$fxA['customer'], $fxA['business'], $fxA['workspace']];
        [, $businessB] = $this->tenant(WorkspacePlanTier::Core, 'Bravo Studio ' . uniqid(), 'Bravo ' . uniqid());

        $own = Templates::create(['name' => 'OwnTemplateAlpha', 'user_id' => $businessA->customer_id, 'business_id' => $businessA->id, 'message' => 'own text', 'status' => true]);
        $foreign = Templates::create(['name' => 'ForeignTemplateBravo', 'user_id' => $businessB->customer_id, 'business_id' => $businessB->id, 'message' => 'foreign text', 'status' => true]);

        $this->authenticateAs($customerA);

        $fetch = fn (Templates $template) => $this->postJson(route('customer.workspaces.businesses.outreach.templates.show_data', [$workspaceA->uid, $businessA->uid, $template->id]));

        $fetch($own)->assertOk()->assertJson(['status' => 'success', 'message' => 'own text']);
        $fetch($foreign)->assertOk()->assertJson(['status' => 'error'])->assertDontSee('foreign text');

        // And neither compose surface ever offers another Business's template.
        foreach (['index', 'new'] as $page) {
            $this->get($this->conversationUrl($page, $workspaceA, $businessA))
                ->assertOk()
                ->assertSee('OwnTemplateAlpha')
                ->assertDontSee('ForeignTemplateBravo');
        }
    }

    /**
     * §6 — a staff member composes; the conversation belongs to the Business
     * and its owning customer, never to the staff member.
     */
    public function test_staff_compose_persists_the_business_owner_and_the_business(): void
    {
        $fx = $this->sendableBusiness();
        $this->stubProviderDelivers();

        $staff = $this->createCustomer();
        $membership = $this->member($fx['workspace'], $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->assign($membership, $fx['business']);
        $this->authenticateAs($staff, ['chat_box']);

        $this->post($this->conversationUrl('sent', $fx['workspace'], $fx['business']), $this->composePayload($fx))
            ->assertRedirect($this->conversationUrl('index', $fx['workspace'], $fx['business']));

        $box = ChatBox::query()->firstOrFail();
        $this->assertSame((int) $fx['business']->id, (int) $box->business_id);
        $this->assertSame((int) $fx['owner']->id, (int) $box->user_id);
        $this->assertNotSame((int) $staff->user_id, (int) $box->user_id);
    }

    // ===================================================================
    // N. §4/§5 — producers, orientation, and one thread per real pair
    // ===================================================================

    public function test_business_compose_writes_the_business_and_the_domain_orientation(): void
    {
        $fx = $this->sendableBusiness();
        $this->stubProviderDelivers();
        $this->authenticateAs($fx['customer'], ['chat_box']);

        $this->post($this->conversationUrl('sent', $fx['workspace'], $fx['business']), $this->composePayload($fx, ['recipient' => '4155557100']))
            ->assertRedirect();

        $box = ChatBox::query()->firstOrFail();
        $this->assertSame((int) $fx['business']->id, (int) $box->business_id);
        $this->assertSame($fx['number']->number, $box->from, 'from = the Business sender identity.');
        $this->assertSame('14155557100', $box->to, 'to = the typed recipient.');
    }

    public function test_an_authoritative_inbound_writes_the_receiving_numbers_business(): void
    {
        $fx = $this->sendableBusiness();

        DLRController::inboundDLR('14155557200', 'hi', $fx['server'], 0, $fx['number']->number);

        $box = ChatBox::query()->firstOrFail();
        $this->assertSame((int) $fx['business']->id, (int) $box->business_id);
        $this->assertSame($fx['number']->number, $box->from);
        $this->assertSame('14155557200', $box->to);
    }

    /**
     * A receiving number that carries no Business cannot prove which of
     * the customer's Businesses the conversation belongs to. NULL, never a
     * guess — and so invisible to every Business route.
     */
    public function test_an_inbound_whose_number_carries_no_business_writes_null(): void
    {
        $fx = $this->sendableBusiness();
        $fx['number']->update(['business_id' => null]);

        DLRController::inboundDLR('14155557300', 'hi', $fx['server'], 0, $fx['number']->number);

        $box = ChatBox::query()->firstOrFail();
        $this->assertNull($box->business_id);
    }

    public function test_an_inbound_whose_number_names_another_customers_business_writes_null(): void
    {
        $fx = $this->sendableBusiness();
        $other = $this->sendableBusiness('14155550300');

        // Corrupt data: the number is this customer's, its business_id is not.
        $fx['number']->update(['business_id' => $other['business']->id]);

        DLRController::inboundDLR('14155557400', 'hi', $fx['server'], 0, $fx['number']->number);

        $box = ChatBox::query()->where('user_id', $fx['owner']->id)->firstOrFail();
        $this->assertNull($box->business_id, 'A Business that is not the customer\'s is never written.');
    }

    /**
     * Outbound first, then the contact replies: one thread.
     */
    public function test_an_outbound_created_conversation_and_the_inbound_reply_converge(): void
    {
        $fx = $this->sendableBusiness();
        $this->stubProviderDelivers();
        $this->authenticateAs($fx['customer'], ['chat_box']);

        $this->post($this->conversationUrl('sent', $fx['workspace'], $fx['business']), $this->composePayload($fx, ['recipient' => '4155557500']))
            ->assertRedirect();

        DLRController::inboundDLR('14155557500', 'reply from the contact', $fx['server'], 0, $fx['number']->number);

        $this->assertSame(1, ChatBox::query()->count(), 'Never two mirrored threads.');
        $box = ChatBox::query()->firstOrFail();
        $this->assertSame(['incoming', 'outgoing'], ChatBoxMessage::where('box_id', $box->id)->pluck('direction')->sort()->values()->all());
    }

    /**
     * Inbound first, then the Business replies: one thread.
     */
    public function test_an_inbound_created_conversation_and_the_business_reply_converge(): void
    {
        $fx = $this->sendableBusiness();
        $this->stubProviderDelivers();

        DLRController::inboundDLR('14155557600', 'hello business', $fx['server'], 0, $fx['number']->number);
        $box = ChatBox::query()->firstOrFail();

        $this->authenticateAs($fx['customer'], ['chat_box']);
        $this->postJson($this->conversationUrl('reply', $fx['workspace'], $fx['business'], $box->uid), [
            'message' => 'thanks for writing',
            'idempotency_token' => (string) Str::uuid(),
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame(1, ChatBox::query()->count(), 'The reply joins the inbound thread.');
        $this->assertSame((int) $fx['business']->id, (int) ChatBox::query()->value('business_id'), 'And keeps its Business.');
        $this->assertSame(2, ChatBoxMessage::where('box_id', $box->id)->count());
    }

    /**
     * The inbound keyword auto-reply's way into the conversation. inboundDLR()
     * builds its own `new Campaigns()`, so an end-to-end run would reach a
     * live provider; the repository half is proven here directly, the way the
     * M5 suite drives quickSend(): `conversation_business_id` lands the reply
     * on the inbound thread, and is read for that identity ONLY.
     */
    public function test_an_auto_reply_carrying_conversation_business_id_joins_the_inbound_thread(): void
    {
        $fx = $this->sendableBusiness();
        DLRController::inboundDLR('14155557990', 'hi', $fx['server'], 0, $fx['number']->number);
        $inbound = ChatBox::query()->firstOrFail();

        $stub = \Mockery::mock(Campaigns::class)->makePartial();
        $stub->shouldReceive('sendPlainSMS')->andReturn((object) [
            'id' => 1, 'uid' => (string) Str::uuid(), 'status' => 'Delivered', 'customer_status' => 'Delivered',
            'cost' => 0, 'sms_count' => 1, 'media_url' => null,
        ]);

        $response = app(\App\Repositories\Eloquent\EloquentCampaignRepository::class)->quickSend($stub, [
            'user' => $fx['owner'],
            'sender_id' => $fx['number']->number,
            'phone_number' => $fx['number']->number,
            'conversation_business_id' => $fx['business']->id,
            'originator' => 'phone_number',
            'sms_type' => 'plain',
            'message' => 'Thanks — keyword received.',
            'recipient' => '4155557990',
            'country_code' => '1',
            'region_code' => 'US',
            'sending_server' => $fx['server']->id,
        ]);

        $this->assertSame('success', $response->getData()->status);
        $this->assertSame(1, ChatBox::query()->count(), 'The auto-reply joins the thread the inbound message opened.');
        $this->assertSame(2, ChatBoxMessage::where('box_id', $inbound->id)->count());
    }

    public function test_conversation_business_id_never_changes_how_an_auto_reply_is_delivered(): void
    {
        $fx = $this->sendableBusiness();

        // A legacy gateway the Business has NOT been assigned. As `business_id`
        // it would be refused by quickSend()'s Business gate; as
        // `conversation_business_id` the delivery path is exactly the
        // pre-2B one.
        $legacyServer = SendingServer::create([
            'name' => 'Legacy keyword gateway ' . uniqid(), 'user_id' => $fx['owner']->id,
            'settings' => SendingServer::TYPE_TWILIO, 'status' => true, 'two_way' => true, 'plain' => true,
        ]);

        $stub = \Mockery::mock(Campaigns::class)->makePartial();
        $stub->shouldReceive('sendPlainSMS')->andReturn((object) [
            'id' => 1, 'uid' => (string) Str::uuid(), 'status' => 'Delivered', 'customer_status' => 'Delivered',
            'cost' => 0, 'sms_count' => 1, 'media_url' => null,
        ]);

        $payload = fn (string $key) => [
            'user' => $fx['owner'],
            'sender_id' => $fx['number']->number,
            'phone_number' => $fx['number']->number,
            $key => $fx['business']->id,
            'originator' => 'phone_number',
            'sms_type' => 'plain',
            'message' => 'Auto reply ' . $key,
            'recipient' => '4155557991',
            'country_code' => '1',
            'region_code' => 'US',
            'sending_server' => $legacyServer->id,
        ];

        $repository = app(\App\Repositories\Eloquent\EloquentCampaignRepository::class);

        // Control: the Business gate DOES refuse this server for `business_id`.
        $this->assertSame('error', $repository->quickSend($stub, $payload('business_id'))->getData()->status);

        $delivered = $repository->quickSend($stub, $payload('conversation_business_id'));

        $this->assertSame('success', $delivered->getData()->status);
        $box = ChatBox::query()->firstOrFail();
        $this->assertSame((int) $fx['business']->id, (int) $box->business_id);
        $this->assertSame((int) $legacyServer->id, (int) $box->sending_server_id);
    }

    // ===================================================================
    // O. §12 — send_by
    // ===================================================================

    public function test_a_new_outgoing_message_records_send_by_from_and_leaves_history_alone(): void
    {
        $fx = $this->sendableBusiness();
        $this->stubProviderDelivers();

        DLRController::inboundDLR('14155557700', 'hi', $fx['server'], 0, $fx['number']->number);
        $box = ChatBox::query()->firstOrFail();

        // A historical row carrying a semantically wrong value, as legacy
        // data does. It must be left exactly as it is.
        $historicalId = DB::table('chat_box_messages')->insertGetId([
            'box_id' => $box->id, 'message' => 'legacy', 'direction' => 'outgoing', 'send_by' => 'to',
            'sms_type' => 'plain', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00',
        ]);

        $this->authenticateAs($fx['customer'], ['chat_box']);
        $this->postJson($this->conversationUrl('reply', $fx['workspace'], $fx['business'], $box->uid), [
            'message' => 'new outgoing',
            'idempotency_token' => (string) Str::uuid(),
        ])->assertOk();

        $new = ChatBoxMessage::where('box_id', $box->id)->where('message', 'new outgoing')->firstOrFail();
        $this->assertSame('from', $new->send_by);
        $this->assertSame('outgoing', $new->direction);

        $this->assertSame('to', DB::table('chat_box_messages')->where('id', $historicalId)->value('send_by'));
    }

    // ===================================================================
    // P. §7/§8 — the route family, and what is gone
    // ===================================================================

    public function test_the_eight_retired_flat_route_names_no_longer_exist(): void
    {
        foreach (['sent', 'messages', 'notification', 'reply', 'delete', 'block', 'pin', 'load'] as $retired) {
            $this->assertFalse(Route::has('customer.chatbox.' . $retired), "customer.chatbox.{$retired} must be gone.");
        }

        foreach (['index', 'new', 'sent', 'load', 'messages', 'notification', 'reply', 'delete', 'block', 'pin'] as $canonical) {
            $this->assertTrue(Route::has('customer.workspaces.businesses.conversations.' . $canonical), "conversations.{$canonical} must exist.");
        }

        // load is POST only — no GET alias.
        $load = app('router')->getRoutes()->getByName('customer.workspaces.businesses.conversations.load');
        $this->assertSame(['POST'], array_values(array_diff($load->methods(), ['HEAD'])));
    }

    public function test_a_retired_flat_post_uri_no_longer_routes(): void
    {
        [[$customerA, $businessA]] = $this->twoTenantBusinesses();
        $box = $this->box($businessA, '15550401001', '15550409001');
        $this->authenticateAs($customerA, ['chat_box']);

        foreach (['/chat-box/sent', "/chat-box/{$box->uid}/messages", "/chat-box/{$box->uid}/block", '/chat-box/load'] as $uri) {
            $this->post($uri)->assertNotFound();
        }

        $this->assertSame(0, DB::table('blacklists')->count());
    }

    public function test_the_bare_inbox_redirects_to_the_only_business(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Solo ' . uniqid(), 'Solo WS ' . uniqid());
        $this->authenticateAs($customer, ['chat_box']);

        $this->get('/chat-box')->assertRedirect($this->conversationUrl('index', $workspace, $business));
        $this->get('/chat-box/new')->assertRedirect($this->conversationUrl('new', $workspace, $business));
    }

    public function test_the_bare_inbox_sends_a_multi_business_actor_to_the_chooser_never_a_guess(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client A ' . uniqid(), 'Agency ' . uniqid());
        $this->addBusiness($owner, $workspace, 'Client B ' . uniqid());
        $this->authenticateAs($owner, ['chat_box']);

        $this->get('/chat-box')->assertRedirect(route('customer.workspaces.index'));
        $this->get('/chat-box/new')->assertRedirect(route('customer.workspaces.index'));
    }

    public function test_the_bare_inbox_sends_an_actor_with_no_business_to_onboarding(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $this->authenticateAs($customer, ['chat_box']);

        $response = $this->get('/chat-box');

        $response->assertRedirect();
        $this->assertStringContainsString('onboarding', (string) $response->headers->get('Location'));
    }

    /**
     * §21 — no first-party code still names a retired flat route. The two
     * surviving GET names are the compatibility redirectors themselves.
     */
    public function test_no_first_party_code_names_a_retired_flat_route(): void
    {
        $offenders = [];
        $pattern = "/customer\\.chatbox\\.(sent|messages|notification|reply|delete|block|pin|load)\\b|url\\(\\s*'\\/?chat-box\\//";

        foreach (['app', 'resources/views', 'routes'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root)));

            foreach ($files as $file) {
                if (! $file->isFile() || ! preg_match('/\.php$/', $file->getFilename())) {
                    continue;
                }

                if (preg_match($pattern, (string) file_get_contents($file->getPathname()))) {
                    $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'Still naming a retired route: ' . implode(', ', $offenders));
    }

    // ===================================================================
    // Q. §16 — entitlement, from the menu and the controller alike
    // ===================================================================

    public function test_inbox_disappears_and_the_route_refuses_when_conversations_is_not_entitled(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Entitled ' . uniqid(), 'Ent WS ' . uniqid());
        $box = $this->box($business, '15550501001', '15550509001');

        $this->authenticateAs($customer);
        $this->assertContains('inbox', $this->menuKeys($this->home()->assertOk()->getContent()), 'Precondition: entitled.');

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Conversations, (int) $customer->user_id, 'Slice 2B test.');

        $this->assertNotContains('inbox', $this->menuKeys($this->home()->assertOk()->getContent()));
        $this->get($this->conversationUrl('index', $workspace, $business))->assertNotFound();
        $this->postJson($this->conversationUrl('messages', $workspace, $business, $box->uid))->assertNotFound();
    }

    public function test_the_inbox_entry_points_at_the_selected_business_route(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Menu ' . uniqid(), 'Menu WS ' . uniqid());
        $this->authenticateAs($customer);

        $links = $this->menuLinks($this->home()->assertOk()->getContent());

        $this->assertContains($this->conversationUrl('index', $workspace, $business), $links);
    }

    // ===================================================================
    // R. §14 — the list reads one message per conversation, no N+1
    // ===================================================================

    public function test_the_list_query_count_does_not_grow_with_the_number_of_conversations(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $this->authenticateAs($customerA, ['chat_box']);

        $queries = null;
        DB::listen(function () use (&$queries) {
            if ($queries !== null) {
                $queries++;
            }
        });

        $count = function (int $conversations) use ($businessA, $workspaceA, &$queries): int {
            DB::table('chat_box_messages')->delete();
            DB::table('chat_boxes')->delete();

            for ($i = 0; $i < $conversations; $i++) {
                $box = $this->box($businessA, '1555060' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), '1555069' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));

                for ($m = 0; $m < 5; $m++) {
                    ChatBoxMessage::create(['box_id' => $box->id, 'message' => "m{$m}", 'direction' => 'incoming', 'sms_type' => 'plain']);
                }
            }

            $queries = 0;
            $this->post($this->conversationUrl('load', $workspaceA, $businessA))->assertOk();
            $measured = $queries;
            $queries = null;

            return $measured;
        };

        // Warm every per-process memo first, so neither measured request
        // is cheaper merely for coming second.
        $count(1);

        $few = $count(2);
        $many = $count(12);

        $this->assertSame($few, $many, "The list cost {$many} queries for 12 conversations against {$few} for 2.");
    }

    /**
     * The same guarantee when every row has a named Contact — the case that
     * per-row Contacts::getFullName() turns into two queries per row.
     */
    public function test_named_conversations_cost_no_query_per_row(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $this->authenticateAs($customerA, ['chat_box']);

        $queries = null;
        DB::listen(function () use (&$queries) {
            if ($queries !== null) {
                $queries++;
            }
        });

        $count = function (int $conversations, bool $pinned) use ($businessA, $workspaceA, &$queries): int {
            DB::table('contacts_custom_field')->delete();
            DB::table('contacts')->delete();
            DB::table('chat_boxes')->delete();

            for ($i = 0; $i < $conversations; $i++) {
                $phone = '1555061' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                $this->box($businessA, '1555068' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), $phone, ['pinned' => $pinned]);
                $this->namedContact($businessA, $phone, 'Nm' . $i);
            }

            $queries = 0;
            $pinned
                ? $this->get($this->conversationUrl('index', $workspaceA, $businessA))->assertOk()->assertSee('Nm0')
                : $this->post($this->conversationUrl('load', $workspaceA, $businessA))->assertOk()->assertSee('Nm0');
            $measured = $queries;
            $queries = null;

            return $measured;
        };

        foreach ([false, true] as $pinned) {
            $count(1, $pinned);

            $few = $count(2, $pinned);
            $many = $count(12, $pinned);

            $this->assertSame($few, $many, ($pinned ? 'Pinned rail' : 'List') . " cost {$many} queries for 12 named conversations against {$few} for 2.");
        }
    }

    /**
     * The batched names are exactly what Contacts::getFullName() would say.
     */
    public function test_batched_display_names_match_get_full_name(): void
    {
        [[, $businessA]] = $this->twoTenantBusinesses();

        $group = \App\Models\ContactGroups::create(['customer_id' => $businessA->customer_id, 'business_id' => $businessA->id, 'name' => 'Parity ' . uniqid()]);
        $first = \App\Models\ContactGroupFields::create(['contact_group_id' => $group->id, 'label' => 'First name', 'type' => 'text', 'tag' => 'FIRST_NAME']);
        $last = \App\Models\ContactGroupFields::create(['contact_group_id' => $group->id, 'label' => 'Last name', 'type' => 'text', 'tag' => 'LAST_NAME']);

        $cases = [
            '15550801001' => ['Ada', 'Lovelace'],
            '15550801002' => ['Grace', null],
            '15550801003' => [null, 'Hopper'],
            '15550801004' => [null, null],
        ];

        $boxes = [];

        foreach ($cases as $phone => [$firstName, $lastName]) {
            $contact = Contacts::create(['customer_id' => $businessA->customer_id, 'business_id' => $businessA->id, 'group_id' => $group->id, 'phone' => $phone, 'status' => 'subscribe']);

            if ($firstName !== null) {
                \App\Models\ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $first->id, 'value' => $firstName]);
            }

            if ($lastName !== null) {
                \App\Models\ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $last->id, 'value' => $lastName]);
            }

            $boxes[$phone] = [$this->box($businessA, '15550801900', $phone), $contact];
        }

        $names = ChatBox::displayNamesFor($businessA, array_column($boxes, 0));

        foreach ($boxes as $phone => [$box, $contact]) {
            $this->assertSame($contact->fresh()->getFullName(), $names[$box->id], "Name for {$phone}.");
        }

        $this->assertSame('Ada Lovelace', $names[$boxes['15550801001'][0]->id]);
        $this->assertNull($names[$boxes['15550801004'][0]->id], 'No name → show the number.');
    }

    public function test_the_list_preview_is_the_latest_message_not_the_whole_history(): void
    {
        [[$customerA, $businessA, $workspaceA]] = $this->twoTenantBusinesses();
        $box = $this->box($businessA, '15550701001', '15550709001');

        foreach (['oldest message', 'middle message', 'newest message'] as $i => $text) {
            DB::table('chat_box_messages')->insert([
                'box_id' => $box->id, 'message' => $text, 'direction' => 'incoming', 'sms_type' => 'plain',
                'created_at' => now()->subMinutes(10 - $i), 'updated_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $this->authenticateAs($customerA, ['chat_box']);

        $this->post($this->conversationUrl('load', $workspaceA, $businessA))
            ->assertOk()
            ->assertSee('newest message')
            ->assertDontSee('oldest message');
    }

    public function test_the_locked_indexes_exist(): void
    {
        foreach ([
            'chat_boxes_business_id_index',
            'chat_boxes_business_pinned_updated_index',
            'chat_boxes_business_notification_index',
            'chat_boxes_business_id_created_at_index',
        ] as $index) {
            $this->assertTrue(Schema::hasIndex('chat_boxes', $index), "{$index} must exist.");
        }
    }

    // ===================================================================
    // S. §3/§13/§20 — separation
    // ===================================================================

    public function test_the_tenancy_column_is_nullable_indexed_and_restricted(): void
    {
        $column = DB::selectOne(
            'select IS_NULLABLE as is_nullable from information_schema.columns
             where table_schema = database() and table_name = ? and column_name = ?',
            ['chat_boxes', 'business_id'],
        );
        $this->assertSame('YES', $column->is_nullable);

        $rule = DB::selectOne(
            'select DELETE_RULE as delete_rule from information_schema.referential_constraints
             where constraint_schema = database() and constraint_name = ?',
            ['chat_boxes_business_id_foreign'],
        );
        $this->assertNotNull($rule);
        $this->assertSame('RESTRICT', $rule->delete_rule);

        // Messages inherit tenancy from their box; no second copy.
        $this->assertFalse(Schema::hasColumn('chat_box_messages', 'business_id'));
    }

    public function test_no_contacts_uniqueness_constraint_was_added(): void
    {
        $unique = collect(Schema::getIndexes('contacts'))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
            ->pluck('name')
            ->all();

        $this->assertSame([], $unique, 'Slice 2B adds no Contacts uniqueness.');
    }

    /**
     * Conversations never reads or writes Agency Prospecting. (Billing is
     * not asserted here: a Delivered BYO send records Slice 3's existing
     * zero-rate transport measurement inside quickSend(), which Slice 2B
     * neither adds nor changes.)
     */
    public function test_conversation_traffic_touches_no_agency_prospecting_row(): void
    {
        $fx = $this->sendableBusiness();
        $this->stubProviderDelivers();

        $prospectTables = collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $name): bool => str_starts_with($name, 'agency_prospect'))
            ->values();

        $before = $prospectTables->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $this->authenticateAs($fx['customer'], ['chat_box']);
        $this->post($this->conversationUrl('sent', $fx['workspace'], $fx['business']), $this->composePayload($fx))->assertRedirect();
        DLRController::inboundDLR('14155557800', 'hi', $fx['server'], 0, $fx['number']->number);

        $after = $prospectTables->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();

        $this->assertNotEmpty($before, 'Precondition: the Agency Prospecting tables exist to be compared.');
        $this->assertSame($before, $after, 'No Agency Prospecting row is written by Conversations.');
        $this->assertSame(2, ChatBox::query()->count(), 'Precondition: both producers actually ran.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Two unrelated tenants, each with its own Workspace and Business on a
     * plan that includes Conversations.
     *
     * @return array{0: array{0: \App\Models\Customer, 1: Business, 2: Workspace}, 1: array{0: \App\Models\Customer, 1: Business, 2: Workspace}}
     */
    private function twoTenantBusinesses(): array
    {
        return [
            $this->tenant(WorkspacePlanTier::Core, 'Alpha Studio ' . uniqid(), 'Alpha ' . uniqid()),
            $this->tenant(WorkspacePlanTier::Core, 'Bravo Studio ' . uniqid(), 'Bravo ' . uniqid()),
        ];
    }

    /**
     * A conversation owned by a Business: the Business's owning customer is
     * the persistence owner, and `from` is the Business-side number.
     */
    private function box(Business $business, string $from, string $to, array $overrides = []): ChatBox
    {
        $box = new ChatBox(array_merge([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => $from,
            'to' => $to,
            'reply_by_customer' => true,
        ], $overrides));
        $box->uid = (string) Str::uuid();
        $box->save();

        return $box;
    }

    private function contact(Business $business, string $phone, string $first = 'Contact', string $status = 'subscribe'): Contacts
    {
        return Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => null,
            'phone' => $phone,
            'first_name' => $first,
            'status' => $status,
        ]);
    }

    private function conversationUrl(string $action, Workspace $workspace, Business $business, ?string $uid = null): string
    {
        $parameters = [$workspace->uid, $business->uid];

        if ($uid !== null) {
            $parameters[] = $uid;
        }

        return route('customer.workspaces.businesses.conversations.' . $action, $parameters);
    }

    /**
     * A tenant whose Business can genuinely send and receive: an active
     * subscription and price plan, a two-way Twilio server ASSIGNED to the
     * Business, and a Business-owned receiving number. Only the provider
     * boundary is ever stubbed (stubProviderDelivers()); quickSend() and
     * inboundDLR() run for real.
     *
     * @return array{customer: \App\Models\Customer, owner: User, business: Business, workspace: Workspace, country: Country, server: SendingServer, number: PhoneNumbers}
     */
    private function sendableBusiness(string $businessNumber = '14155550100', WorkspacePlanTier $tier = WorkspacePlanTier::Core): array
    {
        Http::fake();

        [$customer, $business, $workspace] = $this->tenant($tier, 'Sendable ' . uniqid(), 'Sendable WS ' . uniqid());

        $owner = User::findOrFail($business->customer_id);
        $owner->forceFill(['sms_unit' => '-1'])->save();

        $country = Country::firstOrCreate(
            ['country_code' => '1', 'iso_code' => 'US'],
            ['name' => 'United States', 'status' => 1],
        );

        $currency = Currency::query()->where('code', 'USD')->first()
            ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '${PRICE}', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Slice 2B Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
            'custom_order' => 0,
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $server = SendingServer::create([
            'name' => 'Slice 2B Twilio ' . uniqid(),
            'user_id' => $owner->id,
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'two_way' => true,
            'plain' => true,
            'mms' => true,
            'account_sid' => 'ACtest',
            'auth_token' => 'authtest',
        ]);

        CustomerBasedPricingPlan::create([
            'user_id' => $owner->id,
            'country_id' => $country->id,
            'plan_id' => $plan->id,
            'sending_server' => $server->id,
            'options' => json_encode([
                'plain_sms' => 0.05, 'voice_sms' => 0.10, 'mms_sms' => 0.10,
                'whatsapp_sms' => 0.10, 'viber_sms' => 0.10, 'otp_sms' => 0.10,
            ]),
            'status' => true,
        ]);

        CustomerBasedSendingServer::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'sending_server' => $server->id,
            'status' => true,
        ]);

        $number = PhoneNumbers::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'number' => $businessNumber,
            'status' => 'assigned',
            'capabilities' => json_encode(['sms', 'mms']),
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'validity_date' => now()->addMonth(),
        ]);

        return [
            'customer' => $customer,
            'owner' => $owner->fresh(),
            'business' => $business,
            'workspace' => $workspace,
            'country' => $country,
            'server' => $server,
            'number' => $number->fresh(),
        ];
    }

    /**
     * Every conversation action, in the fixture's Business, as whoever is
     * authenticated right now. Order matters: reply runs before block
     * (a blocked number cannot be replied to) and delete runs last.
     */
    private function assertEveryActionWorks(array $fx, bool $canDelete = true): void
    {
        $this->stubProviderDelivers();
        [$workspace, $business] = [$fx['workspace'], $fx['business']];
        $external = '14155557950';

        $box = $this->box($business, $fx['number']->number, $external, ['sending_server_id' => $fx['server']->id]);
        ChatBoxMessage::create(['box_id' => $box->id, 'message' => 'inbound hello', 'direction' => 'incoming', 'sms_type' => 'plain']);

        $this->get($this->conversationUrl('index', $workspace, $business))->assertOk();
        $this->post($this->conversationUrl('load', $workspace, $business))->assertOk()->assertSee($box->uid, false);

        foreach (['messages', 'notification', 'pin'] as $action) {
            $this->postJson($this->conversationUrl($action, $workspace, $business, $box->uid))
                ->assertOk()
                ->assertJson(['status' => 'success']);
        }
        $this->assertTrue((bool) $box->fresh()->pinned, 'pin toggled the conversation.');

        $this->postJson($this->conversationUrl('reply', $workspace, $business, $box->uid), [
            'message' => 'reply from the team',
            'idempotency_token' => (string) Str::uuid(),
        ])->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame(1, ChatBoxMessage::where('box_id', $box->id)->where('direction', 'outgoing')->count(), 'The reply joined this thread.');
        $this->assertSame(1, ChatBox::query()->count());

        $this->postJson($this->conversationUrl('block', $workspace, $business, $box->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);
        $this->assertSame(1, DB::table('blacklists')->where('business_id', $business->id)->where('number', $external)->count());

        $delete = $this->postJson($this->conversationUrl('delete', $workspace, $business, $box->uid));

        if ($canDelete) {
            $delete->assertOk()->assertJson(['status' => 'success']);
            $this->assertNull(ChatBox::query()->find($box->id));
        } else {
            $delete->assertForbidden();
            $this->assertNotNull(ChatBox::query()->find($box->id), 'Deleting data is prohibited while viewing.');
        }
    }

    /**
     * The ONE stub: the provider accepts the message. Partial, so the
     * controller can still set business_id on the Campaigns instance.
     */
    private function stubProviderDelivers(): void
    {
        $campaign = \Mockery::mock(Campaigns::class)->makePartial();
        $campaign->shouldReceive('sendPlainSMS')->andReturnUsing(fn (array $data) => (object) [
            'id' => 1,
            'uid' => (string) Str::uuid(),
            'to' => $data['phone'],
            'from' => $data['sender_id'],
            'message' => $data['message'],
            'customer_status' => 'Delivered',
            'status' => 'Delivered',
            'cost' => 0,
            'sms_count' => 1,
            'media_url' => null,
        ]);

        $this->app->instance(Campaigns::class, $campaign);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function composePayload(array $fx, array $overrides = []): array
    {
        return array_merge([
            'sender_id' => $fx['number']->number,
            'country_code' => $fx['country']->id,
            'recipient' => '4155557000',
            'sms_type' => 'plain',
            'message' => 'Hello from the Business',
            'sending_server' => $fx['server']->id,
            'idempotency_token' => (string) Str::uuid(),
        ], $overrides);
    }

    /**
     * A Contact whose display name exists the way the application actually
     * stores names: a FIRST_NAME custom field on the Contact's group.
     */
    private function namedContact(Business $business, string $phone, string $firstName): Contacts
    {
        $group = \App\Models\ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Group ' . uniqid(),
        ]);

        $field = \App\Models\ContactGroupFields::create([
            'contact_group_id' => $group->id,
            'label' => 'First name',
            'type' => 'text',
            'tag' => 'FIRST_NAME',
        ]);

        $contact = Contacts::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => 'subscribe',
        ]);

        \App\Models\ContactsCustomField::create([
            'contact_id' => $contact->id,
            'field_id' => $field->id,
            'value' => $firstName,
        ]);

        $this->assertSame($firstName, $contact->fresh()->getFullName(), 'Fixture precondition: the name resolves.');

        return $contact;
    }
}
