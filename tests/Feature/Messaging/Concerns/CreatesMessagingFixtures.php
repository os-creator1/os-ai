<?php

namespace Tests\Feature\Messaging\Concerns;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Library\Messaging\Contracts\MessagingProviderAdapter;
use App\Library\Messaging\FakeMessagingAdapter;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * Slice 3 §4.12 — shared fixtures for the messaging suite.
 *
 * The fake adapter is bound per-test in setUp(), mirroring
 * FakeAgencyProspectingMessageSender's established pattern, so no test ever
 * constructs the real Telnyx adapter unless it is explicitly testing that
 * adapter under Http::fake().
 */
trait CreatesMessagingFixtures
{
    use CreatesBusinessTestData;

    protected ?FakeMessagingAdapter $fakeAdapter = null;

    protected function bindFakeAdapter(): FakeMessagingAdapter
    {
        $this->fakeAdapter = new FakeMessagingAdapter();
        $this->app->instance(MessagingProviderAdapter::class, $this->fakeAdapter);

        // §4.4 — the platform kill switch is enforced by
        // ManagedMessageDispatcher itself, not only by the real adapter's
        // constructor, so binding an adapter is no longer enough on its own
        // to make managed dispatch run. Binding a managed adapter IS the
        // statement "managed messaging is on for this test", so the switch
        // is turned on here rather than repeated in every caller.
        //
        // A test that deliberately exercises the switch being OFF still
        // sets `messaging.managed_messaging_enabled` to false after calling
        // this, and that continues to win.
        config(['messaging.managed_messaging_enabled' => true]);

        return $this->fakeAdapter;
    }

    protected function makeBusiness(): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
    }

    /**
     * A Business with an active identity and one active primary number —
     * the ordinary managed setup.
     *
     * @return array{0: Business, 1: BusinessMessagingIdentity, 2: BusinessMessagingNumber}
     */
    protected function managedBusiness(
        ?string $phoneNumber = null,
        ?string $messagingProfileId = null,
        BusinessMessagingIdentityStatus $identityStatus = BusinessMessagingIdentityStatus::Active,
    ): array {
        $business = $this->makeBusiness();

        $identity = $this->attachIdentity($business, $messagingProfileId, $identityStatus);
        $number = $this->attachNumber($identity, $phoneNumber ?? $this->uniqueNumber(), true);

        return [$business, $identity, $number];
    }

    protected function attachIdentity(
        Business $business,
        ?string $messagingProfileId = null,
        BusinessMessagingIdentityStatus $status = BusinessMessagingIdentityStatus::Active,
    ): BusinessMessagingIdentity {
        $identity = new BusinessMessagingIdentity([
            'uid' => (string) Str::uuid(),
            'business_id' => (int) $business->id,
            'provider' => MessagingProvider::Telnyx->value,
            'status' => $status->value,
            'messaging_profile_id' => $messagingProfileId ?? ('mp_' . Str::random(14)),
            'activated_at' => now(),
        ]);
        $identity->save();

        return $identity;
    }

    protected function attachNumber(
        BusinessMessagingIdentity $identity,
        string $phoneNumber,
        bool $isPrimary = true,
        BusinessMessagingNumberStatus $status = BusinessMessagingNumberStatus::Active,
    ): BusinessMessagingNumber {
        $number = new BusinessMessagingNumber([
            'business_messaging_identity_id' => (int) $identity->id,
            'phone_number' => $phoneNumber,
            'status' => $status->value,
            'is_primary' => $isPrimary,
            'activated_at' => now(),
        ]);
        $number->save();

        return $number;
    }

    /** A distinct, valid North-American E.164 number per call. */
    protected function uniqueNumber(): string
    {
        static $sequence = 0;
        $sequence++;

        return sprintf('+1415555%04d', 1000 + $sequence);
    }

    /** Turn managed messaging on for a test that needs the real adapter. */
    protected function enableManagedMessaging(): void
    {
        config([
            'messaging.managed_messaging_enabled' => true,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'services.telnyx.webhook_public_key' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        ]);
    }
}
