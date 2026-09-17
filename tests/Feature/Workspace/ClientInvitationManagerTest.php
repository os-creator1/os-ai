<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\ClientInvitationStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Exceptions\Workspace\InvalidClientInvitationClaimException;
use App\Exceptions\Workspace\UnauthorizedAgencyRelationshipManagementException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Support\RequestScopedCache;
use App\Library\Workspace\ClientInvitationManager;
use App\Models\ClientWorkspaceInvitation;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Workspace\ClientInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 07 §5/§6 — ClientInvitationManager: the
 * Agency-side send/revoke/claim-validation lifecycle for a client
 * workspace invitation.
 *
 * Every test in this file asserts a boundary this class exists to enforce:
 * NOTHING beyond the one `client_workspace_invitations` row is ever created
 * here — no Workspace, Business, Location, User, or Contract 01
 * relationship. That provisioning only ever happens in
 * AgencyClientProvisioningManager, at acceptance (see
 * AgencyClientProvisioningTest).
 */
class ClientInvitationManagerTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Burns user id 1 on the platform administrator before any actor
        // exists: EloquentAccountRepository::hasPermission() short-circuits
        // that id to "every permission", which would invalidate every
        // denial asserted below.
        $this->platformAdminId();

        // send() always dispatches ClientInvitationNotification; faking it
        // for every test (not just the ones that inspect it) keeps this
        // suite from attempting a real SMTP connection.
        Notification::fake();
    }

    private function manager(): ClientInvitationManager
    {
        return app(ClientInvitationManager::class);
    }

    /** @return array{0: Workspace, 1: User} */
    private function agency(string $name = 'Northwind Agency'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, WorkspacePlanTier::Agency);

        return [$workspace->fresh(), $customer->user->fresh()];
    }

    /** An Agency-tier Workspace still on its Trial window. */
    private function agencyInTrial(string $name = 'Trialing Agency'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Agency, $this->platformAdminId(), 'Trial fixture.', true, 0, now()->addDays(14));

        return [$workspace->fresh(), $customer->user->fresh()];
    }

    /** A Workspace deliberately NOT on the Agency tier (Core/Growth). */
    private function nonAgencyWorkspace(WorkspacePlanTier $tier, string $name = 'Ordinary Co'): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->createWorkspace($customer->user, ['name' => $name]);
        $this->assignTier($workspace, $tier);

        return [$workspace->fresh(), $customer->user->fresh()];
    }

    /**
     * Drives an Agency Workspace's account into a lifecycle state through
     * EntitlementManager's own writers — never by poking lifecycle columns
     * directly. Matches AgencyClientRelationshipManagerTest's own
     * putWorkspaceInto()/AgencyViewAsTest's putAgencyInto() convention.
     */
    private function putAgencyInto(string $state, Workspace $agency): void
    {
        $entitlements = app(EntitlementManager::class);

        match ($state) {
            'grace' => $entitlements->enterGracePeriod($agency),
            'locked' => [$entitlements->enterGracePeriod($agency), $entitlements->lockForNonPayment($agency)],
            'inactive' => $entitlements->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Test: plan made inactive.'),
            'suspended' => $entitlements->changePlanStatus($agency, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Test: plan suspended.'),
        };

        app(RequestScopedCache::class)->flush();
    }

    /**
     * A customer User who is a member of $workspace and holds NO customer
     * permissions: under the V1 rule active Agency membership alone is the
     * ordinary-management grant, so nothing in a permission list may be
     * what makes a test pass.
     */
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

    private function outsider(): User
    {
        return $this->createCustomer()->user->fresh();
    }

    private function send(Workspace $agency, User $actor, ?string $email = null, ?string $businessName = 'Acme Dental'): ClientWorkspaceInvitation
    {
        return $this->manager()->send(
            (int) $actor->id,
            $agency,
            $email ?? ('client' . uniqid('', true) . '@example.test'),
            $businessName,
        );
    }

    /**
     * Extracts the plaintext token from the on-demand notification sent for
     * $invitation. Requires Notification::fake() to already be active. This
     * is the ONLY place in this test file that ever reads the plaintext —
     * exactly mirroring the one legitimate path production code has for it
     * (building the claim email).
     */
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

    // ------------------------------------------------------------------
    // Authority — SEND
    // ------------------------------------------------------------------

    public function test_the_agency_workspace_owner_can_send_an_invitation(): void
    {
        [$agency, $owner] = $this->agency();

        $invitation = $this->send($agency, $owner, 'client@example.test');

        $this->assertSame(ClientInvitationStatus::Pending, $invitation->status);
        $this->assertSame((int) $agency->id, (int) $invitation->agency_workspace_id);
        $this->assertSame((int) $owner->id, (int) $invitation->invited_by_user_id);
        $this->assertSame('client@example.test', $invitation->email);
    }

    public function test_an_active_agency_admin_can_send_an_invitation(): void
    {
        [$agency] = $this->agency();
        $admin = $this->memberOf($agency, WorkspaceMembershipRole::Admin);

        $invitation = $this->send($agency, $admin);

        $this->assertSame((int) $admin->id, (int) $invitation->invited_by_user_id);
    }

    public function test_active_agency_staff_can_send_an_invitation(): void
    {
        [$agency] = $this->agency();
        $staff = $this->memberOf($agency, WorkspaceMembershipRole::Staff);

        $invitation = $this->send($agency, $staff);

        $this->assertSame((int) $staff->id, (int) $invitation->invited_by_user_id);
    }

    public function test_an_inactive_agency_admin_is_refused(): void
    {
        [$agency] = $this->agency();
        $inactiveAdmin = $this->memberOf($agency, WorkspaceMembershipRole::Admin, false);

        $this->expectException(UnauthorizedAgencyRelationshipManagementException::class);

        $this->send($agency, $inactiveAdmin);
    }

    public function test_a_removed_member_is_refused(): void
    {
        [$agency] = $this->agency();
        $formerStaff = $this->memberOf($agency, WorkspaceMembershipRole::Staff);
        $formerStaff->fresh();
        DB::table('workspace_memberships')
            ->where('workspace_id', $agency->id)
            ->where('user_id', $formerStaff->id)
            ->delete();

        $this->expectException(UnauthorizedAgencyRelationshipManagementException::class);

        $this->send($agency, $formerStaff);
    }

    public function test_a_member_of_a_different_agency_only_is_refused(): void
    {
        [$agencyA] = $this->agency('Agency A');
        [$agencyB] = $this->agency('Agency B');
        $memberOfB = $this->memberOf($agencyB, WorkspaceMembershipRole::Admin);

        $this->expectException(UnauthorizedAgencyRelationshipManagementException::class);

        $this->send($agencyA, $memberOfB);
    }

    public function test_client_side_membership_grants_no_send_authority(): void
    {
        [$agency] = $this->agency();
        [$client] = [$this->createWorkspace($this->createCustomer()->user)];
        $clientMember = $this->memberOf($client, WorkspaceMembershipRole::Admin);

        $this->expectException(UnauthorizedAgencyRelationshipManagementException::class);

        $this->send($agency, $clientMember);
    }

    public function test_an_unrelated_actor_is_refused(): void
    {
        [$agency] = $this->agency();
        $outsider = $this->outsider();

        $this->expectException(UnauthorizedAgencyRelationshipManagementException::class);

        $this->send($agency, $outsider);
    }

    // ------------------------------------------------------------------
    // Eligibility — SEND requires BOTH authority AND standing Agency
    // management eligibility (Contract 01 §6, reused verbatim via
    // assertAgencyWorkspaceHasManagementEligibility()).
    // ------------------------------------------------------------------

    public function test_a_core_tier_owner_cannot_send(): void
    {
        [$workspace, $owner] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core);

        $this->expectException(AgencyWorkspaceNotEligibleException::class);

        $this->send($workspace, $owner);
    }

    public function test_a_growth_tier_owner_cannot_send(): void
    {
        [$workspace, $owner] = $this->nonAgencyWorkspace(WorkspacePlanTier::Growth);

        $this->expectException(AgencyWorkspaceNotEligibleException::class);

        $this->send($workspace, $owner);
    }

    public function test_an_agency_on_trial_can_send(): void
    {
        [$agency, $owner] = $this->agencyInTrial();

        $invitation = $this->send($agency, $owner);

        $this->assertSame(ClientInvitationStatus::Pending, $invitation->status);
    }

    public function test_an_active_agency_can_send(): void
    {
        [$agency, $owner] = $this->agency();

        $invitation = $this->send($agency, $owner);

        $this->assertSame(ClientInvitationStatus::Pending, $invitation->status);
    }

    public function test_an_agency_in_grace_can_send(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('grace', $agency);

        $invitation = $this->send($agency, $owner);

        $this->assertSame(ClientInvitationStatus::Pending, $invitation->status);
    }

    public function test_a_locked_agency_cannot_send(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('locked', $agency);

        $countBefore = ClientWorkspaceInvitation::count();

        try {
            $this->send($agency, $owner);
            $this->fail('Expected AgencyWorkspaceNotEligibleException.');
        } catch (AgencyWorkspaceNotEligibleException) {
            // expected
        }

        $this->assertSame($countBefore, ClientWorkspaceInvitation::count());
        Notification::assertNothingSent();
    }

    public function test_an_inactive_agency_cannot_send(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('inactive', $agency);

        $countBefore = ClientWorkspaceInvitation::count();

        try {
            $this->send($agency, $owner);
            $this->fail('Expected AgencyWorkspaceNotEligibleException.');
        } catch (AgencyWorkspaceNotEligibleException) {
            // expected
        }

        $this->assertSame($countBefore, ClientWorkspaceInvitation::count());
        Notification::assertNothingSent();
    }

    public function test_a_suspended_agency_cannot_send(): void
    {
        [$agency, $owner] = $this->agency();
        $this->putAgencyInto('suspended', $agency);

        $countBefore = ClientWorkspaceInvitation::count();

        try {
            $this->send($agency, $owner);
            $this->fail('Expected AgencyWorkspaceNotEligibleException.');
        } catch (AgencyWorkspaceNotEligibleException) {
            // expected
        }

        $this->assertSame($countBefore, ClientWorkspaceInvitation::count());
        Notification::assertNothingSent();
    }

    public function test_a_core_tier_refusal_creates_zero_invitations_and_sends_zero_notifications(): void
    {
        [$workspace, $owner] = $this->nonAgencyWorkspace(WorkspacePlanTier::Core);
        $countBefore = ClientWorkspaceInvitation::count();

        try {
            $this->send($workspace, $owner);
            $this->fail('Expected AgencyWorkspaceNotEligibleException.');
        } catch (AgencyWorkspaceNotEligibleException) {
            // expected
        }

        $this->assertSame($countBefore, ClientWorkspaceInvitation::count());
        Notification::assertNothingSent();
    }

    public function test_a_pending_invitation_may_still_be_revoked_after_the_agency_becomes_ineligible(): void
    {
        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);

        // The Agency becomes ineligible AFTER the invitation was sent while
        // still eligible — revoke() is deliberately authority-only, so this
        // otherwise-authorized owner must still be able to withdraw it.
        $this->putAgencyInto('locked', $agency);

        $revoked = $this->manager()->revoke((int) $owner->id, $invitation);

        $this->assertSame(ClientInvitationStatus::Revoked, $revoked->status);
    }

    // ------------------------------------------------------------------
    // Token storage (Clarification B)
    // ------------------------------------------------------------------

    public function test_the_token_is_never_stored_in_plaintext(): void
    {
        Notification::fake();

        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $plaintext = $this->capturedToken($invitation);

        $this->assertNotSame($plaintext, $invitation->token_hash);
        $this->assertTrue(Hash::check($plaintext, $invitation->token_hash));

        $rawRow = (array) DB::table('client_workspace_invitations')->where('uid', $invitation->uid)->first();
        $this->assertStringNotContainsString($plaintext, json_encode($rawRow));
    }

    public function test_resolving_a_claim_locates_the_row_by_uid_and_only_compares_the_token_via_hash_check(): void
    {
        Notification::fake();

        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $plaintext = $this->capturedToken($invitation);

        $resolved = $this->manager()->resolveForClaim($invitation->uid, $plaintext);

        $this->assertSame($invitation->id, $resolved->id);
    }

    // ------------------------------------------------------------------
    // Claim validation
    // ------------------------------------------------------------------

    public function test_an_invalid_token_is_refused_generically(): void
    {
        Notification::fake();

        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);

        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->manager()->resolveForClaim($invitation->uid, 'not-the-real-token');
    }

    public function test_an_unknown_uid_is_refused_with_the_same_exception_type_as_every_other_failure(): void
    {
        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->manager()->resolveForClaim((string) Str::uuid(), 'anything');
    }

    public function test_an_expired_invitation_is_refused_at_claim_time(): void
    {
        Notification::fake();

        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $plaintext = $this->capturedToken($invitation);

        $invitation->expires_at = now()->subMinute();
        $invitation->save();

        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->manager()->resolveForClaim($invitation->uid, $plaintext);
    }

    public function test_expiry_is_enforced_at_claim_time_regardless_of_any_background_scheduler(): void
    {
        // No scheduled job ever mutates `status` to Expired in this feature;
        // isExpired()/resolveForClaim() derive expiry from expires_at alone,
        // every time a claim is attempted, so a Pending row past its
        // expires_at is refused with no scheduler having run at all.
        Notification::fake();

        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $plaintext = $this->capturedToken($invitation);

        $invitation->expires_at = now()->subSecond();
        $invitation->save();

        $this->assertSame(ClientInvitationStatus::Pending, $invitation->fresh()->status);

        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->manager()->resolveForClaim($invitation->uid, $plaintext);
    }

    public function test_the_default_expiry_is_the_configured_seven_day_ttl(): void
    {
        Notification::fake();

        $this->assertSame(7, (int) config('workspace.client_invitation_ttl_days'));

        [$agency, $owner] = $this->agency();
        $before = now();
        $invitation = $this->send($agency, $owner);

        $this->assertEqualsWithDelta(
            $before->copy()->addDays(7)->timestamp,
            $invitation->expires_at->timestamp,
            5,
        );
    }

    // ------------------------------------------------------------------
    // Revoke
    // ------------------------------------------------------------------

    public function test_the_agency_owner_can_revoke_a_pending_invitation(): void
    {
        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);

        $revoked = $this->manager()->revoke((int) $owner->id, $invitation);

        $this->assertSame(ClientInvitationStatus::Revoked, $revoked->status);
    }

    public function test_an_active_admin_can_revoke_a_pending_invitation(): void
    {
        [$agency] = $this->agency();
        $admin = $this->memberOf($agency, WorkspaceMembershipRole::Admin);
        $invitation = $this->send($agency, $admin);

        $revoked = $this->manager()->revoke((int) $admin->id, $invitation);

        $this->assertSame(ClientInvitationStatus::Revoked, $revoked->status);
    }

    public function test_an_active_staff_member_can_revoke_a_pending_invitation(): void
    {
        [$agency] = $this->agency();
        $staff = $this->memberOf($agency, WorkspaceMembershipRole::Staff);
        $invitation = $this->send($agency, $staff);

        $revoked = $this->manager()->revoke((int) $staff->id, $invitation);

        $this->assertSame(ClientInvitationStatus::Revoked, $revoked->status);
    }

    public function test_an_inactive_member_cannot_revoke(): void
    {
        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $inactiveAdmin = $this->memberOf($agency, WorkspaceMembershipRole::Admin, false);

        $this->expectException(UnauthorizedAgencyRelationshipManagementException::class);

        $this->manager()->revoke((int) $inactiveAdmin->id, $invitation);
    }

    public function test_an_unrelated_actor_cannot_revoke(): void
    {
        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $outsider = $this->outsider();

        $this->expectException(UnauthorizedAgencyRelationshipManagementException::class);

        $this->manager()->revoke((int) $outsider->id, $invitation);
    }

    public function test_a_revoked_invitation_cannot_be_revoked_again(): void
    {
        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $this->manager()->revoke((int) $owner->id, $invitation);

        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->manager()->revoke((int) $owner->id, $invitation->fresh());
    }

    public function test_a_revoked_invitation_is_unusable_at_claim_immediately(): void
    {
        Notification::fake();

        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $plaintext = $this->capturedToken($invitation);

        $this->manager()->revoke((int) $owner->id, $invitation);

        $this->expectException(InvalidClientInvitationClaimException::class);

        $this->manager()->resolveForClaim($invitation->uid, $plaintext);
    }

    public function test_revoking_has_no_workspace_side_effect(): void
    {
        [$agency, $owner] = $this->agency();
        $invitation = $this->send($agency, $owner);
        $workspaceCountBefore = Workspace::count();

        $this->manager()->revoke((int) $owner->id, $invitation);

        $this->assertSame($workspaceCountBefore, Workspace::count());
    }

    // ------------------------------------------------------------------
    // Sending never provisions anything (§5)
    // ------------------------------------------------------------------

    public function test_sending_an_invitation_creates_only_the_invitation_row(): void
    {
        [$agency, $owner] = $this->agency();
        $workspaceCountBefore = Workspace::count();
        $userCountBefore = User::count();

        $this->send($agency, $owner);

        $this->assertSame($workspaceCountBefore, Workspace::count());
        $this->assertSame($userCountBefore, User::count());
        $this->assertSame(1, ClientWorkspaceInvitation::count());
    }

    public function test_email_is_normalized_before_storage(): void
    {
        [$agency, $owner] = $this->agency();

        $invitation = $this->send($agency, $owner, '  Client@Example.TEST ');

        $this->assertSame('client@example.test', $invitation->email);
    }

    public function test_intended_business_name_is_stored_and_trimmed(): void
    {
        [$agency, $owner] = $this->agency();

        $invitation = $this->send($agency, $owner, null, '  Acme Dental  ');

        $this->assertSame('Acme Dental', $invitation->intended_business_name);
    }

    public function test_a_blank_intended_business_name_is_stored_as_null(): void
    {
        [$agency, $owner] = $this->agency();

        $invitation = $this->send($agency, $owner, null, '   ');

        $this->assertNull($invitation->intended_business_name);
    }
}
