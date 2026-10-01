<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessPayerAssignment;
use App\Models\BusinessStripeConnection;
use App\Models\BusinessUsageWallet;
use App\Models\Customer;
use App\Models\ExternalCalendarConnection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner / Admin V1 §§4-6, 8, 14 — the support cockpit explains an
 * account from canonical data: the access answer is the SAME answer the
 * customer-facing gate acts on, plan and subscription are shown beside it
 * without inferring entitlement from provider status, Agency facts are shown
 * read-only, and no credential ever reaches a response.
 */
class PlatformOwnerSupportDiagnosticsTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    private const SECRET = 'SECRET-REFRESH-TOKEN-do-not-leak-0xC0FFEE';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
    }

    private function accessReasonOnAdminPage(Workspace $workspace): string
    {
        $this->actingAsPlatformOwner();
        $html = $this->get(route('admin.workspaces.show', $workspace))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-testid="po-access-reason">([^<]+)</', $html, $match), 'The access reason must be rendered.');

        return trim($match[1]);
    }

    private function accessStateOnAdminPage(Workspace $workspace): string
    {
        $this->actingAsPlatformOwner();
        $html = $this->get(route('admin.workspaces.show', $workspace))->assertOk()->getContent();

        return str_contains($html, 'Blocked —') ? 'blocked' : 'usable';
    }

    // -- 9. canonical assignment + subscription --------------------------------

    public function test_workspace_detail_shows_the_canonical_assignment_and_the_platform_subscription(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $subscription = $fixture['subscription'];
        $this->actingAsPlatformOwner();

        $response = $this->get(route('admin.workspaces.show', $fixture['workspace']))->assertOk();

        $response->assertSee('data-testid="po-subscription"', false);
        $response->assertSee($subscription->provider_customer_id);
        $response->assertSee($subscription->provider_subscription_id);
        $response->assertSee('Growth');
        $response->assertSeeInOrder(['Provider status', $subscription->status->value], false);

        // The "grants access" answer is the enum's own.
        $this->assertTrue($subscription->status->grantsAccess());
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/data-testid="po-subscription-grants">\s*<span[^>]*>\s*Yes\s*</', $html);

        // Plan assignment card
        $summary = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($fixture['workspace']);
        $response->assertSee($summary->tierDisplayName);
    }

    // -- 10. the diagnostic IS the gate -----------------------------------------

    public function test_the_access_diagnostic_matches_the_resolver_and_the_real_customer_gate_in_every_state(): void
    {
        $scenarios = [
            'usable' => fn (Workspace $w) => null,
            'inactive' => fn (Workspace $w) => app(EntitlementManager::class)->changePlanStatus($w, WorkspacePlanAssignmentStatus::Inactive, $this->platformAdminId(), 'Fixture.'),
            'suspended' => fn (Workspace $w) => app(EntitlementManager::class)->changePlanStatus($w, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Fixture.'),
            'locked' => fn (Workspace $w) => app(EntitlementManager::class)->lockForNonPayment($w, $this->platformAdminId(), 'Fixture.'),
            'grace' => fn (Workspace $w) => app(EntitlementManager::class)->enterGracePeriod($w, $this->platformAdminId(), 'Fixture.'),
        ];

        foreach ($scenarios as $name => $apply) {
            [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, "Gate {$name} Co", "Gate {$name} WS");
            $apply($workspace);

            $resolver = app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh());
            $guard = app(CustomerAccountAccessGuard::class)->decisionForBusiness($business->fresh());

            $this->assertSame($resolver->reason, $this->accessReasonOnAdminPage($workspace), "[{$name}] admin reason must equal the resolver's.");
            $this->assertSame($guard->reason, $resolver->reason, "[{$name}] the API gate's guard must agree with the resolver.");
            $this->assertSame($resolver->isLocked() ? 'blocked' : 'usable', $this->accessStateOnAdminPage($workspace), "[{$name}] admin state must equal the resolver's.");

            // The real customer-facing gate, end to end.
            $this->authenticateAs($customer);
            $home = $this->home();

            if ($resolver->isLocked()) {
                $home->assertRedirect(route('customer.account-locked.show'));
            } else {
                $this->assertNotSame(route('customer.account-locked.show'), $home->headers->get('Location'), "[{$name}] a usable account must not be sent to the locked screen.");
            }
        }
    }

    // -- 11. no assignment -------------------------------------------------------

    public function test_a_workspace_with_no_plan_assignment_is_shown_with_the_resolvers_actual_answer(): void
    {
        $fixture = $this->unassignedWorkspace('Unplanned Workspace');
        $resolver = app(CustomerAccountAccessResolver::class)->resolve($fixture['workspace']);

        $reason = $this->accessReasonOnAdminPage($fixture['workspace']);

        // On this codebase an unassigned Workspace is NOT locked — onboarding
        // owns it — and the page says exactly that instead of inventing a block.
        $this->assertSame($resolver->reason, $reason);
        $this->assertFalse($resolver->isLocked());

        $response = $this->get(route('admin.workspaces.show', $fixture['workspace']))->assertOk();
        $response->assertSee('An unassigned Workspace is not blocked by the access gate', false);
        $response->assertDontSee('Blocked —', false);
        $response->assertDontSee('Restore access');
    }

    // -- 12. a non-granting subscription is not presented as granting ------------

    public function test_a_canceled_subscription_is_not_presented_as_granting_access(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $fixture['subscription']->forceFill(['status' => PlatformSubscriptionStatus::Canceled])->save();
        $this->actingAsPlatformOwner();

        $response = $this->get(route('admin.workspaces.show', $fixture['workspace']))->assertOk();
        $html = $response->getContent();

        $this->assertFalse(PlatformSubscriptionStatus::Canceled->grantsAccess());
        $this->assertMatchesRegularExpression('/data-testid="po-subscription-grants">\s*<span[^>]*>\s*No\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/data-testid="po-subscription-grants">\s*<span[^>]*>\s*Yes\s*</', $html);

        // Entitlement is still the assignment's: the account remains usable and
        // the page flags the disagreement instead of deciding it.
        $response->assertSee('data-testid="po-mismatch"', false);
        $response->assertSee('The plan assignment, not the subscription, decides access.', false);
    }

    public function test_a_granting_subscription_on_a_blocked_account_is_flagged_as_a_mismatch(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        app(EntitlementManager::class)->lockForNonPayment($fixture['workspace'], $this->platformAdminId(), 'Fixture lock.');
        $this->actingAsPlatformOwner();

        $response = $this->get(route('admin.workspaces.show', $fixture['workspace']))->assertOk();

        $response->assertSee('data-testid="po-mismatch"', false);
        $response->assertSee('The platform subscription is granting access, but the account is blocked (plan_locked).', false);
    }

    // -- 13. Agency-managed -------------------------------------------------------

    public function test_agency_managed_state_is_shown_read_only_and_an_agency_caused_lock_is_named(): void
    {
        $fixture = $this->createAgencyManagedClient();
        $this->actingAsPlatformOwner();

        $client = $this->get(route('admin.workspaces.show', $fixture['clientWorkspace']))->assertOk();
        $client->assertSee('data-testid="po-agency"', false);
        $client->assertSee($fixture['agencyWorkspace']->name);
        $client->assertSee('Agency-managed');

        $agency = $this->get(route('admin.workspaces.show', $fixture['agencyWorkspace']))->assertOk();
        $agency->assertSee('Manages');
        $agency->assertSee('1 client Workspace');

        // The Agency's own account locks: the CLIENT's canonical decision
        // becomes the Agency-caused lock, and the page names it and offers no
        // restore control on the client.
        app(EntitlementManager::class)->lockForNonPayment($fixture['agencyWorkspace'], $this->platformAdminId(), 'Fixture agency lock.');
        $resolver = app(CustomerAccountAccessResolver::class)->resolve($fixture['clientWorkspace']->fresh());
        $this->assertSame('agency_locked', $resolver->reason);

        $this->assertSame('agency_locked', $this->accessReasonOnAdminPage($fixture['clientWorkspace']));
        $this->get(route('admin.workspaces.show', $fixture['clientWorkspace']))
            ->assertSee("comes from the managing Agency's own account", false)
            ->assertDontSee('data-testid="po-restore-form"', false);
    }

    // -- 14/15. providers and secrets --------------------------------------------

    /**
     * @return array{customer: Customer, business: Business, workspace: Workspace}
     */
    private function tenantWithProviders(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Provider Heavy Co', 'Provider Heavy WS');

        (new BusinessStripeConnection())->forceFill([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'stripe_account_id' => 'acct_TESTCONNECTED1',
            'status' => 'restricted', 'charges_enabled' => true, 'payouts_enabled' => false, 'details_submitted' => true,
            'requirements_disabled_reason' => 'requirements.past_due',
        ])->save();

        (new BusinessEmailAccount())->forceFill([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'provider' => 'google', 'state' => 'revoked',
            'mailbox_email' => 'inbox@providerheavy.test', 'refresh_token_encrypted' => self::SECRET,
            'oauth_state_nonce' => 'NONCE-do-not-leak-123', 'failure_classification' => 'invalid_grant',
        ])->save();

        (new BusinessGoogleConnection())->forceFill([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'product' => 'business_profile', 'state' => 'active',
            'google_account_email' => 'gbp@providerheavy.test', 'refresh_token_encrypted' => self::SECRET,
        ])->save();

        (new ExternalCalendarConnection())->forceFill([
            'uid' => (string) Str::uuid(), 'user_id' => $customer->user_id, 'provider' => 'google', 'state' => 'active',
            'external_account_email' => 'cal@providerheavy.test', 'refresh_token_encrypted' => self::SECRET,
            'sync_cursor' => 'SYNC-CURSOR-do-not-leak', 'sync_failure_count' => 2, 'failure_classification' => 'quota',
        ])->save();

        (new BusinessPayerAssignment())->forceFill([
            'business_id' => $business->id, 'payer_type' => 'business',
        ])->save();

        (new BusinessUsageWallet())->forceFill([
            'business_id' => $business->id, 'currency_id' => $this->fixtureCurrencyId(), 'billing_status' => 'suspended',
            'recharge_period_key' => '2026-10', 'recharge_period_start_utc' => now()->startOfMonth(), 'recharge_period_end_utc' => now()->endOfMonth(),
            'spend_period_key' => '2026-10', 'spend_period_start_utc' => now()->startOfMonth(), 'spend_period_end_utc' => now()->endOfMonth(),
        ])->save();

        return ['customer' => $customer, 'business' => $business, 'workspace' => $workspace];
    }

    public function test_safe_connection_statuses_render_on_the_workspace_and_business_pages(): void
    {
        $fixture = $this->tenantWithProviders();
        $this->actingAsPlatformOwner();

        foreach ([route('admin.workspaces.show', $fixture['workspace']), route('admin.businesses.show', $fixture['business'])] as $url) {
            $response = $this->get($url)->assertOk();

            // Stripe
            $response->assertSee('acct_TESTCONNECTED1');
            $response->assertSee('Restricted');
            $response->assertSee('requirements.past_due');
            // Business Email
            $response->assertSee('inbox@providerheavy.test');
            $response->assertSee('Revoked');
            $response->assertSee('invalid_grant');
            // Google Business Profile
            $response->assertSee('Google Business Profile');
            // Calendar
            $response->assertSee('cal@providerheavy.test');
            $response->assertSee('data-testid="po-calendar-row"', false);
            // Payer / wallet status
            $response->assertSee('Wallet: Suspended');
        }
    }

    public function test_no_secret_or_credential_value_is_ever_present_in_any_platform_owner_response(): void
    {
        $fixture = $this->tenantWithProviders();

        // Prove the secret really is stored (encrypted) so the assertion below
        // means something: the raw column is ciphertext, never the plaintext.
        $rawCiphertext = (string) DB::table('business_email_accounts')->where('business_id', $fixture['business']->id)->value('refresh_token_encrypted');
        $this->assertNotSame('', $rawCiphertext);
        $this->assertNotSame(self::SECRET, $rawCiphertext);

        $this->actingAsPlatformOwner();

        $urls = [
            route('admin.platform-owner.overview'),
            route('admin.platform-owner.audit'),
            route('admin.workspaces.index'),
            route('admin.workspaces.show', $fixture['workspace']),
            route('admin.businesses.index'),
            route('admin.businesses.show', $fixture['business']),
        ];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach ([self::SECRET, $rawCiphertext, 'NONCE-do-not-leak-123', 'SYNC-CURSOR-do-not-leak', 'refresh_token', 'oauth_state_nonce', 'sk_live', 'sk_test', 'whsec_'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html, "{$url} must not render [{$forbidden}].");
            }
        }
    }

    public function test_provider_connection_rows_are_read_without_selecting_any_credential_column(): void
    {
        $fixture = $this->tenantWithProviders();
        $reader = app(\App\Library\PlatformOwner\ProviderConnectionStatusReader::class);

        $rows = [
            $reader->stripeFor([$fixture['business']->id])->first(),
            $reader->emailFor([$fixture['business']->id])->first(),
            $reader->googleFor([$fixture['business']->id])->first(),
            $reader->calendarFor([$fixture['customer']->user_id])->first()->first(),
        ];

        foreach ($rows as $row) {
            $this->assertNotNull($row);
            foreach (['refresh_token_encrypted', 'oauth_state_nonce', 'sync_cursor', 'notification_channel_id'] as $column) {
                $this->assertArrayNotHasKey($column, $row->getAttributes(), get_class($row) . " must not load {$column}.");
            }
        }
    }

    // -- Business detail ------------------------------------------------------------

    public function test_business_detail_shows_workspace_locations_access_and_audit(): void
    {
        $fixture = $this->tenantWithProviders();
        $this->actingAsPlatformOwner();

        $response = $this->get(route('admin.businesses.show', $fixture['business']))->assertOk();

        $response->assertSee('Provider Heavy WS');
        $response->assertSee('data-testid="po-access"', false);
        $response->assertSee('data-testid="po-audit-row"', false);
        $response->assertSee('plan_assigned');
        $response->assertSee(route('admin.workspaces.show', $fixture['workspace']), false);
    }
}
