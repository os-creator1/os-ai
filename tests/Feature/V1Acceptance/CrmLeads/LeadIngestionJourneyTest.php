<?php

namespace Tests\Feature\V1Acceptance\CrmLeads;

use App\Library\Forms\FormOperationToken;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\Customer;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\V1Acceptance\CrmLeads\Concerns\CrmLeadsFixtures;
use Tests\TestCase;

/**
 * V1 Final Acceptance 02, journey B — a lead arrives through the Forms V1 public
 * link (the Location-bound lead path V1 ships) and becomes a Contact and an
 * Opportunity at exactly the deployment's Location, once, however it is replayed.
 * No new ingestion architecture: the visitor's browser posts what it always posts.
 *
 * The legacy Website-builder form (Business-level, no Location of its own) is
 * walked in WebsiteLeadIngestionJourneyTest.
 */
class LeadIngestionJourneyTest extends TestCase
{
    use RefreshDatabase;
    use CrmLeadsFixtures;
    use CreatesFormsFixtures;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private Form $form;

    private FormDeployment $atDowntown;

    private FormDeployment $atUptown;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->business, $this->workspace] = $this->crmTenant('Harbor Lane Studios', 'Harbor Lane');
        $this->downtown = $this->location($this->business, 'Downtown');
        $this->uptown = $this->location($this->business, 'Uptown');
        $this->standardPipeline($this->business);

        $this->form = $this->makeForm($this->business, ['create_opportunity' => true], true);
        $this->atDowntown = $this->deploy($this->business, $this->form, $this->downtown);
        $this->atUptown = $this->deploy($this->business, $this->form, $this->uptown);
    }

    /** What the visitor's browser posts, exactly as the public page renders it. */
    private function submit(FormDeployment $deployment, array $answers = [], ?string $token = null)
    {
        return $this->post(route('public.forms.submit', [$deployment->uid]), $this->submitInput($deployment, $answers, $token ?? FormOperationToken::issue($deployment)));
    }

    public function test_a_new_lead_is_a_contact_and_a_deal_at_the_deployments_location(): void
    {
        $this->submit($this->atUptown)->assertRedirect();

        $submission = FormSubmission::query()->sole();
        $contact = Contacts::query()->sole();
        $deal = CrmOpportunity::query()->sole();

        // Business and Location come from the deployment, never from the request.
        $this->assertSame((int) $this->business->id, (int) $contact->business_id);
        $this->assertSame((int) $this->uptown->id, (int) $contact->location_id);
        $this->assertSame((int) $this->business->id, (int) $deal->business_id);
        $this->assertSame((int) $this->uptown->id, (int) $deal->location_id);
        $this->assertSame((int) $contact->id, (int) $deal->contact_id);
        $this->assertSame('form', $deal->source);
        $this->assertSame((int) $this->uptown->id, (int) $submission->business_location_id);
        $this->assertSame((int) $contact->id, (int) $submission->contact_id);
        $this->assertSame((int) $deal->id, (int) $submission->crm_opportunity_id);

        // The person's own details, and no messaging consent from an anonymous inquiry.
        $this->assertSame('14155551234', (string) $contact->phone);
        $this->assertSame('Ada', $this->customField($contact, 'FIRST_NAME'));
        $this->assertSame(Contacts::STATUS_UNSUBSCRIBE, $contact->status);
        $this->assertSame('new_inquiry', $deal->stage->semantic_key);
    }

    public function test_a_posted_location_or_business_never_overrides_the_deployments(): void
    {
        [, $rivalBusiness] = $this->crmTenant('Rival Studio', 'Rival');
        $rivalLocation = $this->location($rivalBusiness, 'Rival Downtown');

        $this->submit($this->atDowntown, [
            'business_id' => $rivalBusiness->id,
            'business_location_id' => $rivalLocation->id,
            'location_id' => $this->uptown->id,
        ]);

        $contact = Contacts::query()->sole();
        $this->assertSame((int) $this->business->id, (int) $contact->business_id);
        $this->assertSame((int) $this->downtown->id, (int) $contact->location_id);
        $this->assertSame((int) $this->downtown->id, (int) CrmOpportunity::query()->sole()->location_id);
        $this->assertSame(0, Contacts::query()->where('business_id', $rivalBusiness->id)->count());
    }

    public function test_a_replay_with_the_same_token_creates_nothing_more(): void
    {
        $token = FormOperationToken::issue($this->atDowntown);

        $this->submit($this->atDowntown, [], $token)->assertRedirect();
        $this->submit($this->atDowntown, [], $token)->assertRedirect();
        $this->submit($this->atDowntown, [], $token)->assertRedirect();

        $this->assertSame(1, FormSubmission::query()->count());
        $this->assertSame(1, Contacts::query()->count());
        $this->assertSame(1, CrmOpportunity::query()->count());
    }

    public function test_the_same_person_is_one_contact_per_location_and_each_genuine_inquiry_is_its_own_deal(): void
    {
        // A second, separately rendered submission by the same person at the same
        // Location: the same Contact, a second inquiry.
        $this->submit($this->atDowntown)->assertRedirect();
        $this->submit($this->atDowntown, ['message' => 'A different request.'])->assertRedirect();
        $this->assertSame(1, Contacts::query()->where('location_id', $this->downtown->id)->count());
        $this->assertSame(2, CrmOpportunity::query()->where('location_id', $this->downtown->id)->count());

        // The same number at the other Location is a separate Contact (identity is
        // Location-local; V1 never merges across Locations).
        $this->submit($this->atUptown)->assertRedirect();
        $this->assertSame(2, Contacts::query()->count());
        $this->assertSame(1, Contacts::query()->where('location_id', $this->uptown->id)->count());
        $this->assertSame(3, FormSubmission::query()->count());
    }

    public function test_a_lead_is_reachable_only_by_the_staff_of_its_location(): void
    {
        $this->submit($this->atUptown)->assertRedirect();
        $contact = Contacts::query()->sole();
        $deal = CrmOpportunity::query()->sole();

        $uptownStaff = $this->staffAt($this->workspace, $this->uptown);
        $downtownStaff = $this->staffAt($this->workspace, $this->downtown);

        $this->authenticateAs($uptownStaff, self::STAFF_PERMISSIONS);
        $this->get($this->peopleUrl($this->workspace, $this->business, $contact->uid))->assertOk();
        $this->get($this->crmRoute('opportunities.show', $this->workspace, $this->business, [$deal->uid]))->assertOk();
        $this->get($this->crmRoute('board', $this->workspace, $this->business))->assertOk()->assertSee($deal->title);

        $this->authenticateAs($downtownStaff, self::STAFF_PERMISSIONS);
        $this->get($this->peopleUrl($this->workspace, $this->business, $contact->uid))->assertNotFound();
        $this->get($this->crmRoute('opportunities.show', $this->workspace, $this->business, [$deal->uid]))->assertNotFound();
        $this->get($this->crmRoute('board', $this->workspace, $this->business))->assertOk()->assertDontSee($deal->title);

        $this->authenticateAs($this->owner);
        $this->get($this->peopleUrl($this->workspace, $this->business, $contact->uid))->assertOk();
    }

    public function test_a_lead_is_never_lost_when_the_business_has_no_pipeline_yet(): void
    {
        [, $bare, $bareWorkspace] = $this->crmTenant('No Pipeline Co', 'No Pipeline');
        $location = $this->location($bare, 'Only');
        $form = $this->makeForm($bare, ['create_opportunity' => true], true);
        $deployment = $this->deploy($bare, $form, $location);

        $this->submit($deployment)->assertRedirect();

        $contact = Contacts::query()->where('business_id', $bare->id)->sole();
        $this->assertSame((int) $location->id, (int) $contact->location_id);
        $this->assertSame(0, CrmOpportunity::query()->where('business_id', $bare->id)->count());
        $this->assertNotNull(FormSubmission::query()->where('business_id', $bare->id)->sole()->contact_id);
    }
}
