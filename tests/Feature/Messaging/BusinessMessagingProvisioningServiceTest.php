<?php

namespace Tests\Feature\Messaging;

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
        config(['messaging.managed_messaging_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);

        return $fake;
    }

    private function service(): BusinessMessagingProvisioningService
    {
        return new BusinessMessagingProvisioningService(app(BusinessMessagingIdentityResolver::class));
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
}
