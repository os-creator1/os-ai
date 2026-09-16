<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ChatBox;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 08B Part 1/§6 — LocationAccessGuard wired into
 * ChatBoxController's read/write actions.
 *
 * Mirrors ChatBoxSecurityTest's own Business-tenancy adversarial shape,
 * adding the Location axis on top: a Selected-Location-scope staff member
 * may reach only conversations at their granted Location, an All-scope
 * actor and the owner are unaffected, and a route/client-supplied
 * location_id can never substitute for the persisted one.
 */
class ChatBoxLocationAclTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private function location(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    private function box(Business $business, string $from, string $to, array $overrides = []): ChatBox
    {
        $box = new ChatBox(array_merge([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'from' => $from,
            'to' => $to,
            'reply_by_customer' => true,
        ], $overrides));
        $box->uid = (string) Str::uuid();
        $box->save();

        return $box;
    }

    private function conversationUrl(string $action, Workspace $workspace, Business $business, ?string $uid = null): string
    {
        $parameters = [$workspace->uid, $business->uid];

        if ($uid !== null) {
            $parameters[] = $uid;
        }

        return route('customer.workspaces.businesses.conversations.' . $action, $parameters);
    }

    public function test_selected_location_scope_with_correct_grant_is_allowed(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $location = $this->location($business);
        $box = $this->box($business, '14155550100', '14155559001', ['location_id' => $location->id]);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        $this->authenticateAs($staff, ['chat_box']);

        $this->postJson($this->conversationUrl('messages', $workspace, $business, $box->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);
    }

    public function test_selected_location_scope_with_no_grant_is_denied(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $location = $this->location($business);
        $box = $this->box($business, '14155550101', '14155559002', ['location_id' => $location->id]);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        $this->authenticateAs($staff, ['chat_box']);

        $this->postJson($this->conversationUrl('messages', $workspace, $business, $box->uid))->assertNotFound();
    }

    public function test_selected_location_scope_with_only_a_sibling_locations_grant_is_denied(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $location = $this->location($business);
        $siblingLocation = $this->location($business);
        $box = $this->box($business, '14155550102', '14155559003', ['location_id' => $location->id]);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $siblingLocation);
        $this->authenticateAs($staff, ['chat_box']);

        $this->postJson($this->conversationUrl('messages', $workspace, $business, $box->uid))->assertNotFound();
    }

    public function test_all_location_scope_reaches_any_location_in_the_business(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $location = $this->location($business);
        $box = $this->box($business, '14155550103', '14155559004', ['location_id' => $location->id]);

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff, ['chat_box']);

        $this->postJson($this->conversationUrl('messages', $workspace, $business, $box->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);
    }

    public function test_owner_reaches_any_location_regardless_of_membership(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $location = $this->location($business);
        $box = $this->box($business, '14155550104', '14155559005', ['location_id' => $location->id]);

        $this->authenticateAs($owner, ['chat_box']);

        $this->postJson($this->conversationUrl('messages', $workspace, $business, $box->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);
    }

    public function test_a_null_location_conversation_is_unaffected_by_location_acl(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $box = $this->box($business, '14155550105', '14155559006', ['location_id' => null]);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        $this->authenticateAs($staff, ['chat_box']);

        // No Location grant at all, yet allowed: a NULL location_id never
        // gates access on its own, and the actor's Business-level All
        // scope already governs, exactly as before this contract.
        $this->postJson($this->conversationUrl('messages', $workspace, $business, $box->uid))
            ->assertOk()
            ->assertJson(['status' => 'success']);
    }

    /**
     * Foreign-Business/Workspace tenancy failures remain refused before
     * Location is ever considered — proven by reusing the exact route
     * shape a Location-granted actor would otherwise succeed on.
     */
    public function test_foreign_business_remains_denied_even_with_a_location_grant_elsewhere(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'A ' . uniqid(), 'WS A ' . uniqid());
        [$ownerB, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'B ' . uniqid(), 'WS B ' . uniqid());
        $locationB = $this->location($businessB);
        $boxB = $this->box($businessB, '14155550106', '14155559007', ['location_id' => $locationB->id]);

        $staff = $this->createCustomer();
        $membershipA = $this->member($workspaceA, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membershipA->forceFill(['location_access_scope' => LocationAccessScope::All])->save();
        $this->authenticateAs($staff, ['chat_box']);

        // Wrong Workspace/Business pair entirely — denied before Location
        // is ever reached.
        $this->postJson($this->conversationUrl('messages', $workspaceA, $businessB, $boxB->uid))->assertNotFound();
    }

    /**
     * A client-supplied location_id has no route parameter to forge here
     * (the resolved ChatBox's own persisted column is the only source),
     * proven by confirming that assigning a grant for a DIFFERENT,
     * unrelated Location than the box's true one still denies.
     */
    public function test_a_grant_for_an_unrelated_location_cannot_substitute_for_the_persisted_one(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $trueLocation = $this->location($business);
        $otherLocation = $this->location($business);
        $box = $this->box($business, '14155550107', '14155559008', ['location_id' => $trueLocation->id]);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $otherLocation);
        $this->authenticateAs($staff, ['chat_box']);

        $this->postJson($this->conversationUrl('messages', $workspace, $business, $box->uid))->assertNotFound();
    }
}
