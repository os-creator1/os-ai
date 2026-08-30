<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 §15's genuine calendar-month construction, M1 contract §14 —
 * February (28/29 days), a 31-day month, a DST boundary, multi-month
 * dormancy in one step, and a timezone change affecting only the next
 * period.
 */
class UsageCalendarMonthRolloverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function business(string $timezone = 'America/New_York'): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['timezone' => $timezone]));
    }

    /**
     * A genuine, disposable actor User — usage_meters.updated_by_user_id
     * has no database FK, so UsageMeterRepository::create() enforces
     * actor existence at the application layer instead (RFC-005 Amendment
     * 1 §B).
     */
    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    /**
     * RFC-005 Amendment 1 Slice 2 CUTOVER §2's locked fixture sequence: a
     * genuine, disposable UsageMeter must exist before setActiveRate()
     * creates/activates a rate for it, and activateMetering() must flip
     * is_metered before reserve() will accept it.
     */
    private function activateRate(): void
    {
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => 'crm',
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Slice 2 cutover fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate(
            'crm', '1000000', '500000', 'per message',
            $currencyId, $actorId, 'Fixture.',
        );

        app(UsageWalletManager::class)->activateMetering('crm', $actorId, 'Fixture.');
    }

    /**
     * Forces a rollover by setting the wallet's own period_end_utc into
     * the past, then triggering reserve()'s own lazy rollover.
     */
    private function forceRollover(Business $business): void
    {
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'spend_period_end_utc' => now()->subYear(),
            'recharge_period_end_utc' => now()->subYear(),
            'available_balance_micro' => 10_000_000,
        ]);

        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
    }

    public function test_february_28_day_rollover(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-02-15 12:00:00', 'UTC'));

        $business = $this->business('UTC');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->forceRollover($business);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        $this->assertSame('2027-02', $wallet->spend_period_key);
        $this->assertSame('2027-02-01 00:00:00', Carbon::parse($wallet->spend_period_start_utc)->format('Y-m-d H:i:s'));
        $this->assertSame('2027-03-01 00:00:00', Carbon::parse($wallet->spend_period_end_utc)->format('Y-m-d H:i:s'));
    }

    public function test_february_leap_year_29_day_rollover(): void
    {
        Carbon::setTestNow(Carbon::parse('2028-02-10 12:00:00', 'UTC'));

        $business = $this->business('UTC');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->forceRollover($business);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        $this->assertSame('2028-02', $wallet->spend_period_key);
        $this->assertSame('2028-03-01 00:00:00', Carbon::parse($wallet->spend_period_end_utc)->format('Y-m-d H:i:s'));
    }

    public function test_thirty_one_day_month_rollover(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-20 12:00:00', 'UTC'));

        $business = $this->business('UTC');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->forceRollover($business);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        $this->assertSame('2027-01', $wallet->spend_period_key);
        $this->assertSame('2027-01-01 00:00:00', Carbon::parse($wallet->spend_period_start_utc)->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-01 00:00:00', Carbon::parse($wallet->spend_period_end_utc)->format('Y-m-d H:i:s'));
    }

    public function test_dst_spring_forward_boundary_in_business_timezone(): void
    {
        // America/New_York springs forward on 2026-03-08.
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', 'UTC'));

        $business = $this->business('America/New_York');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->forceRollover($business);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        $this->assertSame('2026-03', $wallet->spend_period_key);

        // Local March 1 00:00 America/New_York (still EST, UTC-5) is
        // 2026-03-01 05:00:00 UTC — genuine timezone-aware construction,
        // never a fixed-duration approximation that would ignore the DST
        // transition later in the same month.
        $this->assertSame('2026-03-01 05:00:00', Carbon::parse($wallet->spend_period_start_utc)->format('Y-m-d H:i:s'));

        // Local April 1 00:00 America/New_York (now EDT, UTC-4, since the
        // transition already passed by then) is 2026-04-01 04:00:00 UTC.
        $this->assertSame('2026-04-01 04:00:00', Carbon::parse($wallet->spend_period_end_utc)->format('Y-m-d H:i:s'));
    }

    public function test_dst_fall_back_boundary_in_business_timezone(): void
    {
        // America/New_York falls back on 2026-11-01 (02:00 EDT becomes
        // 01:00 EST — the local 1-2am hour occurs twice).
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));

        $business = $this->business('America/New_York');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->forceRollover($business);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        $this->assertSame('2026-11', $wallet->spend_period_key);

        // Local November 1 00:00 America/New_York is still EDT (UTC-4) —
        // the fall-back transition itself does not occur until 02:00 local
        // that same day (06:00 UTC) — genuine timezone-aware construction,
        // never a fixed-duration approximation that would apply the
        // post-transition EST offset (UTC-5) retroactively to this instant.
        $this->assertSame('2026-11-01 04:00:00', Carbon::parse($wallet->spend_period_start_utc)->format('Y-m-d H:i:s'));

        // Local December 1 00:00 America/New_York is EST (UTC-5), since the
        // fall-back transition has already passed by then.
        $this->assertSame('2026-12-01 05:00:00', Carbon::parse($wallet->spend_period_end_utc)->format('Y-m-d H:i:s'));
    }

    public function test_multi_month_dormancy_lands_in_current_month_in_one_step(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00', 'UTC'));
        $business = $this->business('UTC');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();

        // Wallet dormant since January; now it's July — 6 months later.
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'UTC'));
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'available_balance_micro' => 10_000_000,
        ]);
        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        $this->assertSame('2026-07', $wallet->spend_period_key);
        $this->assertSame(0, (int) $wallet->committed_spend_this_period_micro);
    }

    public function test_business_timezone_change_affects_only_the_next_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00', 'UTC'));
        $business = $this->business('UTC');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();

        $walletBefore = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $originalStart = $walletBefore->spend_period_start_utc;
        $originalEnd = $walletBefore->spend_period_end_utc;

        // Change the Business's own timezone mid-period.
        DB::table('businesses')->where('id', $business->id)->update(['timezone' => 'Asia/Tokyo']);

        // Still within the same (already-fixed-in-UTC) period — no
        // rollover triggers, so the already-fixed boundaries survive
        // unchanged despite the timezone change.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 10_000_000]);
        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        $walletAfter = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame($originalStart, $walletAfter->spend_period_start_utc);
        $this->assertSame($originalEnd, $walletAfter->spend_period_end_utc);
    }
}
