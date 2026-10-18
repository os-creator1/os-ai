<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Entitlement\PlatformFeature;
use App\Models\AutomationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Automations\Workflow\CrossDomain\Support\BuildsCrossDomainFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\TestCase;

/**
 * Automations — what the builder PAGE hands the browser for the cross-domain steps:
 * resources of this Business only, what the actor's Location reach allows, what the
 * account can actually use, and a form for every step the registry knows.
 */
class CrossDomainBuilderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCrossDomainFixtures;
    use CallsWorkflowRoutes;

    /** @var array<string, mixed> */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = $this->xWorld();
    }

    /** @return array<string, mixed> */
    private function builderData(AutomationWorkflow $workflow): array
    {
        $html = $this->get($this->routeUrl('show', $this->world['workspace'], $this->world['business'], $workflow))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="wf-builder-data">(.*?)</script>#s', $html, $match);

        return json_decode(html_entity_decode($match[1]), true);
    }

    private function aWorkflow(): AutomationWorkflow
    {
        return $this->triggerWorkflow($this->world['business'], WorkflowTriggerType::ManualEnrollment);
    }

    public function test_the_page_offers_this_businesss_resources_and_nobody_elses(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $mine = $this->xBookingType($this->world['location'], 'Main consultation');
        $uptownType = $this->xBookingType($uptown, 'Uptown consultation');
        $item = $this->xCatalogItem($this->world['business'], 'Wedding photo booth', 80000);
        [$form] = $this->liveForm($this->world['business'], $this->world['location']);
        $questionnaire = $this->makeQuestionnaire($this->world['business']);

        $other = $this->sendableTenant('Other Studio');
        $this->xBookingType($other['location'], 'Their consultation');
        $this->xCatalogItem($other['business'], 'Their package', 1);

        $this->authenticateAs($this->world['customer']);
        $data = $this->builderData($this->aWorkflow());

        $this->assertEqualsCanonicalizing([(int) $mine->id, (int) $uptownType->id], array_column($data['catalogs']['bookingTypes'], 'id'));
        $this->assertSame([(int) $item->id], array_column($data['catalogs']['catalogItems'], 'id'));
        $this->assertSame('USD', $data['currency']);

        $pages = array_column($data['catalogs']['forms'], 'pages', 'id');
        $this->assertSame(1, $pages[(int) $form->id], 'A one-page form.');
        $this->assertGreaterThanOrEqual(2, $pages[(int) $questionnaire->id], 'A questionnaire carries its page count, so the builder can tell them apart.');
    }

    public function test_booking_types_of_locations_the_actor_cannot_reach_are_not_even_named(): void
    {
        $uptown = $this->xLocation($this->world['business'], 'Uptown');
        $mine = $this->xBookingType($this->world['location'], 'Main consultation');
        $this->xBookingType($uptown, 'Uptown consultation');
        $staff = $this->staffGrantedOnly($this->world['workspace'], $this->world['location']);

        // Staff reach one Location, so the workflow they may open is one pinned to it
        // (a Business-wide workflow needs reach of every Location).
        $workflow = $this->triggerWorkflow($this->world['business'], WorkflowTriggerType::ManualEnrollment, ['business_location_id' => $this->world['location']->id]);
        $this->authenticateAs($staff);
        $data = $this->builderData($workflow);

        $this->assertSame([(int) $mine->id], array_column($data['catalogs']['bookingTypes'], 'id'));
    }

    public function test_the_page_says_what_the_account_can_and_cannot_use_and_why(): void
    {
        $this->xTextingReady($this->world['business']);
        $this->authenticateAs($this->world['customer']);
        $all = $this->builderData($this->aWorkflow())['capabilities'];

        foreach (['sms', 'email', 'crm', 'calendar', 'forms', 'documents', 'catalog', 'payments'] as $capability) {
            $this->assertArrayHasKey($capability, $all);
            $this->assertTrue($all[$capability]['available'], "{$capability} is available in a fully set-up account.");
            $this->assertNull($all[$capability]['reason']);
        }

        $this->denyFeature($this->world['workspace'], PlatformFeature::Calendar);
        $this->denyFeature($this->world['workspace'], PlatformFeature::PaymentsContracts);
        \Illuminate\Support\Facades\DB::table('business_email_accounts')->where('business_id', $this->world['business']->id)->update(['state' => 'disconnected']);
        $identities = \Illuminate\Support\Facades\DB::table('business_messaging_identities')->where('business_id', $this->world['business']->id)->pluck('id');
        \Illuminate\Support\Facades\DB::table('business_messaging_numbers')->whereIn('business_messaging_identity_id', $identities)->delete();
        \Illuminate\Support\Facades\DB::table('business_messaging_identities')->whereIn('id', $identities)->delete();

        $limited = $this->builderData($this->aWorkflow())['capabilities'];

        $this->assertFalse($limited['calendar']['available']);
        $this->assertStringContainsString('calendar', $limited['calendar']['reason']);
        $this->assertFalse($limited['documents']['available']);
        $this->assertFalse($limited['payments']['available'], 'Payments need the documents product.');
        $this->assertFalse($limited['email']['available']);
        $this->assertStringContainsString('mailbox', $limited['email']['reason']);
        $this->assertFalse($limited['sms']['available']);
        $this->assertStringContainsString('phone number', $limited['sms']['reason']);
        $this->assertTrue($limited['crm']['available'], 'Unrelated products are unaffected.');
    }

    public function test_the_chooser_hands_the_browser_the_same_capabilities_for_its_recipes(): void
    {
        $this->denyFeature($this->world['workspace'], PlatformFeature::Forms);
        $this->authenticateAs($this->world['customer']);

        $html = $this->get(route('customer.workspaces.businesses.automations.workflows.create', [$this->world['workspace']->uid, $this->world['business']->uid]))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="wf-capabilities"[^>]*>(.*?)</script>#s', $html, $match);
        $capabilities = json_decode(html_entity_decode($match[1]), true);

        $this->assertFalse($capabilities['forms']['available']);
        $this->assertTrue($capabilities['crm']['available']);
    }

    public function test_the_builder_has_a_form_for_every_step_type_and_a_place_for_every_trigger(): void
    {
        $this->authenticateAs($this->world['customer']);
        $html = $this->get($this->routeUrl('show', $this->world['workspace'], $this->world['business'], $this->aWorkflow()))->assertOk()->getContent();

        foreach (WorkflowNodeType::cases() as $type) {
            $this->assertStringContainsString('id="wf-node-form-' . $type->value . '"', $html, "{$type->value} has no inspector form.");
        }

        foreach (WorkflowTriggerType::cases() as $trigger) {
            if ($trigger === WorkflowTriggerType::ManualEnrollment) {
                continue;
            }

            $this->assertStringContainsString('value="' . $trigger->value . '"', $html, "{$trigger->value} is not offered in the trigger picker.");
        }

        // The three scopes are offered, and the old single select is only the "one location" half.
        foreach (['business', 'one', 'selected'] as $mode) {
            $this->assertStringContainsString('name="wf-scope-mode" value="' . $mode . '"', $html);
        }
    }
}
