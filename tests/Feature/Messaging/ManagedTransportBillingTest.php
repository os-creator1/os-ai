<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Agency Outreach V1 contract §7 — managed SMS rides the canonical usage
 * architecture. The dispatcher reserves from the payer's wallet before the
 * provider is contacted, commits on the provider's confirmed acceptance and
 * releases otherwise. While the `messaging_transport` meter is unmetered or
 * unpriced the whole thing is a no-op (the Slice 3 contract, T-MSG-36).
 */
class ManagedTransportBillingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private const RATE_MICRO = 10_000; // $0.01 per segment

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->bindFakeAdapter();
    }

    public function test_an_unmetered_meter_leaves_sending_unbilled_and_unchanged(): void
    {
        [$business] = $this->managedBusiness();
        $this->fundWallet($business, 1_000_000);

        $result = $this->send($business, 'unmetered-1');

        $this->assertTrue($result->accepted);
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
        $this->assertSame('1000000', $this->balance($business));
    }

    public function test_a_priced_meter_debits_the_wallet_once_on_acceptance(): void
    {
        [$business] = $this->managedBusiness();
        $this->fundWallet($business, 1_000_000);
        $this->priceTransport();

        $result = $this->send($business, 'billed-1', '2');

        $this->assertTrue($result->accepted);
        $this->assertSame(1, $this->fakeAdapter->sentCount());
        $this->assertSame((string) (1_000_000 - 2 * self::RATE_MICRO), $this->balance($business));

        $reservation = DB::table('business_usage_reservations')->where('feature_key', PlatformFeature::MessagingTransport->value)->first();
        $this->assertNotNull($reservation);
        $this->assertSame('committed', (string) $reservation->status);
    }

    public function test_a_replayed_operation_key_neither_resends_nor_charges_twice(): void
    {
        [$business] = $this->managedBusiness();
        $this->fundWallet($business, 1_000_000);
        $this->priceTransport();

        $this->send($business, 'replay-1');
        $this->send($business, 'replay-1');

        $this->assertSame(1, $this->fakeAdapter->sentCount());
        $this->assertSame(1, DB::table('business_usage_reservations')->count());
        $this->assertSame((string) (1_000_000 - self::RATE_MICRO), $this->balance($business));
    }

    public function test_insufficient_balance_refuses_before_any_provider_contact_or_operation_row(): void
    {
        [$business] = $this->managedBusiness();
        $this->fundWallet($business, 0);
        $this->priceTransport();

        try {
            $this->send($business, 'broke-1');
            $this->fail('A refused reservation must stop the send.');
        } catch (MessagingInsufficientFundsException $e) {
            $this->assertSame('insufficient_balance', $e->denialReason);
        }

        $this->assertSame(0, $this->fakeAdapter->sentCount());
        $this->assertSame(0, DB::table(ManagedMessageDispatcher::TABLE)->where('operation_key', 'broke-1')->count());
        $this->assertSame('0', $this->balance($business));
    }

    public function test_a_provider_rejection_releases_the_hold_and_charges_nothing(): void
    {
        [$business] = $this->managedBusiness();
        $this->fundWallet($business, 1_000_000);
        $this->priceTransport();
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;

        $result = $this->send($business, 'rejected-1');

        $this->assertFalse($result->accepted);
        $this->assertSame('1000000', $this->balance($business));
        $this->assertSame(
            'released',
            (string) DB::table('business_usage_reservations')->where('feature_key', PlatformFeature::MessagingTransport->value)->value('status'),
        );
    }

    public function test_paused_paid_activity_refuses_and_each_business_pays_from_its_own_wallet(): void
    {
        [$a] = $this->managedBusiness();
        [$b] = $this->managedBusiness();
        $this->fundWallet($a, 1_000_000);
        $this->fundWallet($b, 1_000_000);
        $this->priceTransport();

        $this->send($a, 'iso-1');

        $this->assertSame((string) (1_000_000 - self::RATE_MICRO), $this->balance($a));
        $this->assertSame('1000000', $this->balance($b), 'Another Business is never charged.');

        DB::table('business_usage_wallets')->where('business_id', $b->id)->update(['paid_activity_paused_at' => now()]);

        $this->expectException(MessagingInsufficientFundsException::class);
        $this->send($b, 'iso-2');
    }

    private function send(Business $business, string $key, string $quantity = '1')
    {
        return app(ManagedMessageDispatcher::class)->dispatch($business, '+14155559400', 'billing fixture', $key, [], $quantity);
    }

    private function balance(Business $business): string
    {
        return (string) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro');
    }

    private function fundWallet(Business $business, int $micro): void
    {
        $currency = Currency::query()->first()
            ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $manager = app(UsageWalletManager::class);

        if (! DB::table('business_usage_wallets')->where('business_id', $business->id)->exists()) {
            $manager->initializeWalletForNewBusiness($business->id);
        }

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'available_balance_micro' => $micro,
            'refundable_paid_available_micro' => $micro,
        ]);
    }

    /** What the platform owner does with the existing rate tooling: a meter, a rate, then metering on. */
    private function priceTransport(): void
    {
        $key = PlatformFeature::MessagingTransport->value;
        $currencyId = Currency::query()->first()->id;
        $actorId = User::create([
            'first_name' => 'Test', 'last_name' => 'Actor', 'email' => 'actor' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;

        $manager = app(UsageWalletManager::class);
        app(UsageMeterRepository::class)->create([
            'meter_key' => $key, 'feature_key' => $key, 'business_id' => null,
            'currency_id' => $currencyId, 'description' => 'Managed messaging transport (per segment).', 'updated_by_user_id' => $actorId,
        ]);
        $manager->setActiveRate($key, (string) self::RATE_MICRO, '5000', 'per segment', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($key, $actorId, 'Fixture.');
    }
}
