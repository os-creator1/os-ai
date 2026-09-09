<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\MessageDispatchStatus;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\E164Normalizer;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\ManagedMessageDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 §4.5 — T-MSG-3, 8, 11, 12, 13, 15, 34, 41, 42.
 *
 * Outbound isolation, resolution and the measurement seam, exercised through
 * the real dispatcher and the fake adapter.
 */
class OutboundIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeAdapter();
    }

    private function dispatcher(): ManagedMessageDispatcher
    {
        return app(ManagedMessageDispatcher::class);
    }

    // ---------------------------------------------------------------
    // T-MSG-8 — exact E.164 normalization
    // ---------------------------------------------------------------

    public function test_equivalent_input_formats_normalize_to_one_canonical_value(): void
    {
        $equivalents = [
            '+1 (415) 555-2671',
            '14155552671',
            '+14155552671',
            '1-415-555-2671',
            "  +1 415 555 2671\t",
        ];

        foreach ($equivalents as $input) {
            $this->assertSame('+14155552671', E164Normalizer::normalize($input), "Failed for [{$input}].");
        }

        // A number without an explicit calling code is refused, never guessed.
        foreach (['5552671', '', '   ', 'not-a-number', '+', null] as $unusable) {
            $this->assertNull(E164Normalizer::normalize($unusable));
        }
    }

    public function test_normalization_happens_before_the_uniqueness_check(): void
    {
        [, $identity] = $this->managedBusiness('+14155553001');
        $resolver = app(BusinessMessagingIdentityResolver::class);

        // Attaching the same number in a different format must collide,
        // proving normalization precedes the constraint.
        $this->expectException(MessagingIdentityConflictException::class);
        $resolver->attachNumber($identity, '1 (415) 555-3001');
    }

    // ---------------------------------------------------------------
    // T-MSG-3 / T-MSG-13 — non-active identities resolve to nothing
    // ---------------------------------------------------------------

    public function test_each_non_active_identity_status_resolves_to_null_and_calls_no_provider(): void
    {
        $resolver = app(BusinessMessagingIdentityResolver::class);

        foreach ([
            BusinessMessagingIdentityStatus::Pending,
            BusinessMessagingIdentityStatus::Suspended,
            BusinessMessagingIdentityStatus::Archived,
        ] as $status) {
            $business = $this->makeBusiness();
            $identity = $this->attachIdentity($business, null, $status);
            $this->attachNumber($identity, $this->uniqueNumber());

            $this->assertNull(
                $resolver->resolveForBusiness($business),
                "Status [{$status->value}] must not resolve.",
            );

            try {
                $this->dispatcher()->dispatch($business, '+14155559000', 'hello', 'op_' . Str::random(8));
                $this->fail("Dispatch must fail closed for status [{$status->value}].");
            } catch (MessagingIdentityConflictException) {
                // expected
            }
        }

        $this->assertSame(0, $this->fakeAdapter->sentCount(), 'No provider call may be made for a non-active identity.');
    }

    public function test_a_business_with_no_identity_at_all_makes_no_provider_call(): void
    {
        $business = $this->makeBusiness();

        $this->expectException(MessagingIdentityConflictException::class);

        try {
            $this->dispatcher()->dispatch($business, '+14155559000', 'hello', 'op_none');
        } finally {
            $this->assertSame(0, $this->fakeAdapter->sentCount());
        }
    }

    // ---------------------------------------------------------------
    // §4.5 step 3 — no "first number" fallback
    // ---------------------------------------------------------------

    public function test_zero_active_primary_numbers_fails_closed_with_no_fallback(): void
    {
        $business = $this->makeBusiness();
        $identity = $this->attachIdentity($business);
        // Two active numbers, neither marked primary.
        $this->attachNumber($identity, $this->uniqueNumber(), false);
        $this->attachNumber($identity, $this->uniqueNumber(), false);

        $this->expectException(MessagingIdentityConflictException::class);

        try {
            $this->dispatcher()->dispatch($business, '+14155559001', 'hello', 'op_no_primary');
        } finally {
            $this->assertSame(0, $this->fakeAdapter->sentCount(), 'Never fall back to whichever number sorts first.');
        }
    }

    public function test_a_suspended_primary_number_is_not_usable(): void
    {
        $business = $this->makeBusiness();
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Suspended);

        $this->expectException(MessagingIdentityConflictException::class);

        try {
            $this->dispatcher()->dispatch($business, '+14155559002', 'hello', 'op_suspended');
        } finally {
            $this->assertSame(0, $this->fakeAdapter->sentCount());
        }
    }

    // ---------------------------------------------------------------
    // T-MSG-11 / T-MSG-12 — cross-Business isolation
    // ---------------------------------------------------------------

    public function test_a_send_always_uses_the_sending_businesss_own_identity_and_number(): void
    {
        [$businessA, $identityA, $numberA] = $this->managedBusiness();
        [$businessB, $identityB, $numberB] = $this->managedBusiness();

        $this->dispatcher()->dispatch($businessA, '+14155559100', 'from A', 'op_a');
        $this->dispatcher()->dispatch($businessB, '+14155559100', 'from B', 'op_b');

        $this->assertSame(2, $this->fakeAdapter->sentCount());

        [$requestA, $requestB] = $this->fakeAdapter->sentRequests;

        $this->assertSame((int) $identityA->id, $requestA->businessMessagingIdentityId);
        $this->assertSame((int) $numberA->id, $requestA->businessMessagingNumberId);
        $this->assertSame($numberA->phone_number, $requestA->fromNumber);

        $this->assertSame((int) $identityB->id, $requestB->businessMessagingIdentityId);
        $this->assertSame((int) $numberB->id, $requestB->businessMessagingNumberId);
        $this->assertSame($numberB->phone_number, $requestB->fromNumber);

        // Neither send may carry the other Business's profile.
        $this->assertNotSame($requestA->messagingProfileId, $requestB->messagingProfileId);
    }

    public function test_the_dispatcher_exposes_no_way_to_supply_an_identity_or_number(): void
    {
        // T-MSG-12 structurally: dispatch() takes no identity/number
        // parameter at all, so no request input can reach that decision.
        $parameters = array_map(
            fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(ManagedMessageDispatcher::class, 'dispatch'))->getParameters(),
        );

        $this->assertSame(
            ['business', 'toNumber', 'body', 'operationKey', 'mediaUrls', 'quantity'],
            $parameters,
        );

        foreach ($parameters as $parameter) {
            $this->assertDoesNotMatchRegularExpression('/identity|number_id|profile/i', $parameter);
        }
    }

    // ---------------------------------------------------------------
    // T-MSG-15 / T-MSG-34 — dispatch records exactly one of each row
    // ---------------------------------------------------------------

    public function test_a_managed_send_writes_one_operation_row_and_one_measurement_row(): void
    {
        [$business] = $this->managedBusiness();

        $result = $this->dispatcher()->dispatch($business, '+14155559200', 'hello', 'op_single');

        $this->assertTrue($result->accepted);
        $this->assertSame(MessageDispatchStatus::Accepted, $result->status);
        $this->assertNotNull($result->providerMessageId);

        $this->assertSame(1, DB::table('business_messaging_operations')
            ->where('business_id', $business->id)->count());
        $this->assertSame(1, DB::table('business_usage_measurements')
            ->where('business_id', $business->id)->count());

        $operation = DB::table('business_messaging_operations')->where('operation_key', 'op_single')->first();
        $this->assertSame('accepted', $operation->status);
        $this->assertSame('outbound', $operation->direction);
        $this->assertSame('managed', $operation->transport_mode);
        $this->assertSame($result->providerMessageId, $operation->provider_message_id);

        $measurement = DB::table('business_usage_measurements')->where('idempotency_key', 'op_single')->first();
        $this->assertSame('messaging_transport', $measurement->feature_key);
        $this->assertSame('segment', $measurement->unit);
        $this->assertSame('managed', $measurement->transport_marker);

        // No RFC-005 accounting machinery was touched: no reservation, no
        // rate, no rate activation, no ledger entry.
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
        $this->assertSame(0, DB::table('business_usage_rates')->count());
        $this->assertSame(0, DB::table('business_usage_rate_activations')->count());

        // Contract discrepancy, recorded rather than hidden (see this
        // branch's report): §4.8/T-MSG-36 states that
        // platform_feature_usage_classifications must carry "no row at all"
        // for MessagingTransport. That is mechanically unachievable while
        // the case exists, because the already-merged migration
        // 2026_08_16_120008_backfill_platform_feature_usage_classifications
        // inserts one row per PlatformFeature case and THROWS if any case
        // lacks one — and editing merged migration history is forbidden.
        //
        // What the requirement actually protects — that Slice 3 activates no
        // metering and no retail rate — is asserted exactly here: the row is
        // unmetered with no active rate, identical in shape to every other
        // unpriced feature.
        $classification = DB::table('platform_feature_usage_classifications')
            ->where('feature_key', 'messaging_transport')
            ->first();

        $this->assertNotNull($classification, 'The merged backfill migration classifies every PlatformFeature case.');
        $this->assertSame(0, (int) $classification->is_metered, 'Slice 3 must never make this feature metered.');
        $this->assertNull($classification->active_rate_id, 'Slice 3 must never activate a retail rate.');
    }

    public function test_a_repeated_operation_key_never_sends_twice(): void
    {
        [$business] = $this->managedBusiness();

        $first = $this->dispatcher()->dispatch($business, '+14155559300', 'hello', 'op_repeat');
        $second = $this->dispatcher()->dispatch($business, '+14155559300', 'hello', 'op_repeat');

        $this->assertSame(1, $this->fakeAdapter->sentCount(), 'A confirmed acceptance must never be re-sent.');
        $this->assertSame($first->providerMessageId, $second->providerMessageId);
        $this->assertSame(1, DB::table('business_messaging_operations')->where('operation_key', 'op_repeat')->count());
        $this->assertSame(1, DB::table('business_usage_measurements')->where('idempotency_key', 'op_repeat')->count());
    }

    // ---------------------------------------------------------------
    // T-MSG-42 — an ambiguous outcome is never Accepted
    // ---------------------------------------------------------------

    public function test_a_provider_rejection_is_recorded_as_rejected_not_accepted(): void
    {
        [$business] = $this->managedBusiness();
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Retryable;

        $result = $this->dispatcher()->dispatch($business, '+14155559400', 'hello', 'op_reject');

        $this->assertFalse($result->accepted);
        $this->assertSame(MessageDispatchStatus::Rejected, $result->status);
        $this->assertSame(ProviderErrorCategory::Retryable, $result->errorCategory);

        $operation = DB::table('business_messaging_operations')->where('operation_key', 'op_reject')->first();
        $this->assertSame('rejected', $operation->status);
        $this->assertSame('retryable', $operation->error_category);
        $this->assertNull($operation->provider_message_id);
    }

    // ---------------------------------------------------------------
    // T-MSG-41 — no credential-shaped substring escapes
    // ---------------------------------------------------------------

    public function test_no_result_or_exception_carries_a_credential_shaped_value(): void
    {
        [$business] = $this->managedBusiness();
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Configuration;

        $result = $this->dispatcher()->dispatch($business, '+14155559500', 'hello', 'op_secret');
        $serialized = json_encode($result);

        foreach (['api_key', 'secret', 'token', 'password', 'Bearer'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, (string) $serialized);
        }

        try {
            $this->dispatcher()->dispatch($this->makeBusiness(), '+14155559501', 'hello', 'op_secret_2');
        } catch (\Throwable $e) {
            foreach (['api_key', 'secret', 'password', 'Bearer'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $e->getMessage());
            }
        }
    }

    public function test_an_unusable_destination_number_fails_before_the_provider(): void
    {
        [$business] = $this->managedBusiness();

        $this->expectException(MessagingIdentityConflictException::class);

        try {
            $this->dispatcher()->dispatch($business, '5552671', 'hello', 'op_bad_dest');
        } finally {
            $this->assertSame(0, $this->fakeAdapter->sentCount());
        }
    }

    public function test_the_fake_adapter_records_calls_and_returns_deterministically(): void
    {
        [$business] = $this->managedBusiness();

        $first = $this->dispatcher()->dispatch($business, '+14155559600', 'one', 'op_det_1');
        $second = $this->dispatcher()->dispatch($business, '+14155559601', 'two', 'op_det_2');

        $this->assertSame('fake_msg_000001', $first->providerMessageId);
        $this->assertSame('fake_msg_000002', $second->providerMessageId);
        $this->assertSame(2, $this->fakeAdapter->sentCount());
        $this->assertSame('one', $this->fakeAdapter->sentRequests[0]->body);
        $this->assertSame('two', $this->fakeAdapter->sentRequests[1]->body);
    }
}
