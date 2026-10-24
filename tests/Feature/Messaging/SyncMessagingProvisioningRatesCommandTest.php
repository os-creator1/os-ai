<?php

namespace Tests\Feature\Messaging;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The once-per-deploy fee schedule: applies only owner-approved, fully specified rates. */
class SyncMessagingProvisioningRatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->admin = User::create(['first_name' => 'Owner', 'last_name' => 'Admin', 'email' => 'o' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
    }

    private function schedule(array $overrides = []): void
    {
        config(['messaging.rates' => array_merge([
            'currency' => 'USD', 'actor_user_id' => (string) $this->admin->id, 'approved_by' => 'Owner approval 2026-10',
            'number_purchase' => ['retail_micro' => '1000000', 'cost_micro' => '500000', 'unit' => 'per number'],
            'number_rental_renewal' => ['retail_micro' => '2000000', 'cost_micro' => '1000000', 'unit' => 'per month'],
            'ten_dlc_registration' => ['retail_micro' => null, 'cost_micro' => null, 'unit' => 'per registration'],
            'ten_dlc_campaign_renewal' => ['retail_micro' => null, 'cost_micro' => null, 'unit' => 'per month'],
        ], $overrides)]);
    }

    public function test_it_activates_only_fully_specified_fees_and_skips_the_rest(): void
    {
        $this->schedule();

        $this->artisan('messaging:sync-provisioning-rates')->expectsOutputToContain('ten_dlc_registration: not configured')->assertSuccessful();

        $this->assertSame(['messaging_number_purchase', 'messaging_number_rental_renewal'], DB::table('usage_meters')->orderBy('meter_key')->pluck('meter_key')->all());
        $this->assertSame(2, DB::table('usage_meters')->where('is_metered', 1)->count());
        $this->assertSame('2000000', (string) DB::table('business_usage_rates')->where('meter_key', 'messaging_number_rental_renewal')->value('retail_rate_micro'));
        $this->assertSame(0, DB::table('usage_meters')->where('meter_key', 'messaging_tollfree_verification')->count());
    }

    public function test_it_is_idempotent_and_a_changed_price_becomes_a_new_version(): void
    {
        $this->schedule();
        $this->artisan('messaging:sync-provisioning-rates')->assertSuccessful();
        $this->artisan('messaging:sync-provisioning-rates')->assertSuccessful();
        $this->assertSame(1, DB::table('business_usage_rates')->where('meter_key', 'messaging_number_purchase')->count());

        $this->schedule(['number_purchase' => ['retail_micro' => '1500000', 'cost_micro' => '500000', 'unit' => 'per number']]);
        $this->artisan('messaging:sync-provisioning-rates')->assertSuccessful();

        $this->assertSame(2, DB::table('business_usage_rates')->where('meter_key', 'messaging_number_purchase')->count());
        $activeId = DB::table('usage_meters')->where('meter_key', 'messaging_number_purchase')->value('active_rate_id');
        $this->assertSame('1500000', (string) DB::table('business_usage_rates')->where('id', $activeId)->value('retail_rate_micro'));
    }

    public function test_it_refuses_without_an_approver_or_an_admin_actor_and_a_dry_run_writes_nothing(): void
    {
        $this->schedule(['approved_by' => '']);
        $this->artisan('messaging:sync-provisioning-rates')->assertFailed();

        $this->schedule(['actor_user_id' => '999999']);
        $this->artisan('messaging:sync-provisioning-rates')->assertFailed();

        $this->schedule();
        $this->artisan('messaging:sync-provisioning-rates', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('usage_meters')->count());
    }

    public function test_a_zero_retail_price_is_refused_never_activated_as_free(): void
    {
        $this->schedule(['number_purchase' => ['retail_micro' => '0', 'cost_micro' => '0', 'unit' => 'per number']]);

        $this->artisan('messaging:sync-provisioning-rates')->assertFailed();

        $this->assertSame(0, DB::table('usage_meters')->where('meter_key', 'messaging_number_purchase')->count());
    }
}
