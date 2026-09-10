<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\WebhookRejectionReason;
use App\Http\Controllers\Customer\DLRController;
use App\Models\MessagingWebhookRejection;
use App\Models\Reports;
use App\Models\SendingServer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Customer Experience Slice 3 — Security Correction 36.
 *
 * The legacy `inbound/*` and DLR surface is public and unauthenticated. An
 * independent audit found five mechanically proven P0 defects there plus two
 * Twilio sibling bypasses; this file is the regression for all of them.
 *
 * Every test drives a real route or the real shared seam. `Http::fake()` is
 * active throughout, every credential is an obvious fixture string, and the
 * media tests clean up after themselves in `tearDown()` whether they pass or
 * fail.
 */
class LegacyWebhookSecurityCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    /** @var list<string> absolute paths this test created under public/mms */
    private array $createdMediaFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    protected function tearDown(): void
    {
        // Cleaned in tearDown so a failing assertion cannot leave a file
        // behind in the web root.
        foreach ($this->createdMediaFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->createdMediaFiles = [];

        parent::tearDown();
    }

    // =================================================================
    // 1. updateDLR — exact resolution, no wildcards, no repeatable refund
    // =================================================================

    private function twilioServer(string $authToken = 'fixture_twilio_auth_token'): SendingServer
    {
        return SendingServer::create([
            'name' => 'Legacy Twilio',
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
            'account_sid' => 'AC_FIXTURE_SID',
            'auth_token' => $authToken,
        ]);
    }

    /**
     * A report in the legacy packed shape: `"{status}|{provider message id}"`.
     */
    private function packedReport(User $owner, string $providerMessageId, ?SendingServer $server = null, int $cost = 5): Reports
    {
        return Reports::create([
            'user_id' => $owner->id,
            'to' => '14155550000',
            'message' => 'legacy',
            'sms_type' => 'plain',
            'status' => 'Delivered|' . $providerMessageId,
            'customer_status' => 'Delivered',
            'direction' => Reports::DIRECTION_OUTGOING,
            'cost' => $cost,
            'sms_count' => 1,
            'sending_server_id' => $server?->id,
        ]);
    }

    private function resolve(?string $providerMessageId, ?SendingServer $server = null): ?Reports
    {
        // The shared seam, exercised directly. Reflection because it is
        // private by design — it is an internal invariant, not an API.
        $method = new \ReflectionMethod(DLRController::class, 'resolveReportForProviderMessage');
        $method->setAccessible(true);

        return $method->invoke(null, $providerMessageId, $server);
    }

    public function test_an_exact_provider_message_id_resolves_to_its_own_report(): void
    {
        $owner = $this->createCustomer();
        $report = $this->packedReport($owner->user, 'SM_EXACT_001');

        $this->assertSame((int) $report->id, (int) $this->resolve('SM_EXACT_001')?->id);
    }

    public function test_a_sql_wildcard_cannot_escape_into_another_tenants_report(): void
    {
        $victim = $this->createCustomer();
        $this->packedReport($victim->user, 'SM_SECRET_VICTIM');

        // Every one of these matched under the old `LIKE %input%`.
        foreach (['%', '_', '%%', 'SM%', 'SM_', '%VICTIM%', 'SM\_SECRET\_VICTIM'] as $probe) {
            $this->assertNull(
                $this->resolve($probe),
                "The wildcard probe [{$probe}] must not resolve to anyone's report.",
            );
        }
    }

    public function test_a_partial_provider_message_id_resolves_to_nothing(): void
    {
        $owner = $this->createCustomer();
        $this->packedReport($owner->user, 'SM_FULL_IDENTIFIER_123');

        foreach (['SM_FULL', 'IDENTIFIER', '123', 'SM_FULL_IDENTIFIER_12'] as $partial) {
            $this->assertNull($this->resolve($partial), "[{$partial}] is not an exact id.");
        }
    }

    public function test_an_ambiguous_candidate_set_fails_closed(): void
    {
        // The same provider message id on two tenants' reports — a data
        // state the legacy schema permits, and one the old `->first()`
        // resolved by insertion order.
        $a = $this->createCustomer();
        $b = $this->createCustomer();

        $this->packedReport($a->user, 'SM_AMBIGUOUS');
        $this->packedReport($b->user, 'SM_AMBIGUOUS');

        $this->assertNull($this->resolve('SM_AMBIGUOUS'), 'Ambiguity must never be resolved by picking one.');
    }

    public function test_a_missing_candidate_fails_closed(): void
    {
        $this->assertNull($this->resolve('SM_NEVER_EXISTED'));
        $this->assertNull($this->resolve(''));
        $this->assertNull($this->resolve('   '));
        $this->assertNull($this->resolve(null));
    }

    public function test_resolution_is_scoped_to_the_sending_server_when_one_is_known(): void
    {
        $a = $this->createCustomer();
        $b = $this->createCustomer();

        $serverA = $this->twilioServer();
        $serverB = SendingServer::create([
            'name' => 'Other Twilio', 'settings' => SendingServer::TYPE_TWILIO,
            'status' => true, 'plain' => true, 'auth_token' => 'other_token',
        ]);

        $reportA = $this->packedReport($a->user, 'SM_SHARED_ID', $serverA);
        $this->packedReport($b->user, 'SM_SHARED_ID', $serverB);

        // Unscoped this is ambiguous and refused; scoped it is exact.
        $this->assertNull($this->resolve('SM_SHARED_ID'));
        $this->assertSame((int) $reportA->id, (int) $this->resolve('SM_SHARED_ID', $serverA)?->id);
    }

    public function test_a_repeated_failed_callback_credits_the_customer_exactly_once(): void
    {
        $owner = $this->createCustomer();
        $owner->user->sms_unit = 100;
        $owner->user->save();

        $report = $this->packedReport($owner->user, 'SM_REFUND_ONCE', null, cost: 7);

        // Ten identical failed callbacks — the old code credited ten times.
        for ($i = 0; $i < 10; $i++) {
            DLRController::updateDLR('SM_REFUND_ONCE', 'Undelivered');
        }

        $this->assertSame(107, (int) $owner->user->fresh()->sms_unit, 'Exactly one credit, however many callbacks.');

        // 'Undelivered' is not one of updateDLR()'s match arms — it accepts
        // 'UNDELIVERABLE'/'UNDELIV' — so it maps to the default, 'Failed'.
        // That is pre-existing behaviour this correction does not change,
        // and 'Failed' is a non-delivered terminal state either way, which
        // is what the refund guard keys on.
        $this->assertSame('Failed', $report->fresh()->customer_status);
    }

    public function test_transition_ordering_preserves_legacy_billing_semantics(): void
    {
        $owner = $this->createCustomer();
        $owner->user->sms_unit = 100;
        $owner->user->save();

        $this->packedReport($owner->user, 'SM_ORDERING', null, cost: 9);

        // Delivered first — no credit.
        DLRController::updateDLR('SM_ORDERING', 'Delivered');
        $this->assertSame(100, (int) $owner->user->fresh()->sms_unit);

        // Then failed — one credit.
        DLRController::updateDLR('SM_ORDERING', 'Failed');
        $this->assertSame(109, (int) $owner->user->fresh()->sms_unit);

        // Then a late delivery — the cost comes back, or the message was
        // free. Legacy semantics preserved rather than quietly changed.
        DLRController::updateDLR('SM_ORDERING', 'Delivered');
        $this->assertSame(100, (int) $owner->user->fresh()->sms_unit);

        // And a repeat of that delivery changes nothing again.
        DLRController::updateDLR('SM_ORDERING', 'Delivered');
        $this->assertSame(100, (int) $owner->user->fresh()->sms_unit);
    }

    public function test_an_unresolvable_callback_mutates_and_credits_nothing(): void
    {
        $owner = $this->createCustomer();
        $owner->user->sms_unit = 100;
        $owner->user->save();

        $report = $this->packedReport($owner->user, 'SM_UNTOUCHED', null, cost: 4);

        DLRController::updateDLR('%', 'Failed');
        DLRController::updateDLR('SM_UNTOUCH', 'Failed');
        DLRController::updateDLR('SM_NOT_A_REAL_ID', 'Failed');

        $this->assertSame(100, (int) $owner->user->fresh()->sms_unit, 'No credit for an unresolvable callback.');
        $this->assertSame('Delivered', $report->fresh()->customer_status, 'No mutation either.');
    }

    // =================================================================
    // 2 & 3. Solucoesdigitais and Textbelt forged inbound
    // =================================================================

    public function test_solucoesdigitais_cannot_forge_an_inbound_against_another_tenant(): void
    {
        $server = SendingServer::create([
            'name' => 'Legacy SD', 'settings' => SendingServer::TYPE_SOLUCOESDIGITAIS,
            'status' => true, 'plain' => true,
        ]);

        $victim = $this->createCustomer();
        $this->packedReport($victim->user, 'CAMPANHA_VICTIM_9001', $server);

        // A wildcard and a substring — both matched under the old LIKE.
        foreach (['%', 'VICTIM', '9001', 'CAMPANHA_VICTIM_900'] as $forged) {
            $this->post(route('inbound.solucoesdigitais'), [
                'id_campanha' => $forged,
                'sms_resposta' => 'forged inbound',
                'nro_telefone' => '14155559001',
            ]);
        }

        $this->assertSame(
            0,
            Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count(),
            'A forged id_campanha must never produce an inbound row.',
        );
        $this->assertSame(0, \App\Models\Blacklists::query()->count(), 'No STOP/blacklist side effect either.');
        $this->assertSame(0, \App\Models\ChatBox::query()->count());
    }

    public function test_solucoesdigitais_fails_closed_on_two_tenants_with_overlapping_ids(): void
    {
        $server = SendingServer::create([
            'name' => 'Legacy SD', 'settings' => SendingServer::TYPE_SOLUCOESDIGITAIS,
            'status' => true, 'plain' => true,
        ]);

        $a = $this->createCustomer();
        $b = $this->createCustomer();

        $this->packedReport($a->user, 'SHARED_CAMPAIGN', $server);
        $this->packedReport($b->user, 'SHARED_CAMPAIGN', $server);

        $this->post(route('inbound.solucoesdigitais'), [
            'id_campanha' => 'SHARED_CAMPAIGN',
            'sms_resposta' => 'ambiguous inbound',
            'nro_telefone' => '14155559002',
        ]);

        $this->assertSame(0, Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count());
    }

    public function test_textbelt_cannot_forge_an_inbound_against_another_tenant(): void
    {
        SendingServer::create([
            'name' => 'Legacy TextBelt', 'settings' => SendingServer::TYPE_TEXTBELT,
            'status' => true, 'plain' => true,
        ]);

        $victim = $this->createCustomer();
        $this->packedReport($victim->user, 'TEXTID_VICTIM_7');

        foreach (['%', '_', 'VICTIM', 'TEXTID_VICTIM'] as $forged) {
            $this->post(route('inbound.textbelt'), [
                'textId' => $forged,
                'fromNumber' => '14155559003',
                'text' => 'forged inbound',
            ]);
        }

        $this->assertSame(0, Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count());
        $this->assertSame(0, \App\Models\ChatBox::query()->count());
    }

    public function test_textbelt_fails_closed_on_two_tenants_with_overlapping_ids(): void
    {
        SendingServer::create([
            'name' => 'Legacy TextBelt', 'settings' => SendingServer::TYPE_TEXTBELT,
            'status' => true, 'plain' => true,
        ]);

        $a = $this->createCustomer();
        $b = $this->createCustomer();

        $this->packedReport($a->user, 'TEXTID_SHARED');
        $this->packedReport($b->user, 'TEXTID_SHARED');

        $this->post(route('inbound.textbelt'), [
            'textId' => 'TEXTID_SHARED',
            'fromNumber' => '14155559004',
            'text' => 'ambiguous inbound',
        ]);

        $this->assertSame(0, Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count());
    }

    // =================================================================
    // 4. Whatsender media — no traversal, no executable write
    // =================================================================

    private function storeMedia($payload): ?string
    {
        $method = new \ReflectionMethod(DLRController::class, 'storeInboundMediaSafely');
        $method->setAccessible(true);

        $stored = $method->invoke(null, $payload);

        if (is_string($stored)) {
            $this->createdMediaFiles[] = public_path('mms') . DIRECTORY_SEPARATOR . $stored;
        }

        return $stored;
    }

    public function test_a_valid_image_is_stored_under_a_generated_name(): void
    {
        // A real 1x1 GIF, so the content check has genuine bytes to read.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        $stored = $this->storeMedia($gif);

        $this->assertNotNull($stored);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.gif$/', $stored, 'The name is generated, not provider-supplied.');
        $this->assertFileExists(public_path('mms') . DIRECTORY_SEPARATOR . $stored);
    }

    public function test_executable_and_unknown_payloads_are_never_written(): void
    {
        $before = $this->mmsFileCount();

        foreach ([
            '<?php system($_GET["c"]); ?>',
            '#!/bin/sh' . "\n" . 'rm -rf /',
            '<html><script>alert(1)</script></html>',
            'MZ' . str_repeat("\0", 64),   // a Windows executable header
            '',
        ] as $payload) {
            $this->assertNull($this->storeMedia($payload), 'A non-allowlisted payload must write nothing.');
        }

        $this->assertSame($before, $this->mmsFileCount(), 'Nothing was written at all.');
    }

    public function test_a_traversing_provider_filename_cannot_influence_the_stored_path(): void
    {
        // The provider filename reaches nothing now, so the attack is stated
        // structurally: whatever name the provider claims, the stored name
        // is generated and lands inside public/mms.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        foreach ([
            '../../evil.php',
            '..\\..\\evil.php',
            '/etc/passwd',
            "evil\0.php",
            'evil.php',
        ] as $claimed) {
            $stored = $this->storeMedia($gif);

            $this->assertNotNull($stored);
            $this->assertStringNotContainsString('..', $stored);
            $this->assertStringNotContainsString('/', $stored);
            $this->assertStringNotContainsString('\\', $stored);
            $this->assertStringNotContainsString("\0", $stored);
            $this->assertStringEndsNotWith('.php', $stored);

            // And the resolved parent really is the intended directory.
            $path = public_path('mms') . DIRECTORY_SEPARATOR . $stored;
            $this->assertSame(realpath(public_path('mms')), realpath(dirname($path)));
        }

        // No file called anything like the claimed names exists anywhere.
        $this->assertFileDoesNotExist(public_path('mms') . DIRECTORY_SEPARATOR . 'evil.php');
        $this->assertFileDoesNotExist(public_path('evil.php'));
    }

    public function test_an_oversized_payload_is_refused(): void
    {
        $gifHeader = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $oversized = $gifHeader . str_repeat('A', 17 * 1024 * 1024);

        $this->assertNull($this->storeMedia($oversized), 'A payload past the byte bound writes nothing.');
    }

    private function mmsFileCount(): int
    {
        $dir = public_path('mms');

        return is_dir($dir) ? count(array_diff(scandir($dir), ['.', '..'])) : 0;
    }

    // =================================================================
    // 5. Legacy Telnyx outbound authenticity
    // =================================================================

    private function telnyxOutboundPayload(string $providerMessageId, string $status = 'delivered'): array
    {
        return [
            'data' => [
                'event_type' => 'message.finalized',
                'payload' => [
                    'id' => $providerMessageId,
                    'direction' => 'outbound',
                    'to' => [['phone_number' => '+14155559100', 'status' => $status]],
                ],
            ],
        ];
    }

    public function test_a_legacy_telnyx_outbound_callback_cannot_mutate_a_report_or_balance(): void
    {
        $server = SendingServer::create([
            'name' => 'Legacy Telnyx', 'settings' => SendingServer::TYPE_TELNYX,
            'status' => true, 'plain' => true, 'api_key' => 'fixture_telnyx_api_key',
        ]);

        $owner = $this->createCustomer();
        $owner->user->sms_unit = 100;
        $owner->user->save();

        $report = $this->packedReport($owner->user, 'TELNYX_OUTBOUND_1', $server, cost: 6);

        $response = $this->postJson(route('inbound.telnyx'), $this->telnyxOutboundPayload('TELNYX_OUTBOUND_1', 'delivery_failed'));

        $response->assertOk();
        $response->assertSee('Delivery callbacks are disabled for this connection');

        $this->assertSame('Delivered', $report->fresh()->customer_status, 'No Report mutation without authenticity.');
        $this->assertSame(100, (int) $owner->user->fresh()->sms_unit, 'No billing mutation either.');

        $this->assertTrue(
            MessagingWebhookRejection::query()
                ->where('reason', WebhookRejectionReason::InvalidSignature->value)
                ->exists(),
            'The refusal is recorded.',
        );
    }

    public function test_the_byo_and_admin_telnyx_outbound_branches_are_refused_alike(): void
    {
        // The inbound branch already refused a BYO connection. This proves
        // the outbound branch is not a way around that — and that an
        // admin/legacy connection gets no unauthenticated exception either.
        $server = SendingServer::create([
            'name' => 'Admin Telnyx', 'settings' => SendingServer::TYPE_TELNYX,
            'status' => true, 'plain' => true,
        ]);

        $owner = $this->createCustomer();
        $this->packedReport($owner->user, 'TELNYX_ADMIN_1', $server);

        $this->postJson(route('inbound.telnyx'), $this->telnyxOutboundPayload('TELNYX_ADMIN_1'))
            ->assertOk()
            ->assertSee('Delivery callbacks are disabled for this connection');

        $this->assertSame(
            'Delivered',
            Reports::query()->where('user_id', $owner->user_id)->first()->customer_status,
        );
    }

    // =================================================================
    // 6. Twilio sibling signature validation
    // =================================================================

    private function signedPost(string $url, array $params, string $authToken): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', $url, $params, [], [], [
            'HTTP_X-Twilio-Signature' => (new RequestValidator($authToken))->computeSignature($url, $params),
        ]);
    }

    public function test_twilio_copilot_refuses_a_missing_or_invalid_signature(): void
    {
        SendingServer::create([
            'name' => 'Copilot', 'settings' => SendingServer::TYPE_TWILIOCOPILOT,
            'status' => true, 'plain' => true, 'auth_token' => 'copilot_fixture_token',
        ]);

        $params = [
            'From' => '+14155559200',
            'To' => '+14155559201',
            'Body' => 'forged',
            'MessagingServiceSid' => 'MG_FIXTURE',
        ];

        // Missing.
        $this->post(route('inbound.twilio_copilot'), $params)->assertOk();

        // Invalid.
        $this->call('POST', route('inbound.twilio_copilot'), $params, [], [], [
            'HTTP_X-Twilio-Signature' => base64_encode('not the right signature'),
        ])->assertOk();

        $this->assertSame(0, Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count());
        $this->assertTrue(
            MessagingWebhookRejection::query()
                ->where('reason', WebhookRejectionReason::InvalidSignature->value)
                ->exists(),
        );
    }

    public function test_twilio_copilot_admits_a_valid_signature(): void
    {
        $token = 'copilot_fixture_token';
        SendingServer::create([
            'name' => 'Copilot', 'settings' => SendingServer::TYPE_TWILIOCOPILOT,
            'status' => true, 'plain' => true, 'auth_token' => $token,
        ]);

        $params = [
            'From' => '+14155559202',
            'To' => '+14155559203',
            'Body' => 'genuine',
            'MessagingServiceSid' => 'MG_FIXTURE',
        ];

        $this->signedPost(route('inbound.twilio_copilot'), $params, $token)->assertOk();

        // It got past the gate: the only refusal recorded is the deeper
        // attribution one, not an invalid signature.
        $this->assertFalse(
            MessagingWebhookRejection::query()
                ->where('reason', WebhookRejectionReason::InvalidSignature->value)
                ->exists(),
            'A correctly signed Copilot request must pass the signature gate.',
        );
    }

    public function test_the_user_scoped_webhook_route_requires_a_valid_twilio_signature(): void
    {
        $token = 'webhook_fixture_token';
        $this->twilioServer($token);

        $owner = $this->createCustomer();
        $owner->user->webhook_url = 'https://example.invalid/hook';
        $owner->user->save();

        $url = route('inbound.webhook', $owner->user->uid ?? $owner->user->id);
        $params = ['From' => '+14155559300', 'To' => '+14155559301', 'Body' => 'forged'];

        // Missing signature.
        $this->post($url, $params)->assertOk();

        // Invalid signature.
        $this->call('POST', $url, $params, [], [], [
            'HTTP_X-Twilio-Signature' => base64_encode('wrong'),
        ])->assertOk();

        $this->assertSame(
            0,
            Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count(),
            'A hardcoded provider name is not proof of authenticity.',
        );
        $this->assertTrue(
            MessagingWebhookRejection::query()
                ->where('reason', WebhookRejectionReason::InvalidSignature->value)
                ->exists(),
        );
    }

    public function test_a_foreign_user_substitution_on_the_webhook_route_writes_nothing(): void
    {
        $this->twilioServer();

        $victim = $this->createCustomer();
        $victim->user->webhook_url = 'https://example.invalid/victim';
        $victim->user->save();

        // No signature, and someone else's user in the path.
        $this->post(route('inbound.webhook', $victim->user->uid ?? $victim->user->id), [
            'From' => '+14155559400',
            'To' => '+14155559401',
            'Body' => 'substituted',
        ])->assertOk();

        $this->assertSame(0, Reports::query()->where('user_id', $victim->user_id)->where('direction', Reports::DIRECTION_INCOMING)->count());
    }

    public function test_the_canonical_twilio_inbound_path_is_not_weakened(): void
    {
        // The positive control for item 6: closing the siblings must not
        // have changed the door that already worked.
        $token = 'fixture_twilio_auth_token';
        $this->twilioServer($token);

        $params = [
            'From' => '+14155559500',
            'To' => '+14155559501',
            'Body' => 'genuine twilio',
            'NumMedia' => '0',
        ];

        $this->signedPost(route('inbound.twilio'), $params, $token)->assertOk();

        $this->assertFalse(
            MessagingWebhookRejection::query()
                ->where('reason', WebhookRejectionReason::InvalidSignature->value)
                ->exists(),
            'A correctly signed Twilio request must still pass.',
        );
    }

    // =================================================================
    // Security Correction 38 — managed operation ↔ Report Business integrity
    //
    // The managed strategy joins EXACTLY: (provider, provider_message_id)
    // → report_id. Finding that row is not the same as being allowed to act
    // on it. The operation and the Report it points at must belong to the
    // SAME Business, and both must actually have one.
    //
    // Correction 37's guard rejected only a MISMATCH between two non-null
    // values, so `operation.business_id = A` pointing at a Report carrying
    // no business_id passed. These are the regressions for that.
    //
    // Every Report below carries the MANAGED shape that
    // ManagedDispatchDelegate::recordLegacyReport() writes — a plain status,
    // never the legacy `"{status}|{id}"` packing, and a NULL
    // sending_server_id — so the legacy fallback cannot resolve it and mask
    // a managed failure. A null result here therefore means the managed
    // strategy REFUSED, not that some other path happened to miss.
    // =================================================================

    private function telnyxServer(): SendingServer
    {
        return SendingServer::create([
            'name' => 'Legacy Telnyx',
            'settings' => SendingServer::TYPE_TELNYX,
            'status' => true,
            'plain' => true,
        ]);
    }

    /**
     * @return array{0: int, 1: User} the Business id and the User who owns it
     */
    private function businessWithOwner(): array
    {
        $business = $this->makeBusiness();

        return [(int) $business->id, User::findOrFail($business->customer->user_id)];
    }

    /**
     * A Report in the managed shape: a Business, no packed status, and no
     * legacy sending server.
     */
    private function managedReport(?int $businessId, int $userId, int $cost = 6): Reports
    {
        return Reports::create([
            'user_id' => $userId,
            'business_id' => $businessId,
            'to' => '14155551234',
            'message' => 'managed',
            'sms_type' => 'plain',
            'status' => 'Sent',
            'customer_status' => 'Sent',
            'direction' => Reports::DIRECTION_OUTGOING,
            'cost' => $cost,
            'sms_count' => 1,
            'sending_server_id' => null,
        ]);
    }

    private function managedOperation(
        ?int $businessId,
        string $providerMessageId,
        ?int $reportId,
        string $provider = 'telnyx',
    ): void {
        DB::table('business_messaging_operations')->insert([
            'business_id' => $businessId,
            'transport_mode' => 'managed',
            'provider' => $provider,
            'direction' => 'outbound',
            'message_type' => 'sms',
            'operation_key' => uniqid('op_', true),
            'provider_message_id' => $providerMessageId,
            'report_id' => $reportId,
            'status' => MessagingOperationStatus::Accepted->value,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A. The legitimate case still works, end to end. */
    public function test_a_managed_callback_resolves_and_transitions_when_both_businesses_agree(): void
    {
        [$businessId, $owner] = $this->businessWithOwner();
        $owner->sms_unit = 100;
        $owner->save();

        $report = $this->managedReport($businessId, (int) $owner->id, cost: 6);
        $this->managedOperation($businessId, 'PM_AGREE_001', (int) $report->id);

        $server = $this->telnyxServer();

        // The seam resolves it…
        $this->assertSame((int) $report->id, (int) $this->resolve('PM_AGREE_001', $server)?->id);

        // …and the real delivery callback still does its whole job.
        DLRController::updateDLR('PM_AGREE_001', 'Failed', $server);

        $this->assertSame('Failed', $report->fresh()->customer_status);
        $this->assertSame(106, (int) $owner->fresh()->sms_unit, 'A genuine failure still credits its cost.');
    }

    /** B. A foreign Business must never be reachable. */
    public function test_a_managed_operation_cannot_reach_another_businesss_report(): void
    {
        [$businessA] = $this->businessWithOwner();
        [$businessB, $ownerB] = $this->businessWithOwner();

        $ownerB->sms_unit = 100;
        $ownerB->save();

        // The Report belongs to B; the operation claims A.
        $report = $this->managedReport($businessB, (int) $ownerB->id, cost: 6);
        $this->managedOperation($businessA, 'PM_FOREIGN_001', (int) $report->id);

        $server = $this->telnyxServer();

        $this->assertNull($this->resolve('PM_FOREIGN_001', $server), 'A foreign Business must never resolve.');

        DLRController::updateDLR('PM_FOREIGN_001', 'Failed', $server);

        $fresh = $report->fresh();
        $this->assertSame('Sent', $fresh->status, 'No mutation.');
        $this->assertSame('Sent', $fresh->customer_status, 'No transition.');
        $this->assertSame(100, (int) $ownerB->fresh()->sms_unit, 'No credit.');
    }

    /**
     * C. The regression Correction 37 was missing.
     *
     * operation.business_id = A, report.business_id = NULL. The old guard
     * required BOTH to be non-null before it would reject, so this passed.
     */
    public function test_a_managed_operation_cannot_reach_a_report_with_no_business(): void
    {
        [$businessId, $owner] = $this->businessWithOwner();
        $owner->sms_unit = 100;
        $owner->save();

        $report = $this->managedReport(null, (int) $owner->id, cost: 6);
        $this->managedOperation($businessId, 'PM_NULLBIZ_001', (int) $report->id);

        $server = $this->telnyxServer();

        $this->assertNull(
            $this->resolve('PM_NULLBIZ_001', $server),
            'A Report with no Business is an integrity failure, not a legacy-compatible state.',
        );

        DLRController::updateDLR('PM_NULLBIZ_001', 'Failed', $server);

        $fresh = $report->fresh();
        $this->assertSame('Sent', $fresh->status, 'No mutation.');
        $this->assertSame('Sent', $fresh->customer_status, 'No transition.');
        $this->assertSame(100, (int) $owner->fresh()->sms_unit, 'No credit.');
    }

    /**
     * D. A managed operation with no Business is refused by the SCHEMA.
     *
     * The non-null column is the real guarantee. It is asserted directly
     * from information_schema so the proof does not depend on the session's
     * SQL mode, and the schema is never weakened to manufacture the state.
     */
    public function test_the_schema_itself_refuses_a_managed_operation_with_no_business(): void
    {
        $column = DB::selectOne(
            'select IS_NULLABLE as is_nullable from information_schema.columns
             where table_schema = database() and table_name = ? and column_name = ?',
            ['business_messaging_operations', 'business_id'],
        );

        $this->assertNotNull($column, 'business_messaging_operations.business_id must exist.');
        $this->assertSame('NO', $column->is_nullable, 'Every managed operation is Business-owned.');

        [$businessId, $owner] = $this->businessWithOwner();
        $report = $this->managedReport($businessId, (int) $owner->id);

        $this->expectException(QueryException::class);

        $this->managedOperation(null, 'PM_NULLOP_001', (int) $report->id);
    }

    /**
     * D (defence in depth). The runtime guard does not lean on that column.
     *
     * This drives the guard directly rather than storing a row the database
     * refuses — no fake persisted state is invented for coverage.
     */
    public function test_the_runtime_guard_also_refuses_an_operation_carrying_no_business(): void
    {
        [$businessId, $owner] = $this->businessWithOwner();
        $report = $this->managedReport($businessId, (int) $owner->id);

        $method = new \ReflectionMethod(DLRController::class, 'integrityCheckedManagedReport');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(null, (object) [
            'report_id' => (int) $report->id,
            'business_id' => null,
        ]));
    }

    /** E. Without an authoritative connection the managed strategy is skipped. */
    public function test_a_managed_report_is_unreachable_without_an_authoritative_connection(): void
    {
        [$businessId, $owner] = $this->businessWithOwner();
        $owner->sms_unit = 100;
        $owner->save();

        $report = $this->managedReport($businessId, (int) $owner->id, cost: 6);
        $this->managedOperation($businessId, 'PM_UNSCOPED_001', (int) $report->id);

        // No SendingServer means no provider to scope by, so the managed
        // strategy is skipped entirely rather than guessed at.
        $this->assertNull($this->resolve('PM_UNSCOPED_001'));

        DLRController::updateDLR('PM_UNSCOPED_001', 'Failed');

        $this->assertSame('Sent', $report->fresh()->customer_status, 'No transition.');
        $this->assertSame(100, (int) $owner->fresh()->sms_unit, 'No credit.');
    }

    /**
     * F. The composite index in practice, at the resolution seam.
     *
     * MessagingSchemaInvariantsTest proves the INDEX permits one id under
     * two providers. This proves the resolver keeps those two apart.
     */
    public function test_one_provider_message_id_under_two_providers_stays_isolated(): void
    {
        [$businessA, $ownerA] = $this->businessWithOwner();
        [$businessB, $ownerB] = $this->businessWithOwner();

        $reportA = $this->managedReport($businessA, (int) $ownerA->id);
        $reportB = $this->managedReport($businessB, (int) $ownerB->id);

        $this->managedOperation($businessA, 'PM_COLLIDE', (int) $reportA->id, 'telnyx');
        $this->managedOperation($businessB, 'PM_COLLIDE', (int) $reportB->id, 'twilio');

        $telnyx = $this->telnyxServer();
        $twilio = $this->twilioServer();

        $this->assertSame((int) $reportA->id, (int) $this->resolve('PM_COLLIDE', $telnyx)?->id);
        $this->assertSame((int) $reportB->id, (int) $this->resolve('PM_COLLIDE', $twilio)?->id);
    }
}
