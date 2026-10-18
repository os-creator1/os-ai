<?php

namespace Tests\Feature\CustomFields;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Runtime\ContactMergeFields;
use App\Models\AutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Opportunity merge fields come from the enrollment's OWN Opportunity trigger
 * fact (its persisted history key), never from the contact's "latest" deal.
 */
class MergeFieldOpportunityContextTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private function enrollmentFor(WorkflowTriggerType $type, $business, $contact, ?string $key): AutomationEnrollment
    {
        return new AutomationEnrollment([
            'business_id' => $business->id, 'contact_id' => $contact->id,
            'trigger_type' => $type, 'trigger_occurrence_key' => $key,
        ]);
    }

    public function test_an_opportunity_triggered_run_resolves_that_deal(): void
    {
        [, $business] = $this->crmTenant();
        $business->forceFill(['currency_code' => 'USD'])->save();
        $contact = $this->crmContact($business->fresh(), ['FIRST_NAME' => 'Pat']);
        $deal = $this->deal($business->fresh(), null, $contact, 'Wedding booth', null, 150000);
        $historyId = DB::table('crm_opportunity_history')->where('opportunity_id', $deal->id)->orderBy('id')->value('id');
        $enrollment = $this->enrollmentFor(WorkflowTriggerType::OpportunityCreated, $business, $contact, 'crm_opportunity_history:' . $historyId);

        $rendered = ContactMergeFields::renderForEnrollment('{{contact.first_name}}: {{opportunity.name}} ({{opportunity.value}}) in {{opportunity.stage}}', $contact, $enrollment, $business->fresh());

        $this->assertSame('Pat: Wedding booth (USD 1,500) in New inquiry', $rendered);
    }

    public function test_the_contacts_other_deals_are_never_substituted_and_other_triggers_get_none(): void
    {
        [, $business] = $this->crmTenant();
        $contact = $this->crmContact($business->fresh(), ['FIRST_NAME' => 'Pat']);
        $this->deal($business->fresh(), null, $contact, 'Existing deal');

        $manual = $this->enrollmentFor(WorkflowTriggerType::ManualEnrollment, $business, $contact, 'manual-1');
        $this->assertSame('[]', ContactMergeFields::renderForEnrollment('[{{opportunity.name}}]', $contact, $manual, $business->fresh()));

        $noHistory = $this->enrollmentFor(WorkflowTriggerType::OpportunityWon, $business, $contact, 'crm_opportunity_history:999999');
        $this->assertSame('[]', ContactMergeFields::renderForEnrollment('[{{opportunity.name}}]', $contact, $noHistory, $business->fresh()));
    }

    public function test_a_history_key_of_another_business_resolves_nothing(): void
    {
        [, $a] = $this->crmTenant('Business A', 'Workspace A');
        [, $b] = $this->crmTenant('Business B', 'Workspace B');
        $contactA = $this->crmContact($a->fresh(), ['FIRST_NAME' => 'Pat']);
        $dealB = $this->deal($b->fresh(), null, null, 'B secret deal');
        $historyB = DB::table('crm_opportunity_history')->where('opportunity_id', $dealB->id)->value('id');

        $forged = $this->enrollmentFor(WorkflowTriggerType::OpportunityCreated, $a, $contactA, 'crm_opportunity_history:' . $historyB);

        $this->assertSame('[]', ContactMergeFields::renderForEnrollment('[{{opportunity.name}}]', $contactA, $forged, $a->fresh()));
    }
}
