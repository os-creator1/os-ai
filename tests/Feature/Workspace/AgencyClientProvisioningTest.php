<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Enums\Workspace\ClientInvitationStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Exceptions\Workspace\InvalidClientInvitationClaimException;
use App\Exceptions\Workspace\UnauthorizedAgencyRelationshipManagementException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\ViewAs\ViewAsManager;
use App\Library\Workspace\AgencyClientProvisioningManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Library\Workspace\ClientInvitationManager;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use App\Models\BusinessPayerAssignment;
use App\Models\ClientWorkspaceInvitation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Workspace\ClientInvitationNotification;
use App\Repositories\Contracts\ClientWorkspaceInvitationRepository;
use App\Repositories\Eloquent\EloquentClientWorkspaceInvitationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use RuntimeException;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 07 §5/§6/§7 — AgencyClientProvisioningManager: the
 * single atomic acceptance orchestrator, plus end-to-end proof that the
 * result it creates is a real, usable Client Workspace under Contract 01's
 * relationship and Contract 04's Agency View As.
 *
 * ClientInvitationManager itself is exercised in ClientInvitationManagerTest;
 * this file starts from an already-Pending invitation and focuses entirely
 * on what happens at acceptance: authentication/email-match, atomicity, the
 * fail-closed re-validation Contract 01's create() performs, single-use, and
 * the resulting Client Workspace's shape.
 */
class AgencyClientProvisioningTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Burns user id 1 on the platform administrator before any actor
        // exists, matching every other Contract 01/04/07 test in this
        // suite — EloquentAccountRepository::hasPermission() short-circuits
        // that id to "every permission".
        $this->platformAdminId();

        // Required by any route that resolves through RedirectIfAuthenticated
        // (Helper::app_config() throws on a missing row) — the 2FA
        // verify.store route this file's new tests exercise is one of
        // them. tenant()'s own fixture calls this internally; the plain
        // agency()/realAccount() helpers here do not, so it is called once
        // for the whole class instead.
        $this->ensureRequiredAppConfigRowsExist();

        Notification::fake();
    }

    private function invitations(): ClientInvitationManager
    {
        return app(ClientInvitationManager::class);
    }

    private function provisioning(): AgencyClientProvisioningManager
    {
        return app(AgencyClientProvisioningManager::class);
    }

    private function relationships(): AgencyClientRelationshipManager
    {
        return app(AgencyClientRelationshipManager::class);
    }

    /** @return array{0: Workspace, 1: User} */
    private function agency(string $name = 'Northwind Agency'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);

        return [$workspace->fresh(), $customer->user->fresh()];
    }

    private function memberOf(Workspace $workspace, WorkspaceMembershipRole $role, bool $active = true): User
    {
        $customer = $this->createCustomer();
        $customer->permissions = json_encode([]);
        $customer->save();

        $this->createMembership($workspace, $customer->user, [
            'role' => $role,
            'is_active' => $active,
        ]);

        return $customer->user->fresh();
    }

    /**
     * A real authenticated-capable User+Customer, standing in for either an
     * ordinary registration (new email) or an ordinary login (existing
     * email) — either way, a real global identity that already exists
     * before accept() is ever called, exactly as Contract 07 §5/§6 require.
     */
    private function realAccount(string $email): User
    {
        $user = User::create([
            'first_name' => 'Jamie',
            'last_name' => 'Client',
            'email' => $email,
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'email_verified_at' => now(),
        ]);

        Customer::create(['user_id' => $user->id]);

        return $user->fresh();
    }

    /**
     * @return array{0: ClientWorkspaceInvitation, 1: string} the invitation
     *         row and its plaintext token
     */
    private function pendingInvitation(Workspace $agency, User $sender, string $email, ?string $businessName = 'Acme Dental'): array
    {
        $invitation = $this->invitations()->send((int) $sender->id, $agency, $email, $businessName);
        $plaintext = $this->capturedToken($invitation);

        return [$invitation, $plaintext];
    }

    /** Requires Notification::fake() to already be active. */
    private function capturedToken(ClientWorkspaceInvitation $invitation): string
    {
        $captured = null;

        Notification::assertSentOnDemand(
            ClientInvitationNotification::class,
            function (ClientInvitationNotification $notification, array $channels, object $notifiable) use ($invitation, &$captured) {
                $ref = new ReflectionClass($notification);

                $uidProperty = $ref->getProperty('invitationUid');
                $uidProperty->setAccessible(true);

                if ($uidProperty->getValue($notification) !== $invitation->uid) {
                    return false;
                }

                $tokenProperty = $ref->getProperty('plaintextToken');
                $tokenProperty->setAccessible(true);
                $captured = $tokenProperty->getValue($notification);

                return true;
            },
        );

        $this->assertNotNull($captured, 'Expected a ClientInvitationNotification to have been sent for this invitation.');

        return $captured;
    }

    /**
     * Matches CreatesCustomerContextFixtures::addBusiness()'s own
     * raw-DB status flip: Business activation is the platform Admin's own
     * BusinessController::updateStatus() action everywhere in this
     * codebase, never automatic, so the View As end-to-end tests below
     * apply that exact same real-world step, not a Contract-07-specific
     * activation this slice does not own.
     */
    private function activateBusiness(Workspace $workspace): void
    {
        $business = Business::where('workspace_id', $workspace->id)->firstOrFail();
        DB::table('businesses')->where('id', $business->id)->update([
            'status' => \App\Enums\Business\BusinessStatus::Active->value,
            'activated_at' => now(),
        ]);
    }

    private function assertNoProvisioningSideEffects(int $workspaceCountBefore, int $businessCountBefore, int $relationshipCountBefore): void
    {
        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($businessCountBefore, Business::count());
        $this->assertSame($relationshipCountBefore, AgencyClientWorkspaceRelationship::count());
    }

    // ------------------------------------------------------------------
    // Happy path — new email (ordinary registration) and existing email
    // (ordinary login)
    // ------------------------------------------------------------------

    public function test_a_brand_new_email_can_accept_once_a_real_account_exists_and_is_authenticated(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'newclient' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email, 'Bright Smile Dental');

        // Flow B: the "new email" branch is an ordinary registration that
        // happens entirely outside this class (RegisterController, per
        // Contract 07 §12, is never touched here) — by the time accept()
        // runs, a real User/Customer already exists.
        $newAccount = $this->realAccount($email);

        $accepted = $this->provisioning()->accept($newAccount, $invitation->uid, $token);

        $this->assertSame(ClientInvitationStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->created_client_workspace_id);

        $clientWorkspace = Workspace::find($accepted->created_client_workspace_id);
        $this->assertNotNull($clientWorkspace);
        $this->assertSame((int) $newAccount->id, (int) $clientWorkspace->owner_user_id);
    }

    public function test_an_existing_email_can_accept_via_an_ordinary_authenticated_session(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'existingclient' . uniqid('', true) . '@example.test';
        $existingAccount = $this->realAccount($email);

        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);

        // Flow B: the "existing email" branch is an ordinary, explicit
        // login — modelled here simply as an already-authenticated User
        // being handed to accept(), the same shape the controller passes
        // Auth::user() through.
        $accepted = $this->provisioning()->accept($existingAccount, $invitation->uid, $token);

        $this->assertSame(ClientInvitationStatus::Accepted, $accepted->status);
        $clientWorkspace = Workspace::find($accepted->created_client_workspace_id);
        $this->assertSame((int) $existingAccount->id, (int) $clientWorkspace->owner_user_id);
    }

    // ------------------------------------------------------------------
    // Clarification A — email match is mandatory, fail-closed
    // ------------------------------------------------------------------

    public function test_an_authenticated_user_with_a_different_email_cannot_accept_and_creates_nothing(): void
    {
        [$agency, $owner] = $this->agency();
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, 'invited@example.test');

        $wrongUser = $this->realAccount('someone-else' . uniqid('', true) . '@example.test');

        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();
        $relationshipCountBefore = AgencyClientWorkspaceRelationship::count();

        try {
            $this->provisioning()->accept($wrongUser, $invitation->uid, $token);
            $this->fail('Expected InvalidClientInvitationClaimException.');
        } catch (InvalidClientInvitationClaimException) {
            // expected — generic, no disclosure of which check failed
        }

        $this->assertNoProvisioningSideEffects($workspaceCountBefore, $businessCountBefore, $relationshipCountBefore);

        $fresh = $invitation->fresh();
        $this->assertSame(ClientInvitationStatus::Pending, $fresh->status);
        $this->assertNull($fresh->created_client_workspace_id);
        $this->assertNull($fresh->accepted_at);
    }

    public function test_the_agency_cannot_accept_on_the_clients_behalf(): void
    {
        [$agency, $owner] = $this->agency();
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, 'invited' . uniqid('', true) . '@example.test');

        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();
        $relationshipCountBefore = AgencyClientWorkspaceRelationship::count();

        // The Agency owner is a real, authenticated User too — but their
        // own email never matches the invited email, so they are refused
        // exactly like any other wrong-email actor.
        $this->expectException(InvalidClientInvitationClaimException::class);

        try {
            $this->provisioning()->accept($owner, $invitation->uid, $token);
        } finally {
            $this->assertNoProvisioningSideEffects($workspaceCountBefore, $businessCountBefore, $relationshipCountBefore);
        }
    }

    // ------------------------------------------------------------------
    // Token/status validity, re-checked at accept() under the lock
    // ------------------------------------------------------------------

    public function test_an_invalid_token_is_refused_at_accept(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation] = $this->pendingInvitation($agency, $owner, $email);
        $account = $this->realAccount($email);

        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->provisioning()->accept($account, $invitation->uid, 'not-the-real-token');
    }

    public function test_an_expired_invitation_is_refused_at_accept(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);
        $invitation->expires_at = now()->subMinute();
        $invitation->save();
        $account = $this->realAccount($email);

        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->provisioning()->accept($account, $invitation->uid, $token);
    }

    public function test_a_revoked_invitation_is_refused_at_accept(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);
        $this->invitations()->revoke((int) $owner->id, $invitation);
        $account = $this->realAccount($email);

        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->provisioning()->accept($account, $invitation->uid, $token);
    }

    // ------------------------------------------------------------------
    // Agency state changes BEFORE acceptance — Clarification C, fail closed
    // ------------------------------------------------------------------

    public function test_the_inviting_actors_authority_is_revalidated_and_fails_closed_if_lost(): void
    {
        [$agency] = $this->agency();
        $sendingAdmin = $this->memberOf($agency, WorkspaceMembershipRole::Admin);
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $sendingAdmin, $email);

        // The inviting Admin is removed from the Agency Workspace entirely
        // before the Client ever accepts.
        DB::table('workspace_memberships')
            ->where('workspace_id', $agency->id)
            ->where('user_id', $sendingAdmin->id)
            ->delete();
        app(\App\Library\Support\RequestScopedCache::class)->flush();

        $account = $this->realAccount($email);
        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();

        $this->expectException(UnauthorizedAgencyRelationshipManagementException::class);

        try {
            $this->provisioning()->accept($account, $invitation->uid, $token);
        } finally {
            // Contract 01's create() throws before any of its own writes,
            // but Workspace/Business/Location were already created earlier
            // in THIS transaction — the outer transaction must still roll
            // every one of them back.
            $this->assertNoProvisioningSideEffects($workspaceCountBefore, $businessCountBefore, 0);
            $fresh = $invitation->fresh();
            $this->assertSame(ClientInvitationStatus::Pending, $fresh->status);
            $this->assertNull($fresh->created_client_workspace_id);
        }
    }

    public function test_a_locked_agency_cannot_complete_acceptance_and_the_whole_transaction_rolls_back(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);

        app(\App\Library\Entitlement\EntitlementManager::class)->enterGracePeriod($agency);
        app(\App\Library\Entitlement\EntitlementManager::class)->lockForNonPayment($agency);
        app(\App\Library\Support\RequestScopedCache::class)->flush();

        $account = $this->realAccount($email);
        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();
        $locationCountBefore = DB::table('business_locations')->count();

        $this->expectException(AgencyWorkspaceNotEligibleException::class);

        try {
            $this->provisioning()->accept($account, $invitation->uid, $token);
        } finally {
            // This is the atomicity proof (§7): by the time
            // AgencyClientRelationshipManager::create() refuses, this
            // transaction had ALREADY created the Client Workspace, its
            // Business, and its primary Location. All three must vanish
            // with the rollback — no orphan Client Workspace.
            $this->assertSame($workspaceCountBefore, Workspace::count());
            $this->assertSame($businessCountBefore, Business::count());
            $this->assertSame($locationCountBefore, DB::table('business_locations')->count());
            $this->assertSame(0, AgencyClientWorkspaceRelationship::count());

            $fresh = $invitation->fresh();
            $this->assertSame(ClientInvitationStatus::Pending, $fresh->status);
            $this->assertNull($fresh->created_client_workspace_id);
            $this->assertNull($fresh->accepted_at);
        }
    }

    public function test_an_inactive_agency_plan_cannot_complete_acceptance(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);

        app(\App\Library\Entitlement\EntitlementManager::class)->changePlanStatus(
            $agency,
            \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Inactive,
            $this->platformAdminId(),
            'Test: plan made inactive before acceptance.',
        );
        app(\App\Library\Support\RequestScopedCache::class)->flush();

        $account = $this->realAccount($email);

        $this->expectException(AgencyWorkspaceNotEligibleException::class);

        $this->provisioning()->accept($account, $invitation->uid, $token);

        $this->assertSame(0, AgencyClientWorkspaceRelationship::count());
    }

    public function test_a_suspended_agency_plan_cannot_complete_acceptance(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);

        app(\App\Library\Entitlement\EntitlementManager::class)->changePlanStatus(
            $agency,
            \App\Enums\Entitlement\WorkspacePlanAssignmentStatus::Suspended,
            $this->platformAdminId(),
            'Test: plan suspended before acceptance.',
        );
        app(\App\Library\Support\RequestScopedCache::class)->flush();

        $account = $this->realAccount($email);

        $this->expectException(AgencyWorkspaceNotEligibleException::class);

        $this->provisioning()->accept($account, $invitation->uid, $token);
    }

    // ------------------------------------------------------------------
    // Atomicity — forced failure at the very last step (marking Accepted)
    // ------------------------------------------------------------------

    public function test_a_failure_while_marking_the_invitation_accepted_rolls_back_everything_including_the_relationship(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);
        $account = $this->realAccount($email);

        // A repository decorator that performs the real markAccepted()
        // write and then throws — proving the DB::transaction() wrapping
        // accept() is truly ONE outer transaction all the way through its
        // last step, not just through relationship creation.
        $this->app->bind(ClientWorkspaceInvitationRepository::class, function ($app) {
            return new class($app->make(\App\Models\ClientWorkspaceInvitation::class)) extends EloquentClientWorkspaceInvitationRepository {
                public function markAccepted(\App\Models\ClientWorkspaceInvitation $invitation, int $createdClientWorkspaceId): \App\Models\ClientWorkspaceInvitation
                {
                    parent::markAccepted($invitation, $createdClientWorkspaceId);

                    throw new RuntimeException('Forced failure after marking the invitation accepted, for atomicity testing.');
                }
            };
        });

        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();

        try {
            $this->provisioning()->accept($account, $invitation->uid, $token);
            $this->fail('Expected the forced RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Forced failure', $e->getMessage());
        }

        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($businessCountBefore, Business::count());
        $this->assertSame(0, AgencyClientWorkspaceRelationship::count());

        $fresh = ClientWorkspaceInvitation::query()->where('uid', $invitation->uid)->first();
        $this->assertSame(ClientInvitationStatus::Pending, $fresh->status);
        $this->assertNull($fresh->created_client_workspace_id);
        $this->assertNull($fresh->accepted_at);
    }

    // ------------------------------------------------------------------
    // Single-use / already-Accepted
    // ------------------------------------------------------------------

    public function test_an_already_accepted_invitation_cannot_be_accepted_again(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);
        $account = $this->realAccount($email);

        $first = $this->provisioning()->accept($account, $invitation->uid, $token);
        $this->assertSame(ClientInvitationStatus::Accepted, $first->status);

        $workspaceCountAfterFirst = Workspace::count();

        $this->expectException(InvalidClientInvitationClaimException::class);

        try {
            $this->provisioning()->accept($account, $invitation->uid, $token);
        } finally {
            // No second Client Workspace, Business, or relationship.
            $this->assertSame($workspaceCountAfterFirst, Workspace::count());
            $this->assertSame(1, AgencyClientWorkspaceRelationship::count());
        }
    }

    // ------------------------------------------------------------------
    // Existing global identity — other memberships/Workspaces untouched
    // ------------------------------------------------------------------

    public function test_an_existing_users_unrelated_workspace_and_memberships_are_unchanged(): void
    {
        $existingEmail = 'busy-owner' . uniqid('', true) . '@example.test';
        $existingAccount = $this->realAccount($existingEmail);

        // This same global User already owns an unrelated Workspace, and is
        // separately a Staff member of a second unrelated Workspace.
        $ownedWorkspace = $this->createWorkspace($existingAccount, ['name' => 'Their Own Studio']);
        [$otherAgency] = $this->agency('Other Agency');
        $this->createMembership($otherAgency, $existingAccount, [
            'role' => WorkspaceMembershipRole::Staff,
            'is_active' => true,
        ]);

        [$agency, $owner] = $this->agency('Inviting Agency');
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $existingEmail);

        $accepted = $this->provisioning()->accept($existingAccount, $invitation->uid, $token);

        $newWorkspace = Workspace::find($accepted->created_client_workspace_id);

        $this->assertNotSame((int) $ownedWorkspace->id, (int) $newWorkspace->id);
        $this->assertNotNull($ownedWorkspace->fresh());
        $this->assertSame((int) $existingAccount->id, (int) $ownedWorkspace->fresh()->owner_user_id);

        $stillAMember = DB::table('workspace_memberships')
            ->where('workspace_id', $otherAgency->id)
            ->where('user_id', $existingAccount->id)
            ->where('is_active', true)
            ->exists();
        $this->assertTrue($stillAMember, 'The existing Workspace membership must be untouched by acceptance.');
    }

    public function test_a_new_client_workspace_is_always_new_never_a_reused_existing_workspace(): void
    {
        $email = 'twice-invited' . uniqid('', true) . '@example.test';
        $account = $this->realAccount($email);

        [$agencyA, $ownerA] = $this->agency('Agency A');
        [$agencyB, $ownerB] = $this->agency('Agency B');

        [$invitationA, $tokenA] = $this->pendingInvitation($agencyA, $ownerA, $email, 'First Co');
        $acceptedA = $this->provisioning()->accept($account, $invitationA->uid, $tokenA);
        $firstWorkspaceId = (int) $acceptedA->created_client_workspace_id;

        [$invitationB, $tokenB] = $this->pendingInvitation($agencyB, $ownerB, $email, 'Second Co');
        $acceptedB = $this->provisioning()->accept($account, $invitationB->uid, $tokenB);
        $secondWorkspaceId = (int) $acceptedB->created_client_workspace_id;

        $this->assertNotSame($firstWorkspaceId, $secondWorkspaceId);
        $this->assertSame((int) $account->id, (int) Workspace::find($firstWorkspaceId)->owner_user_id);
        $this->assertSame((int) $account->id, (int) Workspace::find($secondWorkspaceId)->owner_user_id);
        $this->assertSame(2, AgencyClientWorkspaceRelationship::where('client_workspace_id', '!=', null)
            ->whereIn('client_workspace_id', [$firstWorkspaceId, $secondWorkspaceId])
            ->count());
    }

    // ------------------------------------------------------------------
    // Exactly-one shape of the provisioned result
    // ------------------------------------------------------------------

    public function test_acceptance_creates_exactly_one_business_one_primary_location_and_one_active_relationship(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email, 'Sunrise Cafe');
        $account = $this->realAccount($email);

        $accepted = $this->provisioning()->accept($account, $invitation->uid, $token);
        $clientWorkspaceId = (int) $accepted->created_client_workspace_id;

        $businesses = Business::where('workspace_id', $clientWorkspaceId)->get();
        $this->assertCount(1, $businesses);
        $this->assertSame('Sunrise Cafe', $businesses->first()->name);

        $primaryLocations = DB::table('business_locations')
            ->where('business_id', $businesses->first()->id)
            ->where('is_primary', true)
            ->get();
        $this->assertCount(1, $primaryLocations);

        $relationships = AgencyClientWorkspaceRelationship::where('agency_workspace_id', $agency->id)
            ->where('client_workspace_id', $clientWorkspaceId)
            ->get();
        $this->assertCount(1, $relationships);
        $this->assertSame(AgencyClientRelationshipStatus::Active, $relationships->first()->status);
    }

    public function test_the_relationship_established_by_user_id_is_the_inviting_agency_actor_not_the_accepting_client(): void
    {
        [$agency] = $this->agency();
        $sendingAdmin = $this->memberOf($agency, WorkspaceMembershipRole::Admin);
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $sendingAdmin, $email);
        $account = $this->realAccount($email);

        $accepted = $this->provisioning()->accept($account, $invitation->uid, $token);

        $relationship = AgencyClientWorkspaceRelationship::where('client_workspace_id', $accepted->created_client_workspace_id)->first();

        $this->assertSame((int) $sendingAdmin->id, (int) $relationship->established_by_user_id);
        $this->assertNotSame((int) $account->id, (int) $relationship->established_by_user_id);
        $this->assertSame((int) $invitation->invited_by_user_id, (int) $relationship->established_by_user_id);
    }

    public function test_the_invitation_records_accepted_at_and_the_created_client_workspace_id(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);
        $account = $this->realAccount($email);

        $before = now();
        $accepted = $this->provisioning()->accept($account, $invitation->uid, $token);

        $this->assertSame(ClientInvitationStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertTrue($accepted->accepted_at->greaterThanOrEqualTo($before->subSecond()));
        $this->assertSame((int) $accepted->created_client_workspace_id, (int) Workspace::where('owner_user_id', $account->id)->latest('id')->first()->id);
    }

    public function test_provisioning_grants_no_payer_or_agencyrebill_authority(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);
        $account = $this->realAccount($email);

        $accepted = $this->provisioning()->accept($account, $invitation->uid, $token);
        $business = Business::where('workspace_id', $accepted->created_client_workspace_id)->first();

        // BusinessCreated's own existing listener (InitializeBusinessUsageProfile)
        // idempotently initializes an ordinary default payer assignment for
        // every new Business — that is standard, pre-existing behavior this
        // slice does not disable. The one thing Contract 07 forbids is this
        // slice ITSELF ever assigning the AgencyRebill payer type or any
        // AgencyRebill consent — it never does either.
        $assignment = BusinessPayerAssignment::where('business_id', $business->id)->first();

        if ($assignment !== null) {
            $this->assertNotSame(\App\Enums\Usage\PayerType::AgencyRebill, $assignment->payer_type);
        }

        $this->assertNull($assignment?->managing_agency_relationship_id);
    }

    // ------------------------------------------------------------------
    // End to end — Contract 04 Agency View As
    // ------------------------------------------------------------------

    public function test_the_newly_created_client_workspace_is_immediately_a_valid_agency_view_as_target(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email, 'Fresh Cuts Salon');
        $account = $this->realAccount($email);

        $accepted = $this->provisioning()->accept($account, $invitation->uid, $token);
        $clientWorkspace = Workspace::find($accepted->created_client_workspace_id);

        // Every Business created anywhere in this codebase — organic
        // onboarding's own applyIdentity() CREATE branch included — starts
        // Draft; BusinessRepository::updateStatus(Active) is reached only
        // by the platform Admin's own BusinessController::updateStatus(),
        // never automatically. Contract 07 does not add a new business-
        // activation policy (that would duplicate/invent lifecycle policy
        // this slice has no mandate to own), so this test proves the
        // Contract 01 relationship this flow created is what makes View As
        // work, exactly like AgencyViewAsTest's own clientAccount() fixture
        // activates a Business the same way for the identical reason.
        $this->activateBusiness($clientWorkspace);

        $session = app(ViewAsManager::class)->startAgencyView($owner, $agency->uid, $clientWorkspace->uid, 'Onboarding check');

        $this->assertSame((int) $agency->id, (int) $session->viewing_agency_workspace_id);
        $this->assertSame((int) $clientWorkspace->id, (int) $session->workspace_id);
    }

    public function test_agency_view_as_of_the_freshly_provisioned_client_still_creates_no_client_membership(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email);
        $account = $this->realAccount($email);

        $accepted = $this->provisioning()->accept($account, $invitation->uid, $token);
        $clientWorkspace = Workspace::find($accepted->created_client_workspace_id);
        $this->activateBusiness($clientWorkspace);

        app(ViewAsManager::class)->startAgencyView($owner, $agency->uid, $clientWorkspace->uid);

        $membershipExists = DB::table('workspace_memberships')
            ->where('workspace_id', $clientWorkspace->id)
            ->where('user_id', $owner->id)
            ->exists();

        $this->assertFalse($membershipExists);
    }

    // ------------------------------------------------------------------
    // Final correction round, item 2 — 2FA bypass on invitation acceptance.
    // routes/web.php carries only the 'web' middleware group, so 'auth'
    // alone let a User who has completed only the FIRST factor (password)
    // reach real provisioning while a 2FA challenge was still pending.
    // Both client-invitations.claim (GET) and .accept (POST) now also
    // carry 'twofactor'.
    // ------------------------------------------------------------------

    /**
     * Matches CustomerAccountAccessGateTest::markPendingTwoFactor()'s own
     * convention exactly, adapted to operate on a User directly (this
     * file's realAccount() returns a User, not a Customer tuple).
     *
     * @return int the plaintext pending code, for a test that needs to
     *   submit it back
     */
    private function markPendingTwoFactor(User $user): int
    {
        config(['app.two_factor' => true]);

        $customer = $user->customer;
        $customer->permissions = json_encode($this->allCustomerPermissions());
        $customer->save();

        $user = $user->fresh();
        $user->two_factor = true;
        $user->generateTwoFactorCode();
        $user->save();

        // Rebind the guard to the freshly-mutated instance -- the same
        // object TwoFactor middleware will read auth()->user() as.
        $this->actingAs($user);

        return $user->two_factor_code;
    }

    public function test_a_pending_two_factor_challenge_blocks_both_claim_and_accept_until_completed(): void
    {
        [$agency, $owner] = $this->agency();
        $email = 'twofactor-client' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $owner, $email, 'Two Factor Co');
        $account = $this->realAccount($email);

        $code = $this->markPendingTwoFactor($account);

        $workspaceCountBefore = Workspace::count();
        $businessCountBefore = Business::count();

        // GET claim -> redirected to the 2FA challenge, never the claim
        // page itself, while the challenge is pending.
        $this->get(route('client-invitations.claim', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect(route('verify.index'));

        // POST accept -> the same redirect, and no provisioning occurs.
        $this->post(route('client-invitations.accept', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect(route('verify.index'));

        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($businessCountBefore, Business::count());
        $this->assertSame(0, AgencyClientWorkspaceRelationship::count());
        $fresh = $invitation->fresh();
        $this->assertSame(ClientInvitationStatus::Pending, $fresh->status);
        $this->assertNull($fresh->created_client_workspace_id);

        // Complete the 2FA challenge using the existing test convention.
        $this->post(route('verify.store'), ['two_factor_code' => (string) $code])
            ->assertRedirect();

        // The SAME invitation now proceeds normally: GET shows the
        // confirmation page, and POST accept provisions for real.
        $this->get(route('client-invitations.claim', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertOk()
            ->assertViewIs('client_invitations.confirm');

        $this->post(route('client-invitations.accept', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect();

        $accepted = $invitation->fresh();
        $this->assertSame(ClientInvitationStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->created_client_workspace_id);
    }

    // ------------------------------------------------------------------
    // Final correction round, item 3 — invitation routes must not inherit
    // an unrelated Workspace's lock from CustomerAccountAccessGate (global
    // 'web' middleware group). client-invitations.claim/.accept carry no
    // {workspaceUid}, so without an explicit allowlist entry they fell to
    // the workspace-agnostic resolution path, keyed off the actor's OTHER,
    // wholly unrelated Workspace(s).
    // ------------------------------------------------------------------

    public function test_a_locked_unrelated_workspace_does_not_block_claim_and_acceptance_still_succeeds(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        $email = 'locked-unrelated' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $agencyOwner, $email, 'New Co');
        $account = $this->realAccount($email);

        $lockedWorkspace = $this->createWorkspace($account, ['name' => 'Old Locked Co']);
        $this->assignTier($lockedWorkspace, WorkspacePlanTier::Core);
        app(EntitlementManager::class)->enterGracePeriod($lockedWorkspace);
        app(EntitlementManager::class)->lockForNonPayment($lockedWorkspace);

        $this->actingAs($account);

        // The claim route remains reachable despite the owned, Locked,
        // wholly unrelated Workspace.
        $this->get(route('client-invitations.claim', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertOk()
            ->assertViewIs('client_invitations.confirm');

        $this->post(route('client-invitations.accept', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect();

        $accepted = $invitation->fresh();
        $this->assertSame(ClientInvitationStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->created_client_workspace_id);

        // The old, unrelated, locked Workspace is completely unchanged --
        // still locked, not silently reactivated by this flow.
        $this->assertTrue(app(\App\Library\Entitlement\CustomerAccountAccessResolver::class)->resolve($lockedWorkspace->fresh())->isLocked());
    }

    public function test_an_inactive_unrelated_workspace_does_not_block_claim_and_acceptance_still_succeeds(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        $email = 'inactive-unrelated' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $agencyOwner, $email, 'New Co');
        $account = $this->realAccount($email);

        $inactiveWorkspace = $this->createWorkspace($account, ['name' => 'Old Inactive Co']);
        $this->assignTier($inactiveWorkspace, WorkspacePlanTier::Core);
        app(EntitlementManager::class)->changePlanStatus($inactiveWorkspace, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Fixture.');

        $this->actingAs($account);

        $this->get(route('client-invitations.claim', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertOk();

        $this->post(route('client-invitations.accept', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect();

        $accepted = $invitation->fresh();
        $this->assertSame(ClientInvitationStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->created_client_workspace_id);
    }

    public function test_a_suspended_unrelated_workspace_does_not_block_claim_and_acceptance_still_succeeds(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        $email = 'suspended-unrelated' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $agencyOwner, $email, 'New Co');
        $account = $this->realAccount($email);

        $suspendedWorkspace = $this->createWorkspace($account, ['name' => 'Old Suspended Co']);
        $this->assignTier($suspendedWorkspace, WorkspacePlanTier::Core);
        app(EntitlementManager::class)->changePlanStatus($suspendedWorkspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Fixture.');

        $this->actingAs($account);

        $this->get(route('client-invitations.claim', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertOk();

        $this->post(route('client-invitations.accept', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect();

        $accepted = $invitation->fresh();
        $this->assertSame(ClientInvitationStatus::Accepted, $accepted->status);
        $this->assertNotNull($accepted->created_client_workspace_id);
    }

    public function test_the_allowlist_is_invitation_specific_and_the_old_locked_workspace_stays_ordinarily_blocked(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        $email = 'still-blocked' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $agencyOwner, $email, 'New Co');
        $account = $this->realAccount($email);

        $lockedWorkspace = $this->createWorkspace($account, ['name' => 'Old Locked Co']);
        $this->assignTier($lockedWorkspace, WorkspacePlanTier::Core);
        app(EntitlementManager::class)->enterGracePeriod($lockedWorkspace);
        app(EntitlementManager::class)->lockForNonPayment($lockedWorkspace);

        $this->actingAs($account);

        // The invitation routes are reachable...
        $this->get(route('client-invitations.claim', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertOk();

        // ...but an ORDINARY operational route for the old, locked
        // Workspace remains exactly as blocked as before -- this allowlist
        // names only the two invitation routes, never a prefix or a
        // broader carve-out.
        $this->get(route('customer.workspaces.show', $lockedWorkspace->uid))
            ->assertRedirect(route('customer.account-locked.show'));
    }

    public function test_middleware_ordering_locked_unrelated_workspace_plus_pending_two_factor_still_requires_verification_first(): void
    {
        [$agency, $agencyOwner] = $this->agency();
        $email = 'locked-and-2fa' . uniqid('', true) . '@example.test';
        [$invitation, $token] = $this->pendingInvitation($agency, $agencyOwner, $email, 'New Co');
        $account = $this->realAccount($email);

        $lockedWorkspace = $this->createWorkspace($account, ['name' => 'Old Locked Co']);
        $this->assignTier($lockedWorkspace, WorkspacePlanTier::Core);
        app(EntitlementManager::class)->enterGracePeriod($lockedWorkspace);
        app(EntitlementManager::class)->lockForNonPayment($lockedWorkspace);

        $code = $this->markPendingTwoFactor($account);

        $workspaceCountBefore = Workspace::count();

        // The account gate lets the invitation route through (it is
        // allowlisted) but TwoFactor still intercepts and redirects to
        // verify -- the gate's allowlist and the 2FA gate are independent,
        // and neither one substitutes for the other.
        $this->get(route('client-invitations.claim', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect(route('verify.index'));

        $this->post(route('client-invitations.accept', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect(route('verify.index'));

        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame(ClientInvitationStatus::Pending, $invitation->fresh()->status);

        // Completing 2FA lets acceptance proceed normally, the locked
        // unrelated Workspace notwithstanding.
        $this->post(route('verify.store'), ['two_factor_code' => (string) $code])->assertRedirect();

        $this->post(route('client-invitations.accept', ['uid' => $invitation->uid, 'token' => $token]))
            ->assertRedirect();

        $this->assertSame(ClientInvitationStatus::Accepted, $invitation->fresh()->status);
    }
}
