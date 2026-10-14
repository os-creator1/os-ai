<?php

namespace Tests\Browser\AskMotionGrove;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Browser-acceptance seeder for Ask MotionGrove. NOT part of the phpunit suites (tests/Browser is not listed in
 * phpunit.xml): it deliberately COMMITS rows, so run it only against the disposable test database, by path:
 *
 *   vendor/bin/phpunit tests/Browser/AskMotionGrove/SeedBrowserFixtures.php
 *
 * It writes the ids of a Launch tenant (new business on a trial), a Growth tenant (running business with data) and an
 * exhausted-trial tenant to qa.json, next to this file, for browser.js.
 */
class SeedBrowserFixtures extends TestCase
{
    use CreatesDashboardFixtures;

    public function test_seed(): void
    {
        $this->freezeClockOff();
        config(['opportunity.enabled' => true]);
        $out = [];

        // Launch: a brand-new business on a trial.
        [$c1, $b1, $w1] = $this->bareTenant(WorkspacePlanTier::Growth, 'Launch Studio', 'Launch Account');
        DB::table('workspace_plan_assignments')->where('workspace_id', $w1->id)->update(['trial_ends_at' => now()->addDays(12), 'is_complimentary' => false]);
        // Launch has its Location (so a booking type can be prepared) but no booking type yet.
        \App\Models\BusinessLocation::create(['business_id' => $b1->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $out['launch'] = $this->row($c1, $b1, $w1);

        // Growth: running business with real data.
        [$c2, $b2, $w2] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Studio', 'Growth Account');
        $this->contactsAt($b2, 6, now()->subDays(5)->format('Y-m-d H:i:s'));
        $this->contactsAt($b2, 3, now()->subDays(40)->format('Y-m-d H:i:s'));
        $this->conversationWith($b2, [['incoming', now()->subMinutes(50)->format('Y-m-d H:i:s')]]);
        $this->website($b2, 'draft');
        $this->recommendation($b2, ['type' => 'missing_phone', 'priority_score' => 70]);
        $loc = (int) DB::table('business_locations')->where('business_id', $b2->id)->value('id');
        DB::table('contacts')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'customer_id' => $b2->customer_id, 'business_id' => $b2->id,
            'location_id' => $loc, 'group_id' => $this->contactGroup($b2)->id, 'phone' => '15557770000', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $out['growth'] = $this->row($c2, $b2, $w2);

        // Exhausted trial (deterministic ledger fixture).
        [$c3, $b3, $w3] = $this->bareTenant(WorkspacePlanTier::Growth, 'Capped Studio', 'Capped Account');
        DB::table('workspace_plan_assignments')->where('workspace_id', $w3->id)->update(['trial_ends_at' => now()->addDays(3), 'is_complimentary' => false]);
        DB::table('ai_usage_periods')->insert([
            'scope_type' => 'workspace', 'scope_id' => $w3->id, 'workspace_id' => $w3->id, 'period_key' => 'trial', 'policy_key' => 'trial',
            'policy_version' => 1, 'cap_microusd' => 3000000, 'reserved_microusd' => 0, 'committed_microusd' => 3000000,
            'interactive_reserved_microusd' => 0, 'interactive_committed_microusd' => 2400000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $out['capped'] = $this->row($c3, $b3, $w3);

        // A paid Core account: browser.js exhausts its month and then upgrades it to Growth inside the same month.
        [$c4, $b4, $w4] = $this->tenant(WorkspacePlanTier::Core, 'Core Studio', 'Core Account');
        $out['core'] = $this->row($c4, $b4, $w4);

        $out['permissions'] = array_merge(['access_backend'], $this->allCustomerPermissions());
        file_put_contents(__DIR__ . '/qa.json', json_encode($out, JSON_PRETTY_PRINT));
        $this->assertFileExists(__DIR__ . '/qa.json');
    }

    private function freezeClockOff(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
    }

    private function row($customer, $business, $workspace): array
    {
        return ['user' => (int) $customer->user_id, 'workspace' => $workspace->uid, 'business' => $business->uid];
    }
}
