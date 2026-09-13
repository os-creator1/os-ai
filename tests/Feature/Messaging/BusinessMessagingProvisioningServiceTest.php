<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\BusinessMessagingProvisioningService;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingProvisioningIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Text messaging setup/number/compliance hub — STATE 1's provisioning
 * orchestration: search returns whatever the adapter genuinely returned,
 * order only ever persists a number after a real provider round trip, and
 * a Business already carrying a number can never provision a second one.
 */
class BusinessMessagingProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private function bindFakeProvisioningAdapter(): FakeProvisioningAdapter
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_key',
        ]);

        return $fake;
    }

    private function service(): BusinessMessagingProvisioningService
    {
        return app(BusinessMessagingProvisioningService::class);
    }

    // -----------------------------------------------------------------
    // Availability / preview.
    // -----------------------------------------------------------------

    public function test_provisioning_is_unavailable_when_the_platform_switch_is_off(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);

        $this->assertFalse($this->service()->isAvailable());
    }

    public function test_provisioning_is_unavailable_when_no_api_key_is_configured(): void
    {
        config(['messaging.managed_messaging_enabled' => true, 'services.telnyx.api_key' => '']);

        $this->assertFalse($this->service()->isAvailable());
    }

    public function test_provisioning_is_available_once_both_gates_are_set(): void
    {
        $this->bindFakeProvisioningAdapter();

        $this->assertTrue($this->service()->isAvailable());
    }

    public function test_searching_when_not_configured_returns_an_empty_list_never_a_guess(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);

        $results = $this->service()->searchNumbers(new NumberSearchCriteria('US', PhoneNumberType::Local));

        $this->assertSame([], $results);
    }

    // -----------------------------------------------------------------
    // Search — a pure pass-through to the adapter.
    // -----------------------------------------------------------------

    public function test_search_returns_exactly_what_the_adapter_returns(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $candidate = new AvailableNumberCandidate('+14155550100', PhoneNumberType::Local, 'candidate-ref-1');
        $fake->queueSearchResult($candidate);

        $criteria = new NumberSearchCriteria('US', PhoneNumberType::Local, '415');
        $results = $this->service()->searchNumbers($criteria);

        $this->assertSame([$candidate], $results);
        $this->assertSame([$criteria], $fake->searches, 'The exact criteria reached the adapter.');
    }

    // -----------------------------------------------------------------
    // Order — never a fake success; only a real round trip persists.
    // -----------------------------------------------------------------

    public function test_provisioning_a_number_creates_an_identity_and_an_active_primary_number(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $candidate = new AvailableNumberCandidate('+14155550101', PhoneNumberType::Local, 'candidate-ref-2');

        $number = $this->service()->provisionNumber($business, $candidate);

        $this->assertInstanceOf(BusinessMessagingNumber::class, $number);
        $this->assertSame('+14155550101', $number->phone_number);
        $this->assertTrue($number->is_primary);
        $this->assertTrue($number->isActive());
        $this->assertSame(PhoneNumberType::Local, $number->number_type);

        $identity = app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business);
        $this->assertNotNull($identity, 'A new managed identity must exist for this Business.');
        $this->assertNotNull($identity->messaging_profile_id);
    }

    public function test_provisioning_a_toll_free_number_records_its_number_type(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $candidate = new AvailableNumberCandidate('+18005550100', PhoneNumberType::TollFree, 'candidate-ref-3');

        $number = $this->service()->provisionNumber($business, $candidate);

        $this->assertSame(PhoneNumberType::TollFree, $number->number_type);
    }

    public function test_a_business_that_already_has_an_active_identity_cannot_provision_a_second_number(): void
    {
        $this->bindFakeProvisioningAdapter();
        [$business] = $this->managedBusiness();

        $this->expectException(MessagingIdentityConflictException::class);

        $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550102', PhoneNumberType::Local, 'candidate-ref-4'));
    }

    public function test_provisioning_when_not_configured_throws_and_writes_nothing(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);
        $business = $this->makeBusiness();

        try {
            $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550103', PhoneNumberType::Local, 'candidate-ref-5'));
            $this->fail('Expected MessagingProviderNotConfiguredException.');
        } catch (MessagingProviderNotConfiguredException) {
            // expected
        }

        $this->assertNull(app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business));
        $this->assertDatabaseMissing('business_messaging_numbers', ['phone_number' => '+14155550103']);
    }

    public function test_provisioning_writes_the_identity_and_its_number_together(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550104', PhoneNumberType::Local, 'candidate-ref-6'));

        $this->assertDatabaseHas('business_messaging_identities', ['business_id' => $business->id]);
        $this->assertDatabaseHas('business_messaging_numbers', ['id' => $number->id, 'business_messaging_identity_id' => $number->business_messaging_identity_id]);
    }

    // -----------------------------------------------------------------
    // PR #295 Correction Round 1, item 3 — the identity slot is reserved
    // BEFORE any provider call, so a concurrent/stale second attempt can
    // never reach the adapter and can never cause a second paid
    // commitment.
    // -----------------------------------------------------------------

    public function test_a_pending_reservation_from_a_concurrent_attempt_blocks_a_second_attempt_before_any_provider_call(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        // Simulates a first, still-in-flight request having already won
        // the reservation (the exact row provisionNumber() itself would
        // create before calling the adapter).
        app(BusinessMessagingIdentityResolver::class)->create(
            $business,
            'reserved:concurrent-attempt',
            null,
            MessagingProvider::Telnyx,
            BusinessMessagingIdentityStatus::Pending,
        );

        $this->expectException(MessagingIdentityConflictException::class);

        try {
            $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550110', PhoneNumberType::Local, 'candidate-ref-7'));
        } finally {
            $this->assertSame([], $fake->provisionedOrders, 'The second, conflicting attempt must never reach the provider.');
        }
    }

    public function test_a_provider_failure_frees_the_reservation_for_another_attempt(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        $this->app->bind(MessagingProvisioningAdapter::class, fn () => new class extends FakeProvisioningAdapter {
            public function provisionNumber(\App\Models\Business $business, AvailableNumberCandidate $candidate): \App\Library\Messaging\DTO\ProvisionedNumberResult
            {
                throw new \RuntimeException('Simulated provider failure.');
            }
        });

        try {
            $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550111', PhoneNumberType::Local, 'candidate-ref-8'));
            $this->fail('Expected the simulated provider failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated provider failure.', $e->getMessage());
        }

        // The failed reservation must not leave the Business permanently
        // stuck — a fresh attempt is possible.
        $this->assertNull(app(BusinessMessagingIdentityResolver::class)->resolveForBusiness($business));
        $this->assertDatabaseCount('business_messaging_identities', 0);

        $this->bindFakeProvisioningAdapter();
        $number = $this->service()->provisionNumber($business, new AvailableNumberCandidate('+14155550112', PhoneNumberType::Local, 'candidate-ref-9'));
        $this->assertInstanceOf(BusinessMessagingNumber::class, $number);
    }

    public function test_a_local_finalization_failure_after_provider_success_is_recorded_never_lost(): void
    {
        $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        // A phone number already claimed by another active mapping makes
        // attachNumber() throw AFTER the (fake) provider has already
        // "succeeded" — exactly the partial-failure window item 3 requires
        // reconciliation state for.
        [$otherBusiness] = $this->managedBusiness('+14155550113');

        $number = new AvailableNumberCandidate('+14155550113', PhoneNumberType::Local, 'candidate-ref-10');

        try {
            $this->service()->provisionNumber($business, $number);
            $this->fail('Expected a MessagingIdentityConflictException from the claimed number.');
        } catch (MessagingIdentityConflictException) {
            // expected
        }

        // The reservation identity itself survives with the real provider
        // profile id — it is not lost.
        $identity = \App\Models\BusinessMessagingIdentity::query()->where('business_id', $business->id)->first();
        $this->assertNotNull($identity);
        $this->assertStringStartsNotWith('reserved:', $identity->messaging_profile_id);

        // And an incident row exists recording exactly what the provider
        // returned, so the phone number itself is never invisible either.
        $this->assertDatabaseHas('business_messaging_provisioning_incidents', [
            'business_id' => $business->id,
            'stage' => 'number_attach_failed_after_provider_success',
            'phone_number' => '+14155550113',
        ]);
        $this->assertSame(1, BusinessMessagingProvisioningIncident::where('business_id', $business->id)->count());
    }
}
