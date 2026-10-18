<?php

namespace Tests\Feature\Automations\Workflow\CrossDomain\Support;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Catalog\CatalogItemManager;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\Feature\Payments\Concerns\CreatesPayableDocuments;

/**
 * The cross-domain Automations tests' shared world: ONE Business that holds every
 * product the new triggers and actions reach into — Documents and Payments (a Stripe
 * account that can take a charge), Forms, the CRM pipeline, the Calendar, a product
 * catalog and a connected mailbox — built through each domain's own production
 * managers, never by raw inserts where a manager exists.
 *
 * Nothing here talks to a provider: Business Email runs against its in-memory fake
 * provider, Stripe against FakeStripeConnectGateway, the messaging core against a
 * recording double, and AdvanceWorkflowEnrollment is faked so a test drives each step
 * itself and "how many times was it sent" is observable.
 */
trait BuildsCrossDomainFixtures
{
    use CreatesPayableDocuments;
    use CreatesDocumentsTestData;
    use CreatesCrmFixtures;
    use CreatesFormsFixtures;
    use CreatesBusinessEmailFixtures;
    use BuildsFoundationWorkflows;
    use BuildsActionWorkflows;

    /**
     * @return array{customer: \App\Models\Customer, business: Business, workspace: \App\Models\Workspace, location: BusinessLocation, contact: Contacts}
     */
    protected function xWorld(): array
    {
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        $this->bindFakeGateway();
        $this->bindFakeEmailProviders();

        $tenant = $this->sendableTenant();
        $this->chargeReadyConnection($tenant['business']);
        $this->activeAccount($tenant['business']);
        $this->xGiveEmail($tenant['business'], $tenant['contact'], 'pat@example.com');

        return $tenant;
    }

    /**
     * A texting path for the Business: an active managed number and an originator it
     * owns. The messaging core itself is doubled per test (captureSendCore), so nothing
     * is ever sent.
     */
    protected function xTextingReady(Business $business): void
    {
        \App\Models\Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = \App\Models\Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        \App\Models\Senderid::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'sender_id' => 'XDOMAIN' . random_int(100, 999),
            'status' => \App\Models\Senderid::STATUS_ACTIVE,
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'currency_id' => $currency->id,
        ]);

        $this->giveManagedIdentity($business);
    }

    /** Give a contact exactly one email address (a contact has no email column: it is a custom field). */
    protected function xGiveEmail(Business $business, Contacts $contact, string $email): void
    {
        $field = DB::table('contact_group_fields')->where('contact_group_id', $contact->group_id)->where('tag', 'EMAIL')->first();

        $fieldId = $field !== null ? (int) $field->id : (int) DB::table('contact_group_fields')->insertGetId([
            'contact_group_id' => $contact->group_id,
            'label' => 'Email',
            'type' => 'email',
            'tag' => 'EMAIL',
            'visible' => true,
            'required' => false,
            'is_phone' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contacts_custom_field')->updateOrInsert(
            ['contact_id' => $contact->id, 'field_id' => $fieldId],
            ['value' => $email, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    protected function xLocation(Business $business, string $name): BusinessLocation
    {
        return BusinessLocation::create(['business_id' => $business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    /** A contact of the world's Business, at a Location (or none), with one email address. */
    protected function xContact(array $world, ?BusinessLocation $location, string $phone, ?string $email = 'client@example.com'): Contacts
    {
        $contact = Contacts::create([
            'customer_id' => $world['business']->customer_id,
            'business_id' => $world['business']->id,
            'group_id' => $world['contact']->group_id,
            'phone' => $phone,
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);

        if ($email !== null) {
            $this->xGiveEmail($world['business'], $contact, $email);
        }

        return $contact->fresh();
    }

    protected function xBookingType(BusinessLocation $location, string $name = 'Consultation', bool $active = true): BookingType
    {
        return BookingType::create([
            'business_location_id' => $location->id,
            'name' => $name,
            'duration_minutes' => 30,
            'is_active' => $active,
        ]);
    }

    protected function xCatalogItem(Business $business, string $name = 'Photo booth hire', int $priceMinor = 50000, string $type = 'package'): CatalogItem
    {
        return app(CatalogItemManager::class)->create($business, [
            'type' => $type,
            'name' => $name,
            'description' => null,
            'price_minor' => $priceMinor,
            'currency_code' => 'USD',
        ]);
    }

    /** The standard sales pipeline, and a deal for the contact in its first stage. */
    protected function xDeal(Business $business, Contacts $contact, ?BusinessLocation $location = null): CrmOpportunity
    {
        $deal = $this->deal($business, $this->standardPipeline($business), $contact);

        if ($location !== null) {
            DB::table('crm_opportunities')->where('id', $deal->id)->update(['location_id' => $location->id]);
        }

        return $deal->fresh();
    }

    /** Enroll a contact directly (the step runs when the test says so). */
    protected function xEnroll(AutomationWorkflow $workflow, Contacts $contact, string $occurrence, ?BusinessLocation $pinned = null): AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, $occurrence, 0, $pinned?->id === null ? null : (int) $pinned->id);
        $this->assertNotNull($enrollment, 'The contact should have been enrolled.');

        return $enrollment;
    }

    protected function xAdvance(AutomationEnrollment $enrollment): AutomationEnrollment
    {
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        return $enrollment->fresh();
    }

    protected function xStep(AutomationEnrollment $enrollment, string $nodeType): AutomationStepRun
    {
        return AutomationStepRun::query()->where('enrollment_id', $enrollment->id)->where('node_type', $nodeType)->sole();
    }

    /** @return array{key: string, type: string, config: array<string, mixed>} */
    protected function xNode(string $type, array $config): array
    {
        return ['key' => (string) Str::uuid(), 'type' => $type, 'config' => $config];
    }

    /**
     * A published manual workflow. A contact may enter it again (each entry is its own
     * occurrence), so a test can run the same step for the same contact more than once.
     */
    protected function xManualWorkflow(Business $business, array $steps, array $triggerConfig = []): AutomationWorkflow
    {
        return $this->triggerWorkflow(
            $business,
            WorkflowTriggerType::ManualEnrollment,
            $triggerConfig + ['enrollment_policy' => 'once_per_occurrence', 'enrollment_policy_source' => 'user'],
            [...$steps, $this->endStep()],
        );
    }
}
