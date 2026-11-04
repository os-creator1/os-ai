<?php

namespace Tests\Feature\Growth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Growth\GrowthOpportunityReader;
use App\Library\Growth\GrowthViewer;
use App\Models\Business;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * Stored Growth Opportunities are filtered LIVE by the same domain => feature
 * requirement the evaluation uses, so a downgrade to Core hides the domains
 * the plan no longer includes (SEO, Ads, ...) from every GrowthOpportunityReader
 * path, keeps CRM-included ones, preserves Location ACL, and never deletes.
 */
class GrowthOpportunityPlanEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const SEO = 'seo.technical_findings:v1';

    private const ADS = 'ads.sync_stale:v1';

    private const CRM = 'crm.unanswered_new_leads:v1';

    public function test_growth_sees_every_domain(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->seed3($business);

        $types = $this->topTypes($business);

        $this->assertEqualsCanonicalizing([self::SEO, self::ADS, self::CRM], $types);
    }

    public function test_core_downgrade_hides_seo_and_ads_everywhere_but_keeps_crm_and_history(): void
    {
        $business = $this->createBusinessForOpportunities();
        [$seo, $ads, $crm] = $this->seed3($business);

        $this->changePlan($business, WorkspacePlanTier::Core);
        $business = $business->fresh();
        $reader = app(GrowthOpportunityReader::class);
        $viewer = GrowthViewer::forTest(1, [], true);

        $this->assertSame([self::CRM], $this->topTypes($business));
        $this->assertSame([self::CRM], collect($reader->paginate($business, $viewer, ['state' => 'all'])->items())->pluck('type')->all());
        $this->assertSame(1, $reader->summary($business, $viewer)['open']);
        $this->assertSame(1, $reader->stateCounts($business, $viewer)['open']);
        $this->assertNull($reader->find($business, $viewer, $seo->uid));
        $this->assertNull($reader->find($business, $viewer, $ads->uid));
        $this->assertNotNull($reader->find($business, $viewer, $crm->uid));

        // History is untouched.
        $this->assertSame(3, Opportunity::query()->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('opportunities', ['id' => $seo->id, 'status' => 'open', 'freshness' => 'current']);

        // Upgrade restores them.
        $this->changePlan($business, WorkspacePlanTier::Growth);
        $this->assertEqualsCanonicalizing([self::SEO, self::ADS, self::CRM], $this->topTypes($business->fresh()));
    }

    public function test_location_acl_still_applies_on_core(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->changePlan($business, WorkspacePlanTier::Core);
        $business = $business->fresh();

        $this->growth($business, self::CRM, OpportunityWorkerKey::Sales, ['location_id' => null]);
        $other = \App\Models\BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $hidden = $this->growth($business, 'crm.stale_opportunities:v1', OpportunityWorkerKey::Sales, ['location_id' => $other->id]);

        $viewer = GrowthViewer::forTest(1, [], false);
        $reader = app(GrowthOpportunityReader::class);

        $this->assertSame([self::CRM], $reader->top($business, $viewer, 10)->pluck('type')->all());
        $this->assertNull($reader->find($business, $viewer, $hidden->uid));
    }

    public function test_another_tenants_growth_rows_never_appear(): void
    {
        $business = $this->createBusinessForOpportunities();
        $other = $this->createBusinessForOpportunities();
        $this->growth($other, self::CRM, OpportunityWorkerKey::Sales);

        $this->assertSame([], $this->topTypes($business));
    }

    /** @return array<int, string> */
    private function topTypes(Business $business): array
    {
        return app(GrowthOpportunityReader::class)
            ->top($business, GrowthViewer::forTest(1, [], true), 20)
            ->pluck('type')->all();
    }

    /** @return array<int, Opportunity> */
    private function seed3(Business $business): array
    {
        return [
            $this->growth($business, self::SEO, OpportunityWorkerKey::Seo),
            $this->growth($business, self::ADS, OpportunityWorkerKey::Ads),
            $this->growth($business, self::CRM, OpportunityWorkerKey::Sales),
        ];
    }

    private function growth(Business $business, string $type, OpportunityWorkerKey $worker, array $overrides = []): Opportunity
    {
        return $this->createOpportunity($business, array_merge([
            'type' => $type,
            'worker_key' => $worker->value,
            'location_id' => null,
            'impact' => 3,
        ], $overrides));
    }

    private function changePlan(Business $business, WorkspacePlanTier $tier): void
    {
        $admin = User::query()->where('is_admin', true)->firstOrFail();

        app(EntitlementManager::class)->changePlan($business->workspace()->first(), $tier, (int) $admin->id, 'Growth eligibility test.');
    }
}
