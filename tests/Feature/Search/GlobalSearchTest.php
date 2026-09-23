<?php

namespace Tests\Feature\Search;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ChatBox;
use App\Models\Contacts;
use App\Models\Customer;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Blueprint §7/§24, Contract 17 §12.G — the Global Search foundation:
 * Contacts, Opportunities, Conversations and transactional documents,
 * inside one Business, each independently permission/entitlement/Location
 * gated. LOCATION SAFETY IS LOAD-BEARING: every assertion below proves an
 * unauthorized record produces no result, no count, no title and no URL —
 * never a filtered client-side list.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;

    private function search(array $tenant, string $q): array
    {
        $url = route('customer.workspaces.businesses.search', [$tenant['workspace']->uid, $tenant['business']->uid]);

        return $this->getJson($url . '?q=' . urlencode($q))->assertOk()->json('results');
    }

    private function otherLocation(Business $business): BusinessLocation
    {
        return BusinessLocation::create([
            'business_id' => $business->id,
            'name' => 'Other Location',
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ]);
    }

    private function staffGrantedOnly(array $tenant, BusinessLocation $location): Customer
    {
        $staff = $this->createCustomer();

        $membership = $this->createMembership($tenant['workspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);

        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);

        return $staff;
    }

    private function deal(array $tenant, string $title): int
    {
        $pipeline = app(CrmPipelineService::class)->setUpStandardPipeline($tenant['business']);

        $opportunity = app(CrmOpportunityService::class)->create($tenant['business'], $pipeline, $tenant['contact'], $title);

        return (int) $opportunity->id;
    }

    private function conversation(array $tenant, string $title = ''): int
    {
        $box = new ChatBox([
            'user_id' => $tenant['business']->customer_id,
            'business_id' => $tenant['business']->id,
            'from' => '18005550100',
            'to' => $tenant['contact']->phone,
            'reply_by_customer' => true,
        ]);
        $box->uid = (string) Str::uuid();
        $box->save();

        return (int) $box->id;
    }

    // -----------------------------------------------------------------
    // Each domain appears when authorized
    // -----------------------------------------------------------------

    public function test_contact_result_appears_when_authorized(): void
    {
        $tenant = $this->sendableTenant();
        DB::table('contacts_custom_field')->insert([
            'contact_id' => $tenant['contact']->id,
            'field_id' => \App\Models\ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $tenant['contact']->group_id, 'tag' => 'FIRST_NAME'],
                ['label' => 'First Name', 'type' => 'text', 'visible' => true, 'required' => false],
            )->id,
            'value' => 'Zendaya Marlowe',
        ]);
        $this->authenticateAs($tenant['customer']);

        $results = $this->search($tenant, 'Zendaya');

        $this->assertNotEmpty($results['contacts']);
        $this->assertSame('contacts', $results['contacts'][0]['domain']);
        $this->assertStringContainsString('Zendaya', $results['contacts'][0]['title']);
    }

    public function test_opportunity_result_appears_when_authorized(): void
    {
        $tenant = $this->sendableTenant();
        $this->deal($tenant, 'Skyline Roofing Overhaul');
        $this->authenticateAs($tenant['customer']);

        $results = $this->search($tenant, 'Skyline Roofing');

        $this->assertNotEmpty($results['opportunities']);
        $this->assertSame('Skyline Roofing Overhaul', $results['opportunities'][0]['title']);
    }

    public function test_conversation_result_appears_when_authorized(): void
    {
        $tenant = $this->sendableTenant();
        $this->conversation($tenant);
        $this->authenticateAs($tenant['customer']);

        $results = $this->search($tenant, substr($tenant['contact']->phone, -6));

        $this->assertNotEmpty($results['conversations']);
        $this->assertSame('conversations', $results['conversations'][0]['domain']);
    }

    public function test_document_result_appears_when_authorized(): void
    {
        $tenant = $this->sendableTenant();
        $this->draftDocument($tenant, ['title' => 'Backyard Deck Proposal']);
        $this->authenticateAs($tenant['customer']);

        $results = $this->search($tenant, 'Backyard Deck');

        $this->assertNotEmpty($results['documents']);
        $this->assertSame('Backyard Deck Proposal', $results['documents'][0]['title']);
    }

    // -----------------------------------------------------------------
    // Exact-Business scope
    // -----------------------------------------------------------------

    public function test_results_are_scoped_to_the_exact_business(): void
    {
        $mine = $this->sendableTenant('Mine');
        $theirs = $this->sendableTenant('Theirs');
        $this->draftDocument($theirs, ['title' => 'Foreign Document Title']);
        $this->deal($theirs, 'Foreign Opportunity Title');
        $this->authenticateAs($mine['customer']);

        $results = $this->search($mine, 'Foreign');

        $this->assertSame([], $results['documents']);
        $this->assertSame([], $results['opportunities']);
    }

    // -----------------------------------------------------------------
    // Location ACL — the load-bearing behavior
    // -----------------------------------------------------------------

    public function test_selected_and_allowed_location_works_for_a_document(): void
    {
        $tenant = $this->sendableTenant();
        $this->draftDocument($tenant, ['title' => 'Granted Location Proposal']);
        $staff = $this->staffGrantedOnly($tenant, $tenant['location']);
        $this->authenticateAs($staff);

        $results = $this->search($tenant, 'Granted Location');

        $this->assertNotEmpty($results['documents']);
    }

    public function test_a_document_at_an_inaccessible_location_is_entirely_absent(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant, ['title' => 'Hidden Location Proposal']);
        $other = $this->otherLocation($tenant['business']);
        $staff = $this->staffGrantedOnly($tenant, $other);
        $this->authenticateAs($staff);

        $results = $this->search($tenant, 'Hidden Location');

        $this->assertSame([], $results['documents']);

        // Guessing the exact id/uid changes nothing.
        $direct = $this->search($tenant, (string) $document->uid);
        $this->assertSame([], $direct['documents']);
    }

    public function test_an_opportunity_at_an_inaccessible_location_is_entirely_absent(): void
    {
        $tenant = $this->sendableTenant();
        $dealId = $this->deal($tenant, 'Location Gated Roofing Deal');
        $other = $this->otherLocation($tenant['business']);
        DB::table('crm_opportunities')->where('id', $dealId)->update(['location_id' => $other->id]);

        $staff = $this->staffGrantedOnly($tenant, $tenant['location']);
        $this->authenticateAs($staff);

        $results = $this->search($tenant, 'Location Gated Roofing');

        $this->assertSame([], $results['opportunities']);
    }

    public function test_a_conversation_at_an_inaccessible_location_is_entirely_absent(): void
    {
        $tenant = $this->sendableTenant();
        $boxId = $this->conversation($tenant);
        $other = $this->otherLocation($tenant['business']);
        DB::table('chat_boxes')->where('id', $boxId)->update(['location_id' => $other->id]);

        $staff = $this->staffGrantedOnly($tenant, $tenant['location']);
        $this->authenticateAs($staff);

        $results = $this->search($tenant, substr($tenant['contact']->phone, -6));

        $this->assertSame([], $results['conversations']);
    }

    // -----------------------------------------------------------------
    // Permission and entitlement denial
    // -----------------------------------------------------------------

    public function test_permission_denial_removes_the_relevant_domain_result(): void
    {
        $tenant = $this->sendableTenant();
        $this->draftDocument($tenant, ['title' => 'Permission Gated Proposal']);
        $this->deal($tenant, 'Permission Gated Deal');
        $this->authenticateAs(
            $tenant['customer'],
            array_values(array_diff($this->allCustomerPermissions(), ['payments_contracts']))
        );

        $results = $this->search($tenant, 'Permission Gated');

        $this->assertSame([], $results['documents'], 'Documents must be empty without the payments_contracts capability.');
        $this->assertNotEmpty($results['opportunities'], 'Opportunities is unaffected by a different domain\'s capability.');
    }

    public function test_entitlement_denial_removes_entitlement_gated_domain_results(): void
    {
        $tenant = $this->sendableTenant();
        $this->deal($tenant, 'Entitlement Gated Deal');
        app(EntitlementManager::class)->createOrChangeOverride(
            $tenant['workspace'],
            PlatformFeature::Crm,
            WorkspaceEntitlementOverrideState::Deny,
            $this->platformAdminId(),
            'Global Search test: deny CRM.'
        );
        $this->authenticateAs($tenant['customer']);

        $results = $this->search($tenant, 'Entitlement Gated');

        $this->assertSame([], $results['opportunities']);
    }

    // -----------------------------------------------------------------
    // No leak by construction
    // -----------------------------------------------------------------

    public function test_a_foreign_query_text_cannot_reveal_a_result(): void
    {
        $tenant = $this->sendableTenant();
        $this->draftDocument($tenant, ['title' => 'Ordinary Proposal']);
        $this->authenticateAs($tenant['customer']);

        $results = $this->search($tenant, 'Something That Matches Nothing At All');

        $this->assertSame([], $results['documents']);
        $this->assertSame([], $results['contacts']);
        $this->assertSame([], $results['opportunities']);
        $this->assertSame([], $results['conversations']);
    }

    public function test_document_result_exposes_no_token_or_provider_secret(): void
    {
        $tenant = $this->sendableTenant();
        [$document, $token] = $this->sendAndCaptureToken($this->draftDocument($tenant, ['title' => 'Secret Bearing Proposal']));
        $this->authenticateAs($tenant['customer']);

        $body = $this->getJson(
            route('customer.workspaces.businesses.search', [$tenant['workspace']->uid, $tenant['business']->uid]) . '?q=Secret+Bearing'
        )->assertOk()->getContent();

        foreach ([$token, (string) $document->access_token_expires_at] as $secret) {
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $this->assertStringNotContainsString('access_token', $body);
        $this->assertStringNotContainsString('stripe_account', $body);
        $this->assertStringNotContainsString('client_secret', $body);
        $this->assertStringNotContainsString('provider_payment_intent', $body);
        $this->assertStringNotContainsString('provider_charge', $body);
        $this->assertStringNotContainsString('provider_refund', $body);
    }

    // -----------------------------------------------------------------
    // Links and bounds
    // -----------------------------------------------------------------

    public function test_result_links_resolve_to_canonical_authorized_screens(): void
    {
        $tenant = $this->sendableTenant();
        $document = $this->draftDocument($tenant, ['title' => 'Canonical Link Proposal']);
        $this->authenticateAs($tenant['customer']);

        $results = $this->search($tenant, 'Canonical Link');

        $this->assertSame(
            route('customer.workspaces.businesses.documents.show', [$tenant['workspace']->uid, $tenant['business']->uid, $document->uid]),
            $results['documents'][0]['url'],
        );
        $this->get($results['documents'][0]['url'])->assertOk();
    }

    public function test_query_and_result_count_is_bounded(): void
    {
        $tenant = $this->sendableTenant();

        for ($i = 1; $i <= 12; $i++) {
            $this->draftDocument($tenant, ['title' => 'Bounded Proposal ' . $i]);
        }

        $this->authenticateAs($tenant['customer']);

        $results = $this->search($tenant, 'Bounded Proposal');

        $this->assertLessThanOrEqual(
            \App\Library\Search\GlobalSearchCoordinator::PER_SOURCE_LIMIT,
            count($results['documents'])
        );
    }
}
