<?php

namespace Tests\Feature\Crm\Concerns;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Models\Business;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\Customer;
use App\Models\Workspace;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

trait CreatesCrmFixtures
{
    use CreatesCustomerContextFixtures;

    private int $crmPhoneSequence = 5000;

    /** @var array<int, ContactGroups> */
    private array $crmGroups = [];

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function crmTenant(string $businessName = 'Harbor Lane Studios', string $workspaceName = 'Harbor Lane'): array
    {
        return $this->tenant(WorkspacePlanTier::Growth, $businessName, $workspaceName);
    }

    /**
     * @param  array<string, string>  $identity custom-field tag => value (FIRST_NAME, LAST_NAME, ...)
     */
    protected function crmContact(Business $business, array $identity = [], ?string $phone = null): Contacts
    {
        $group = $this->crmGroups[$business->id] ??= ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Clients',
            'status' => true,
        ]);

        $contact = Contacts::create([
            'customer_id' => $group->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone ?? '1415555' . str_pad((string) (++$this->crmPhoneSequence), 4, '0', STR_PAD_LEFT),
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);

        foreach ($identity as $tag => $value) {
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $group->id, 'tag' => $tag],
                ['label' => ucwords(strtolower(str_replace('_', ' ', $tag))), 'type' => 'text', 'visible' => true, 'required' => false],
            );
            ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $field->id, 'value' => $value]);
        }

        return $contact->fresh();
    }

    protected function standardPipeline(Business $business): CrmPipeline
    {
        return app(CrmPipelineService::class)->setUpStandardPipeline($business);
    }

    protected function stageKeyed(CrmPipeline $pipeline, string $semanticKey): CrmPipelineStage
    {
        return CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->where('semantic_key', $semanticKey)->firstOrFail();
    }

    protected function stageNamed(CrmPipeline $pipeline, string $name): CrmPipelineStage
    {
        return CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->where('name', $name)->firstOrFail();
    }

    protected function deal(Business $business, ?CrmPipeline $pipeline = null, ?Contacts $contact = null, string $title = 'Kitchen renovation', ?CrmPipelineStage $stage = null, ?int $valueMinor = null): CrmOpportunity
    {
        $pipeline ??= $this->standardPipeline($business);
        $contact ??= $this->crmContact($business);

        return app(CrmOpportunityService::class)->create($business, $pipeline, $contact, $title, $valueMinor, $stage);
    }

    /**
     * @param  array<int|string, string>  $parameters extra route parameters after the Workspace and Business
     */
    protected function crmRoute(string $name, Workspace $workspace, Business $business, array $parameters = []): string
    {
        return route('customer.workspaces.businesses.crm.' . $name, [$workspace->uid, $business->uid, ...$parameters]);
    }
}
