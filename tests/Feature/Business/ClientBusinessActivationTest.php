<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Newly-invited-client flow fix — ClientBusinessActivationController and
 * BusinessManager::activateClientBusiness() in isolation. The end-to-end
 * accepted-invitation -> Draft -> activation -> Agency View As path is
 * proven in AgencyClientsHttpTest; this file proves the activation step's
 * own mechanics: who may reach it, what it actually writes, the Draft
 * guard, and that a refused or invalid attempt changes nothing.
 *
 * Review correction — this path is for a genuine Agency-managed Client
 * Workspace ONLY (an ACTIVE AgencyClientWorkspaceRelationship must name
 * it), never "any Draft Business the actor owns": the businesses table
 * itself defaults every row to Draft, so every positive fixture here
 * (draftClient()) creates a REAL active relationship, and the "ordinary
 * Draft Business" and "terminated relationship" cases are their own
 * explicit negative tests below.
 */
class ClientBusinessActivationTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    /**
     * A genuine Agency-managed Client Workspace with a Draft Business
     * carrying AgencyClientProvisioningManager::accept()'s own placeholder
     * identity — the actual shape this fix concerns.
     *
     * @return array{customer: Customer, business: Business, workspace: Workspace, agencyWorkspace: Workspace, agencyOwner: Customer}
     */
    private function draftClient(string $name = 'Newly Invited Client'): array
    {
        $managed = $this->createAgencyManagedClient(
            clientBusinessName: $name . ' Clinic',
            clientWorkspaceName: $name,
        );

        DB::table('businesses')->where('id', $managed['clientBusiness']->id)->update([
            'status' => BusinessStatus::Draft->value,
            'activated_at' => null,
            'industry' => BusinessIndustry::Other->value,
            'country_code' => 'US',
            'timezone' => 'UTC',
            'currency_code' => 'USD',
        ]);

        return [
            'customer' => $managed['clientOwner'],
            'business' => Business::find($managed['clientBusiness']->id),
            'workspace' => $managed['clientWorkspace'],
            'agencyWorkspace' => $managed['agencyWorkspace'],
            'agencyOwner' => $managed['agencyOwner'],
        ];
    }

    /**
     * An ORDINARY Draft Business with no Agency involved at all — the
     * shape this fix must NOT touch: it keeps its own, unrelated
     * admin-controlled activation rule.
     *
     * @return array{customer: Customer, business: Business, workspace: Workspace}
     */
    private function unmanagedDraftClient(): array
    {
        return $this->createIndependentWorkspaceBusiness(status: BusinessStatus::Draft);
    }

    private function actingAsCustomer(Customer $customer): static
    {
        $this->authenticateAs($customer);

        return $this;
    }

    private function showUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.activate.show', [$workspace->uid, $business->uid]);
    }

    private function storeUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.activate.store', [$workspace->uid, $business->uid]);
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'industry' => BusinessIndustry::ProfessionalServices->value,
            'country_code' => 'ca',
            'timezone' => 'America/Toronto',
            'currency_code' => 'cad',
            'location_name' => 'Main Office',
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'address_line_2' => '',
            'city' => 'Toronto',
            'region' => 'ON',
            'postal_code' => 'M5V 2T6',
            'location_country_code' => 'ca',
            'public_address' => '1',
            'service_radius_km' => '',
            'confirm' => '1',
        ], $overrides);
    }

    private function assertUnchangedPlaceholder(Business $business): void
    {
        $fresh = $business->fresh();
        $this->assertSame(BusinessStatus::Draft, $fresh->status, 'A refused or invalid attempt never changes status.');
        $this->assertNull($fresh->activated_at);
        $this->assertSame(BusinessIndustry::Other, $fresh->industry, 'A refused or invalid attempt never touches identity.');
        $this->assertSame('US', $fresh->country_code);
        $this->assertSame('UTC', $fresh->timezone);
        $this->assertSame('USD', $fresh->currency_code);
    }

    // ------------------------------------------------------------------
    // SUCCESSFUL ACTIVATION (genuine Agency-managed Client Workspace)
    // ------------------------------------------------------------------

    public function test_the_owner_can_open_the_activation_form_prefilled_with_the_placeholder_values(): void
    {
        $fixture = $this->draftClient();

        $response = $this->actingAsCustomer($fixture['customer'])->get($this->showUrl($fixture['workspace'], $fixture['business']));

        $response->assertOk();
        $response->assertSee('US', false);
        $response->assertSee('UTC', false);
        $response->assertSee('USD', false);
    }

    public function test_a_valid_submission_activates_the_business_and_writes_every_real_field(): void
    {
        $fixture = $this->draftClient();

        $response = $this->actingAsCustomer($fixture['customer'])
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload());

        $response->assertRedirect(route('customer.workspaces.show', $fixture['workspace']->uid));

        $business = $fixture['business']->fresh();
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
        $this->assertSame(BusinessIndustry::ProfessionalServices, $business->industry);
        $this->assertSame('CA', $business->country_code);
        $this->assertSame('America/Toronto', $business->timezone);
        $this->assertSame('CAD', $business->currency_code);

        $location = $business->primaryLocation;
        $this->assertNotNull($location);
        $this->assertSame('Main Office', $location->name);
        $this->assertSame('123 Main St', $location->address_line_1);
        $this->assertSame('Toronto', $location->city);
        $this->assertSame('ON', $location->region);
        $this->assertSame('CA', $location->country_code);
        $this->assertTrue((bool) $location->public_address);
    }

    public function test_activation_creates_no_second_location_when_one_already_exists(): void
    {
        $fixture = $this->draftClient();
        app(\App\Library\Business\BusinessLocationManager::class)->upsertPrimaryLocation($fixture['business'], [
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);

        $this->actingAsCustomer($fixture['customer'])
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(1, Business::find($fixture['business']->id)->locations()->count());
    }

    // ------------------------------------------------------------------
    // THE DRAFT GUARD
    // ------------------------------------------------------------------

    public function test_an_already_active_business_cannot_be_activated_again(): void
    {
        $managed = $this->createAgencyManagedClient();
        $fixture = ['customer' => $managed['clientOwner'], 'business' => $managed['clientBusiness'], 'workspace' => $managed['clientWorkspace']];

        $response = $this->actingAsCustomer($fixture['customer'])
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload());

        $response->assertRedirect(route('customer.workspaces.show', $fixture['workspace']->uid));

        // Untouched: the guard refuses before any write, not merely before
        // the status flips.
        $business = $fixture['business']->fresh();
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotSame('CA', $business->country_code);
    }

    public function test_the_activation_form_redirects_away_for_an_already_active_business(): void
    {
        $managed = $this->createAgencyManagedClient();

        $response = $this->actingAsCustomer($managed['clientOwner'])
            ->get($this->showUrl($managed['clientWorkspace'], $managed['clientBusiness']));

        $response->assertRedirect(route('customer.workspaces.show', $managed['clientWorkspace']->uid));
    }

    // ------------------------------------------------------------------
    // REVIEW CORRECTION — GENUINE AGENCY-MANAGED CLIENT WORKSPACES ONLY
    // ------------------------------------------------------------------

    /**
     * An ordinary Draft Business with no Agency involved at all must be
     * refused by both actions, and keeps its own, unrelated
     * admin-controlled activation rule untouched: owning the Workspace is
     * not enough on its own.
     */
    public function test_an_ordinary_draft_business_with_no_agency_relationship_cannot_be_activated_through_this_path(): void
    {
        $fixture = $this->unmanagedDraftClient();

        $this->actingAsCustomer($fixture['customer'])
            ->get($this->showUrl($fixture['workspace'], $fixture['business']))
            ->assertNotFound();

        $response = $this->actingAsCustomer($fixture['customer'])
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload());

        $response->assertNotFound();
        $business = $fixture['business']->fresh();
        $this->assertSame(BusinessStatus::Draft, $business->status);
        $this->assertNull($business->activated_at);
    }

    /**
     * A relationship that has since been terminated is exactly as refused
     * as one that never existed — the Client Workspace was genuinely
     * Agency-managed once, but is not any more.
     */
    public function test_a_terminated_relationship_refuses_activation(): void
    {
        $fixture = $this->draftClient();

        $relationship = app(AgencyClientRelationshipManager::class)->findActiveForClientWorkspace((int) $fixture['workspace']->id);
        $this->assertNotNull($relationship);
        app(AgencyClientRelationshipManager::class)->terminate((int) $fixture['agencyOwner']->user_id, $relationship, 'Test: relationship terminated.');

        $this->actingAsCustomer($fixture['customer'])
            ->get($this->showUrl($fixture['workspace'], $fixture['business']))
            ->assertNotFound();

        $response = $this->actingAsCustomer($fixture['customer'])
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload());

        $response->assertNotFound();
        $this->assertUnchangedPlaceholder($fixture['business']);
    }

    // ------------------------------------------------------------------
    // UNAUTHORIZED ACTORS
    // ------------------------------------------------------------------

    public function test_an_unrelated_stranger_cannot_open_or_submit_the_activation_form(): void
    {
        $fixture = $this->draftClient();
        $stranger = $this->createCustomer();

        $this->actingAsCustomer($stranger)->get($this->showUrl($fixture['workspace'], $fixture['business']))->assertNotFound();
        $this->actingAsCustomer($stranger)
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload())
            ->assertNotFound();

        $this->assertUnchangedPlaceholder($fixture['business']);
    }

    /**
     * The inviting Agency's own owner — not merely an unrelated stranger —
     * is refused identically: activation is the Client Workspace's own
     * OWNER's step alone (resolveManagedClientWorkspace() accepts only
     * that Workspace's own owner_user_id, which is never the Agency's).
     */
    public function test_the_inviting_agencys_owner_cannot_activate_the_clients_business(): void
    {
        $fixture = $this->draftClient();

        $this->actingAsCustomer($fixture['agencyOwner'])
            ->get($this->showUrl($fixture['workspace'], $fixture['business']))
            ->assertNotFound();

        $response = $this->actingAsCustomer($fixture['agencyOwner'])
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload());

        $response->assertNotFound();
        $this->assertUnchangedPlaceholder($fixture['business']);
    }

    /**
     * Even an Admin member of the Client Workspace — not only the inviting
     * Agency — is refused: activation is the Workspace OWNER's own step
     * alone (ClientBusinessActivationController::
     * resolveManagedClientWorkspace() accepts only owner_user_id).
     */
    public function test_an_admin_member_of_the_client_workspace_cannot_activate_it(): void
    {
        $fixture = $this->draftClient();
        $adminCustomer = $this->createCustomer();
        $this->createMembership($fixture['workspace'], $adminCustomer->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
        ]);

        $response = $this->actingAsCustomer($adminCustomer)
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload());

        $response->assertNotFound();
        $this->assertUnchangedPlaceholder($fixture['business']);
    }

    public function test_a_forged_business_uid_from_a_different_workspace_is_refused(): void
    {
        $fixture = $this->draftClient();
        $otherFixture = $this->draftClient('Someone Elses Client');

        $response = $this->actingAsCustomer($fixture['customer'])
            ->post($this->storeUrl($fixture['workspace'], $otherFixture['business']), $this->validPayload());

        $response->assertNotFound();
        $this->assertUnchangedPlaceholder($otherFixture['business']);
    }

    // ------------------------------------------------------------------
    // VALIDATION — explicit review and confirmation
    // ------------------------------------------------------------------

    public function test_confirm_is_required(): void
    {
        $fixture = $this->draftClient();

        $response = $this->actingAsCustomer($fixture['customer'])
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload(['confirm' => null]));

        $response->assertSessionHasErrors('confirm');
        $this->assertUnchangedPlaceholder($fixture['business']);
    }

    public function test_industry_other_is_required_when_industry_is_other(): void
    {
        $fixture = $this->draftClient();

        $response = $this->actingAsCustomer($fixture['customer'])->post(
            $this->storeUrl($fixture['workspace'], $fixture['business']),
            $this->validPayload(['industry' => BusinessIndustry::Other->value, 'industry_other' => null]),
        );

        $response->assertSessionHasErrors('industry_other');
        $this->assertUnchangedPlaceholder($fixture['business']);
    }

    public function test_a_storefront_location_requires_a_real_address(): void
    {
        $fixture = $this->draftClient();

        $response = $this->actingAsCustomer($fixture['customer'])->post(
            $this->storeUrl($fixture['workspace'], $fixture['business']),
            $this->validPayload(['address_line_1' => '', 'city' => '', 'region' => '']),
        );

        $response->assertSessionHasErrors(['address_line_1', 'city', 'region']);
        $this->assertUnchangedPlaceholder($fixture['business']);
    }

    public function test_a_failed_validation_writes_nothing_and_never_activates(): void
    {
        $fixture = $this->draftClient();

        $this->actingAsCustomer($fixture['customer'])
            ->post($this->storeUrl($fixture['workspace'], $fixture['business']), $this->validPayload(['confirm' => null]));

        $this->assertUnchangedPlaceholder($fixture['business']);
    }
}
