<?php

namespace Tests\Feature\Crm;

use App\Models\BusinessLocation;
use App\Models\CrmOpportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 08B Part 2/§8 — CrmOpportunityService::create(),
 * the single real writer of `crm_opportunities`, resolves `location_id`,
 * driven through its real HTTP production entry point
 * (CrmOpportunitiesController::store()).
 */
class CrmOpportunityLocationCreationSiteTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    public function test_store_resolves_a_single_active_location(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
        $pipeline = $this->standardPipeline($business);
        $contact = $this->crmContact($business);
        $this->authenticateAs($owner);

        $this->post($this->crmRoute('opportunities.store', $workspace, $business), [
            'title' => 'New roof estimate',
            'contact' => $contact->uid,
            'pipeline' => $pipeline->uid,
        ])->assertRedirect();

        $deal = CrmOpportunity::query()->where('business_id', $business->id)->sole();
        $this->assertSame((int) $location->id, (int) $deal->location_id);
    }

    public function test_store_leaves_several_active_locations_null(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $pipeline = $this->standardPipeline($business);
        $contact = $this->crmContact($business);
        $this->authenticateAs($owner);

        $this->post($this->crmRoute('opportunities.store', $workspace, $business), [
            'title' => 'New roof estimate',
            'contact' => $contact->uid,
            'pipeline' => $pipeline->uid,
        ])->assertRedirect();

        $deal = CrmOpportunity::query()->where('business_id', $business->id)->sole();
        $this->assertNull($deal->location_id);
    }
}
