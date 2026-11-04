<?php

namespace Tests\Feature\Opportunity;

use App\Models\Business;
use App\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * An action destination must lead to the EXACT Business being viewed.
 * `customer.business.edit` resolves the signed-in customer's primary
 * Business, so it is only offered when that is the viewed Business and the
 * actor is not viewing as a client; otherwise no link is rendered.
 */
class OpportunityActionDestinationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('opportunity.enabled', true);
    }

    public function test_owner_in_their_own_business_gets_the_profile_link(): void
    {
        $managed = $this->createAgencyManagedClient();
        $this->authenticateAs($managed['agencyOwner']);

        $opportunity = $this->opportunity($managed['agencyBusiness'], 'missing_email', 'add_email');

        $html = $this->get(route('customer.opportunities.show', $opportunity->id))->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('customer.business.edit') . '"', $html);
    }

    public function test_agency_view_as_never_links_to_the_agencys_own_primary_business_profile(): void
    {
        $managed = $this->createAgencyManagedClient();
        $this->authenticateAs($managed['agencyOwner']);
        $this->post(route('customer.workspaces.clients.view-as', [$managed['agencyWorkspace']->uid, $managed['clientWorkspace']->uid]))->assertRedirect();

        $profile = $this->opportunity($managed['clientBusiness'], 'missing_email', 'add_email');
        $services = $this->opportunity($managed['clientBusiness'], 'missing_services', 'add_service');

        $html = $this->get(route('customer.opportunities.show', $profile->id))->assertOk()->getContent();
        $this->assertStringContainsString('Add your public business email', $html);
        $this->assertStringNotContainsString(route('customer.business.edit'), $html);
        $this->assertStringNotContainsString($managed['agencyBusiness']->uid, $html);

        // A scoped destination, when offered, targets the viewed client Business only.
        $html = $this->get(route('customer.opportunities.show', $services->id))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('customer.business.edit'), $html);
        $this->assertStringNotContainsString($managed['agencyBusiness']->uid, $html);
    }

    public function test_scoped_destination_targets_the_viewed_business_for_the_owner(): void
    {
        $managed = $this->createAgencyManagedClient();
        $this->authenticateAs($managed['agencyOwner']);

        $services = $this->opportunity($managed['agencyBusiness'], 'missing_services', 'add_service');

        $html = $this->get(route('customer.opportunities.show', $services->id))->assertOk()->getContent();

        $this->assertStringContainsString(
            'href="' . route('customer.workspaces.businesses.services.index', [$managed['agencyWorkspace']->uid, $managed['agencyBusiness']->uid]) . '"',
            $html,
        );
    }

    private function opportunity(Business $business, string $type, string $actionKey): Opportunity
    {
        return Opportunity::create([
            'business_id' => $business->id,
            'worker_key' => 'business_advisor',
            'type' => $type,
            'fingerprint_version' => 1,
            'fingerprint' => hash('sha256', $type . $business->id),
            'title' => 'x',
            'summary' => 'x',
            'status' => 'open',
            'freshness' => 'current',
            'impact' => 3,
            'urgency' => 1,
            'effort' => 1,
            'confidence' => '1.00',
            'goal_relevance_rank' => 1,
            'evidence_freshness_rank' => 1,
            'priority_score' => 50,
            'scoring_version' => 1,
            'scored_at' => now(),
            'evidence' => [],
            'recommended_action' => ['action_key' => $actionKey, 'parameters' => [], 'completion_policy' => 'customer_attested'],
            'occurrence_number' => 1,
            'last_confirmed_at' => now(),
            'first_detected_at' => now(),
        ]);
    }
}
