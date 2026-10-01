<?php

namespace Tests\Feature\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\AgencyBilling\AgencySaasPlanManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\AgencyClientListReader;
use App\Models\AgencySaasPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * Agency V1 completion, acceptance items 5 and 6 — the SaaS Plan model as the
 * repository's own Lane C contract defines it, proved at the seams the brief
 * names: entitlements derive from the canonical tier through the existing
 * entitlement architecture (no second permission engine, no free-form
 * features), a plan can never cross Agencies, deactivating a plan never
 * disturbs existing subscribers, and the catalog is bounded.
 */
class AgencySaasPlanAcceptanceTest extends TestCase
{
    use CreatesAgencySaasFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    public function test_a_resale_plan_selects_a_canonical_tier_and_never_carries_free_form_features_or_limits(): void
    {
        // The model has no feature or limit columns at all: the only capability
        // lever an Agency has is a tier the product already knows how to entitle.
        foreach (['features', 'feature_keys', 'limits', 'permissions', 'entitlements'] as $column) {
            $this->assertFalse(Schema::hasColumn('agency_saas_plans', $column), "agency_saas_plans.{$column} must not exist.");
        }

        $this->assertNotContains('features', (new AgencySaasPlan())->getFillable());

        // And the tier must be a canonical, resellable one — never the Agency tier.
        $this->assertSame(['core', 'growth'], AgencySaasPlan::RESELLABLE_TIERS);
        $this->assertSame(
            [WorkspacePlanTier::Core, WorkspacePlanTier::Growth],
            AgencySaasPlanManager::resellableTiers(),
        );

        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        foreach (['agency', 'platinum', 'white_label', ''] as $tier) {
            try {
                $this->plans()->create((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace'], [
                    'name' => 'Bad tier', 'tier' => $tier, 'price' => '10.00',
                    'currency_id' => $this->fixtureCurrencyId(), 'currency_code' => 'USD', 'billing_cycle' => 'monthly',
                ]);
                $this->fail("A plan was created for tier [{$tier}].");
            } catch (AgencyBillingException $e) {
                $this->assertSame(AgencyBillingException::TIER_NOT_RESELLABLE, $e->reason);
            }
        }
    }

    public function test_a_clients_effective_entitlements_are_exactly_its_tiers_through_the_existing_architecture(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, WorkspacePlanTier::Growth);

        $this->enrolledClient($fixture, $plan);

        $entitlements = app(EntitlementManager::class);
        $client = $entitlements->getWorkspaceEntitlementSummary($fixture['clientWorkspace']->fresh());

        // A directly-assigned Growth Workspace is the reference for "what Growth entitles".
        [, , $reference] = $this->tenant(WorkspacePlanTier::Growth, 'Reference Business', 'Reference Growth');
        $direct = $entitlements->getWorkspaceEntitlementSummary($reference);

        $this->assertSame(WorkspacePlanTier::Growth, $client->tier);
        $this->assertSame($direct->planFeatureKeys, $client->planFeatureKeys);
        $this->assertSame([], $client->overrides, 'The Agency adds no per-client override: capability is the tier, nothing else.');

