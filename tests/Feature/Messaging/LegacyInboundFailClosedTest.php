<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\WebhookRejectionReason;
use App\Models\Blacklists;
use App\Models\ChatBox;
use App\Models\CustomerBasedSendingServer;
use App\Models\MessagingWebhookRejection;
use App\Models\PhoneNumbers;
use App\Models\Reports;
use App\Models\SendingServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Customer Experience Slice 3 §4.6.5 — T-MSG-24, 25, 26, 27, 28.
 *
 * The legacy `inbound/*` surface, which predates managed messaging and still
 * carries live BYO traffic. Before this slice, an inbound message that
 * matched no assigned `phone_numbers` row was written anyway against
 * `inboundDLR()`'s `int $user_id = 1` default — silently handing one
 * tenant's message to whoever holds user 1. These tests pin the fix at the
 * real production entry points (§4.6.6), by real HTTP requests through the
 * real routes, never by calling the controller method directly.
 *
 * No live provider is contacted: `Http::fake()` is active throughout and
 * every credential below is an obvious fixture string.
 */
class LegacyInboundFailClosedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    /** A platform-owned number nobody has been assigned. */
    private const UNATTRIBUTABLE = '14155559999';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

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

    private function telnyxServer(): SendingServer
    {
        return SendingServer::create([
            'name' => 'Legacy Telnyx',
            'settings' => SendingServer::TYPE_TELNYX,
            'status' => true,
            'plain' => true,
            'api_key' => 'fixture_telnyx_api_key',
        ]);
    }

    /** The exact Telnyx inbound payload shape `inboundTelnyx()` parses. */
    private function telnyxInboundPayload(string $destination, string $origin = '+14155550001'): array
    {
        return [
            'data' => [
                'event_type' => 'message.received',
                'payload' => [
                    'id' => 'pm_legacy_fixture',
                    'direction' => 'inbound',
                    'text' => 'hello from the legacy path',
                    'from' => ['phone_number' => $origin],
                    'to' => [['phone_number' => $destination, 'status' => 'delivered']],
                ],
            ],
        ];
    }

    private function assertNothingWasAttributed(string $context): void
    {
        $this->assertSame(
            0,
            Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count(),
            "{$context}: an unattributable inbound message must write no Reports row.",
        );
        $this->assertSame(0, ChatBox::query()->count(), "{$context}: no ChatBox row either.");
        $this->assertSame(0, Blacklists::query()->count(), "{$context}: no STOP/blacklist entry either.");
    }

    // ---------------------------------------------------------------
    // T-MSG-24 — legacy Telnyx cannot default to user 1
    // ---------------------------------------------------------------

    public function test_the_legacy_telnyx_route_cannot_attribute_an_unknown_number_to_user_one(): void
    {
        $this->telnyxServer();

        // Deliberately present, and deliberately NOT matching: proving the
        // fix is "no attribution found" rather than "no rows exist at all".
        PhoneNumbers::create([
            'number' => '14155551111',
            'status' => 'assigned',
            'user_id' => $this->createCustomer()->user_id,
        ]);

        $response = $this->postJson(
            route('inbound.telnyx'),
            $this->telnyxInboundPayload('+' . self::UNATTRIBUTABLE),
        );

        $response->assertOk();
        $this->assertNothingWasAttributed('legacy Telnyx');

        $rejection = MessagingWebhookRejection::query()->first();
        $this->assertNotNull($rejection, 'The refusal must leave an auditable trace.');
        $this->assertSame(WebhookRejectionReason::UnknownMapping, $rejection->reason);
    }

    // ---------------------------------------------------------------
    // T-MSG-25 — legacy Twilio cannot default to user 1
    // ---------------------------------------------------------------

    public function test_the_legacy_twilio_route_cannot_attribute_an_unknown_number_to_user_one(): void
    {
        $authToken = 'fixture_twilio_auth_token';
        $this->twilioServer($authToken);

        $params = [
            'From' => '+14155550002',
            'To' => '+' . self::UNATTRIBUTABLE,
            'Body' => 'hello from the legacy path',
            'NumMedia' => '0',
        ];

        // A genuinely valid signature, so the request reaches inboundDLR()
        // and the assertion is about attribution, not about the new
        // signature gate rejecting it first.
        $this->postWithTwilioSignature($authToken, $params)->assertOk();

        $this->assertNothingWasAttributed('legacy Twilio');
    }

    // ---------------------------------------------------------------
    // T-MSG-26 — BYO Twilio inbound is securely verified
    // ---------------------------------------------------------------

    /**
     * The two halves are told apart by WHICH refusal each produces, which is
     * a sharper discrimination than "one wrote a row and one did not":
     *
     * - a validly signed request gets past the new gate and is refused
     *   deeper, by `inboundDLR()`'s attribution rules → `unknown_mapping`;
     * - an invalidly signed one never reaches `inboundDLR()` at all → only
     *   `invalid_signature`.
     *
     * Both use the same unattributable number on purpose. Asserting the
     * fully-attributed happy path end-to-end is not possible on any
     * migration-built database: `inboundDLR()`'s attributed branch issues
     * `UPDATE chat_boxes SET ... ai_replied = 0` (DLRController.php:620-625)
     * and no migration in the repository defines `chat_boxes.ai_replied`.
     * That statement is byte-identical on pristine `origin/main`, and the
     * column is absent from the pristine baseline database too — a
     * pre-existing defect, in a branch of `inboundDLR()` outside this
     * slice's allowlist. It is reported rather than worked around here.
     */
    public function test_a_valid_twilio_signature_passes_the_gate_and_an_invalid_one_never_reaches_it(): void
    {
        $authToken = 'fixture_twilio_auth_token';
        $this->twilioServer($authToken);

        $params = [
            'From' => '+14155550003',
            'To' => '+' . self::UNATTRIBUTABLE,
            'Body' => 'a genuinely signed inbound message',
            'NumMedia' => '0',
        ];

        // (a) Valid signature — the request is admitted, and the only
        //     refusal recorded is the one inboundDLR() itself makes.
        $this->postWithTwilioSignature($authToken, $params)->assertOk();

        $this->assertSame(
            [WebhookRejectionReason::UnknownMapping->value],
            MessagingWebhookRejection::query()->pluck('reason')->map(
                fn ($reason) => $reason instanceof WebhookRejectionReason ? $reason->value : $reason,
            )->all(),
            'A correctly signed request must pass the signature gate and be judged on attribution alone.',
        );

        MessagingWebhookRejection::query()->delete();

        // (b) Invalid signature — refused before inboundDLR() runs, so the
        //     attribution rules never get to speak.
        $this->call('POST', route('inbound.twilio'), $params, [], [], [
            'HTTP_X-Twilio-Signature' => base64_encode('this is not the right signature'),
        ])->assertOk();

        $reasons = MessagingWebhookRejection::query()->pluck('reason')->map(
            fn ($reason) => $reason instanceof WebhookRejectionReason ? $reason->value : $reason,
        )->all();

        $this->assertContains(WebhookRejectionReason::InvalidSignature->value, $reasons);
        $this->assertNotContains(
            WebhookRejectionReason::UnknownMapping->value,
            $reasons,
            'An unverifiable request must never reach inboundDLR().',
        );

        $this->assertSame(
            0,
            Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count(),
        );
    }

    public function test_a_missing_twilio_signature_header_is_refused_outright(): void
    {
        $this->twilioServer();

        $this->post(route('inbound.twilio'), [
            'From' => '+14155550004',
            'To' => '+' . self::UNATTRIBUTABLE,
            'Body' => 'unsigned',
            'NumMedia' => '0',
        ])->assertOk();

        $this->assertSame(0, Reports::query()->where('direction', Reports::DIRECTION_INCOMING)->count());
        $this->assertTrue(
            MessagingWebhookRejection::query()
                ->where('reason', WebhookRejectionReason::InvalidSignature->value)
                ->exists(),
        );
    }

    // ---------------------------------------------------------------
    // T-MSG-27 — BYO Telnyx inbound is disabled, fail-closed
    // ---------------------------------------------------------------

    public function test_byo_telnyx_inbound_writes_nothing_regardless_of_payload_and_records_the_disablement(): void
    {
        $server = $this->telnyxServer();
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        // What makes this connection Business-facing BYO: the customer-owned
        // assignment row. The link column is `sending_server`.
        CustomerBasedSendingServer::create([
            'user_id' => $customer->user_id,
            'business_id' => $business->id,
            'sending_server' => $server->id,
            'status' => true,
        ]);

        // Deliberately a payload that WOULD attribute successfully: the
        // disablement must not depend on the message being unattributable.
        PhoneNumbers::create([
            'number' => self::UNATTRIBUTABLE,
            'status' => 'assigned',
            'user_id' => $customer->user_id,
        ]);

        $response = $this->postJson(
            route('inbound.telnyx'),
            $this->telnyxInboundPayload('+' . self::UNATTRIBUTABLE),
        );

        $response->assertOk();
        $response->assertSee('Inbound processing is disabled for this connection');

        $this->assertNothingWasAttributed('BYO Telnyx');

        $rejection = MessagingWebhookRejection::query()->first();
        $this->assertNotNull($rejection, 'The disablement itself must be recorded.');
        $this->assertSame(WebhookRejectionReason::UnknownMapping, $rejection->reason);
    }

    public function test_a_legacy_telnyx_connection_with_no_business_link_is_not_caught_by_the_byo_gate(): void
    {
        // The gate is deliberately narrow: an admin/legacy Telnyx server
        // with no CustomerBasedSendingServer row keeps its pre-existing
        // behaviour. It still cannot fall back to user 1 (T-MSG-24), but it
        // is not refused up-front as a BYO connection either — proving the
        // two mechanisms are distinct and neither is doing the other's job.
        // The response body is the discriminator: the BYO gate returns its
        // own disablement string and nothing else does.
        $this->telnyxServer();

        $response = $this->postJson(
            route('inbound.telnyx'),
            $this->telnyxInboundPayload('+' . self::UNATTRIBUTABLE),
        );

        $response->assertOk();
        $response->assertDontSee('Inbound processing is disabled for this connection');
    }

    // ---------------------------------------------------------------
    // T-MSG-28 — the duplicate/dead Telnyx routes cannot bypass the
    // canonical handler
    // ---------------------------------------------------------------

    public function test_the_removed_duplicate_telnyx_routes_no_longer_resolve(): void
    {
        $telnyxRoutes = [];

        foreach (app('router')->getRoutes() as $route) {
            if (str_contains($route->uri(), 'telnyx')) {
                $telnyxRoutes[] = $route->uri();
            }
        }

        // routes/web.php's `/telnyx/webhook` line is gone outright.
        $this->assertNotContains('telnyx/webhook', $telnyxRoutes);
        $this->post('/telnyx/webhook', [])->assertNotFound();

        // routes/web.php's second line registered a SECOND route for
        // `POST /inbound/telnyx`, duplicating routes/public.php's canonical
        // `inbound.telnyx`. The path still resolves — it is the real,
        // supported legacy endpoint — but now to exactly one route, the
        // named canonical one, so nothing can reach inboundTelnyx() while
        // bypassing the middleware and gates that route carries.
        $matching = array_values(array_filter(
            $telnyxRoutes,
            fn (string $uri): bool => $uri === 'inbound/telnyx/{gateway?}' || $uri === 'inbound/telnyx',
        ));

        $this->assertSame(['inbound/telnyx/{gateway?}'], $matching);
        $this->assertSame(
            'inbound/telnyx/{gateway?}',
            app('router')->getRoutes()->getByName('inbound.telnyx')?->uri(),
        );
    }

    /**
     * Sign a form POST exactly the way Twilio does, using the fixture
     * auth_token, and send it to the real legacy route.
     */
    private function postWithTwilioSignature(string $authToken, array $params): \Illuminate\Testing\TestResponse
    {
        $url = route('inbound.twilio');
        $signature = (new RequestValidator($authToken))->computeSignature($url, $params);

        return $this->call('POST', $url, $params, [], [], [
            'HTTP_X-Twilio-Signature' => $signature,
        ]);
    }
}
