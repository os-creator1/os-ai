<?php

namespace Tests\Feature\Forms\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Crm\CrmPipelineService;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CrmPipeline;
use App\Models\Customer;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;

/**
 * Forms V1 — fixtures for the Forms domain and HTTP tests.
 *
 * Everything is built through the same seams production reads: forms and
 * deployments through `FormManager`, memberships through the real membership
 * repositories, entitlement changes through `EntitlementManager`. Nothing here
 * can accidentally prove a rule the production guards would not agree with.
 */
trait CreatesFormsFixtures
{
    use CreatesCustomerContextFixtures;

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    protected function formsTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Core, string $name = 'Harbor Lane Studios'): array
    {
        return $this->tenant($tier, $name, $name.' Workspace');
    }

    protected function formsLocation(Business $business, string $name = 'Downtown'): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
    }

    protected function formsPipeline(Business $business): CrmPipeline
    {
        return app(CrmPipelineService::class)->setUpStandardPipeline($business);
    }

    /**
     * A realistic lead form: a name, a required phone, an email, a date, a
     * pick-one and a message. `$overrides` replaces top-level keys.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function leadFormInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Quote request',
            'intro' => 'Tell us about your event.',
            'submit_label' => 'Send',
            'success_message' => 'Thanks — we will be in touch.',
            'create_opportunity' => false,
            'fields' => [
                ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                ['label' => 'Phone', 'type' => 'phone', 'required' => true],
                ['label' => 'Email', 'type' => 'email', 'required' => false],
                ['label' => 'Event date', 'type' => 'date', 'required' => false],
                ['label' => 'Event type', 'type' => 'select', 'required' => false, 'options' => "Wedding\nCorporate\nBirthday"],
                ['label' => 'Message', 'type' => 'textarea', 'required' => false],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeForm(Business $business, array $overrides = [], bool $activate = false): Form
    {
        $manager = app(FormManager::class);
        $form = $manager->create($business, $this->leadFormInput($overrides));

        return $activate ? $manager->activate($business, $form) : $form;
    }

    /**
     * A three-page questionnaire: who they are (name, phone), their event (date,
     * type) and the details (a REQUIRED message and consent on the LAST page, so a
     * later-page required field is exercised). Creates an Opportunity.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function questionnaireInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Event questionnaire',
            'intro' => 'A few questions about your event.',
            'submit_label' => 'Finish',
            'success_message' => 'Thanks — questionnaire received.',
            'create_opportunity' => true,
            'pages' => [
                ['key' => 'page_1', 'title' => 'About you', 'position' => 1],
                ['key' => 'page_2', 'title' => 'Your event', 'position' => 2],
                ['key' => 'page_3', 'title' => 'Details', 'position' => 3],
            ],
            'fields' => [
                ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true, 'page' => 'page_1'],
                ['label' => 'Phone', 'type' => 'phone', 'required' => true, 'page' => 'page_1'],
                ['label' => 'Event date', 'type' => 'date', 'required' => true, 'page' => 'page_2'],
                ['label' => 'Event type', 'type' => 'select', 'required' => false, 'options' => "Wedding
Corporate", 'page' => 'page_2'],
                ['label' => 'Message', 'type' => 'textarea', 'required' => true, 'page' => 'page_3'],
                ['label' => 'I agree', 'type' => 'checkbox', 'required' => true, 'page' => 'page_3'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeQuestionnaire(Business $business, array $overrides = [], bool $activate = true): Form
    {
        $manager = app(FormManager::class);
        $form = $manager->create($business, $this->questionnaireInput($overrides));

        return $activate ? $manager->activate($business, $form) : $form;
    }

    /** The answers of each questionnaire page, keyed by page key. @return array<string, array<string, mixed>> */
    protected function questionnaireAnswers(): array
    {
        return [
            'page_1' => ['your_name' => 'Ada Lovelace', 'phone' => '+1 (415) 555-1234'],
            'page_2' => ['event_date' => '2027-06-01', 'event_type' => 'Wedding'],
            'page_3' => ['message' => 'Quote please', 'i_agree' => '1'],
        ];
    }

    /**
     * One step of a questionnaire as the visitor's browser posts it.
     *
     * @param  array<string, mixed>|null  $answers  null = the standard answers for that page
     * @return array<string, mixed>
     */
    protected function stepInput(string $token, string $page, ?array $answers = null): array
    {
        return array_merge(
            $answers ?? $this->questionnaireAnswers()[$page],
            [FormSubmissionService::TOKEN_FIELD => $token, FormSubmissionService::PAGE_FIELD => $page]
        );
    }

    protected function deploy(Business $business, Form $form, BusinessLocation $location, bool $enabled = true): FormDeployment
    {
        return app(FormManager::class)->setDeployment($business, $form, $location, $enabled)
            ?? throw new \RuntimeException('deploy() fixture produced no deployment');
    }

    /**
     * An ACTIVE form deployed at one Location.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: Form, 1: FormDeployment}
     */
    protected function liveForm(Business $business, BusinessLocation $location, array $overrides = []): array
    {
        $form = $this->makeForm($business, $overrides, true);

        return [$form, $this->deploy($business, $form, $location)];
    }

    /**
     * What a visitor's browser posts: a fresh render's token plus the answers.
     *
     * @param  array<string, mixed>  $answers  keyed by field key
     * @return array<string, mixed>
     */
    protected function submitInput(FormDeployment $deployment, array $answers = [], ?string $token = null): array
    {
        return array_merge([
            'your_name' => 'Ada Lovelace',
            'phone' => '+1 (415) 555-1234',
            'email' => 'Ada@Example.test',
            'event_date' => '2027-06-01',
            'event_type' => 'Wedding',
            'message' => 'Looking for a quote.',
            FormSubmissionService::TOKEN_FIELD => $token ?? FormOperationToken::issue($deployment),
        ], $answers);
    }

    protected function staffWithFullReach(Workspace $workspace): Customer
    {
        $customer = $this->createCustomer();

        $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);

        return $customer;
    }

    /** Reaches the Business but holds an explicit grant for ONE Location only. */
    protected function staffGrantedOnly(Workspace $workspace, BusinessLocation $location): Customer
    {
        $customer = $this->createCustomer();

        $membership = $this->createMembership($workspace, $customer->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);

        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);

        return $customer;
    }

    protected function outsider(): Customer
    {
        return $this->createCustomer();
    }

    /** The Forms capability is the ONLY thing removed — "tenancy without capability". */
    protected function authenticateWithoutFormsCapability(Customer $customer): void
    {
        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['forms'])));
    }

    /** Only the Forms capability and nothing else (proves Website/other keys never satisfy it). */
    protected function authenticateWithOnlyFormsCapability(Customer $customer): void
    {
        $this->authenticateAs($customer, ['forms']);
    }

    protected function denyFeature(Workspace $workspace, PlatformFeature $feature): void
    {
        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace,
            $feature,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(),
            'Forms test: deny '.$feature->value.'.'
        );
    }

    protected function formsRoute(string $name, Workspace $workspace, Business $business, array $parameters = []): string
    {
        return route('customer.workspaces.businesses.forms.'.$name, [$workspace->uid, $business->uid, ...$parameters]);
    }
}