        // Every key is a canonical PlatformFeature — never a free-form string.
        foreach ($client->planFeatureKeys as $key) {
            $this->assertNotNull(\App\Enums\Entitlement\PlatformFeature::tryFrom($key), $key);
        }
    }

    public function test_a_plan_can_never_be_offered_across_agencies(): void
    {
        $a = $this->agencyWithClient('Agency A', 'Client of A');
        $b = $this->agencyWithClient('Agency B', 'Client of B');

        $this->connectAgencyStripe($a['agencyWorkspace'], (int) $a['agencyOwner']->user_id);
        $this->connectAgencyStripe($b['agencyWorkspace'], (int) $b['agencyOwner']->user_id);
        $planOfB = $this->publishedPlan($b['agencyWorkspace'], (int) $b['agencyOwner']->user_id, name: 'B Plan');

        // A's owner, A's own client, B's plan.
        try {
            $this->subscriptions()->offer((int) $a['agencyOwner']->user_id, $a['agencyWorkspace'], $a['clientWorkspace'], $planOfB);
            $this->fail('Agency A offered Agency B\'s plan.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::NO_ACTIVE_RELATIONSHIP, $e->reason);
        }

        // B's owner, B's plan, A's client.
        try {
            $this->subscriptions()->offer((int) $b['agencyOwner']->user_id, $b['agencyWorkspace'], $a['clientWorkspace'], $planOfB);
            $this->fail('Agency B offered to Agency A\'s client.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::NO_ACTIVE_RELATIONSHIP, $e->reason);
        }

        $this->assertSame(0, DB::table('agency_client_subscriptions')->count());
    }

    public function test_unpublishing_a_plan_leaves_existing_subscribers_untouched_and_stops_new_offers(): void
    {
        $fixture = $this->agencyWithClient('Northwind Agency', 'First Client');
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, WorkspacePlanTier::Growth);

        $enrolled = $this->enrolledClient($fixture, $plan);
        $tierBefore = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($fixture['clientWorkspace']->fresh());

        $this->plans()->unpublish((int) $fixture['agencyOwner']->user_id, $plan);

        // Existing assignment is PRESERVED (Lane C §C8: a shop-window decision, not a cancellation).
        $subscription = $enrolled['subscription']->refresh();
        $this->assertTrue($subscription->status->isLive());
        $this->assertSame((int) $plan->id, (int) $subscription->agency_saas_plan_id);

        $tierAfter = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($fixture['clientWorkspace']->fresh());
        $this->assertSame($tierBefore->tier, $tierAfter->tier);
        $this->assertSame($tierBefore->status, $tierAfter->status);
        $this->assertSame([(int) $plan->id => 1], $this->plans()->subscriberCounts($fixture['agencyWorkspace']));

        // ...but nobody NEW can be offered it.
        $second = $this->createAgencyManagedClient($fixture['agencyWorkspace'], 'Second Business', 'Second Client');

        try {
            $this->subscriptions()->offer(
                (int) $fixture['agencyOwner']->user_id,
                $fixture['agencyWorkspace'],
                $second['clientWorkspace'],
                $plan->refresh(),
            );
            $this->fail('An unpublished plan was offered.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::PLAN_NOT_SELLABLE, $e->reason);
        }
    }

    public function test_the_clients_list_shows_the_agencys_own_subscription_for_each_client(): void
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, name: 'Growth Partner');
        $this->enrolledClient($fixture, $plan);

        $row = collect(app(AgencyClientListReader::class)->page((int) $fixture['agencyWorkspace']->id)->items())->first();

        $this->assertSame('Growth Partner', $row['agency_subscription_plan']);
        $this->assertInstanceOf(AgencyClientSubscriptionStatus::class, $row['agency_subscription_status']);
        $this->assertTrue($row['agency_subscription_status']->isLive());
    }

    public function test_the_plan_catalog_is_bounded_on_read_and_on_create(): void
    {
        $fixture = $this->agencyWithClient();
        $workspace = $fixture['agencyWorkspace'];
        $ownerId = (int) $fixture['agencyOwner']->user_id;
        $this->connectAgencyStripe($workspace, $ownerId);

        $rows = [];

        for ($i = 0; $i < AgencySaasPlanManager::MAX_PLANS_PER_AGENCY + 5; $i++) {
            $rows[] = [
                'uid' => (string) Str::uuid(),
                'agency_workspace_id' => $workspace->id,
                'name' => sprintf('Plan %03d', $i),
                'tier' => 'core',
                'billing_cycle' => 'monthly',
                'is_published' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('agency_saas_plans')->insert($rows);

        // Reads never return more than the bound...
        $this->assertCount(AgencySaasPlanManager::MAX_PLANS_PER_AGENCY, $this->plans()->forAgency($workspace));

        // ...and one more plan is refused outright (already over the cap here).
        try {
            $this->plans()->create($ownerId, $workspace, [
                'name' => 'One too many', 'tier' => 'core', 'price' => '10.00',
                'currency_id' => $this->fixtureCurrencyId(), 'currency_code' => 'USD', 'billing_cycle' => 'monthly',
            ]);
            $this->fail('A plan beyond the cap was created.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::PLAN_LIMIT_REACHED, $e->reason);
        }

        // Another Agency's catalog is unaffected by this one's cap.
        $other = $this->agencyWithClient('Other Agency', 'Other Client');
        $this->connectAgencyStripe($other['agencyWorkspace'], (int) $other['agencyOwner']->user_id);
        $created = $this->plans()->create((int) $other['agencyOwner']->user_id, $other['agencyWorkspace'], [
            'name' => 'First plan', 'tier' => 'core', 'price' => '10.00',
            'currency_id' => $this->fixtureCurrencyId(), 'currency_code' => 'USD', 'billing_cycle' => 'monthly',
        ]);
        $this->assertSame((int) $other['agencyWorkspace']->id, (int) $created->agency_workspace_id);
    }
}
