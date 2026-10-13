<?php

namespace Tests\Feature\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\PlatformOwner\AccountClosureState;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountAssessor;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountClosureManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * Lane C and account deletion: a client's subscription to an Agency is
 * cancelled on the Agency's own connected Stripe account — in both directions
 * (the client is deleted, or the Agency is) — and a deletion never reaches an
 * independently owned client Workspace.
 */
class PlatformAccountDeletionAgencyBillingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAgencySaasFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function enrolled(): array
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        return [$fixture, $this->enrolledClient($fixture, $plan)];
    }

    private function close(User $user, string $reason = 'Requested by the customer.')
    {
        $assessment = app(PlatformAccountAssessor::class)->assess($user);

        return app(PlatformAccountClosureManager::class)->request($this->platformAdminId(), $user->uid, $user->email, $reason, $assessment['fingerprint']);
    }

    public function test_deleting_a_client_cancels_its_agency_subscription_on_the_agencys_account(): void
    {
        [$fixture, $enrolled] = $this->enrolled();
        $client = $fixture['clientOwner']->user;

        $closure = $this->close($client);

        $this->assertSame(AccountClosureState::Closed, $closure->state);

        $calls = $this->agencyStripe->callsOf('cancelSubscriptionNow');
        $this->assertCount(1, $calls);
        $this->assertSame($enrolled['provider_subscription_id'], $calls[0]['args']['subscription']);
        $this->assertSame((string) $enrolled['subscription']->connected_account_id, $calls[0]['args']['account']);

        // The subscription history stays, and says canceled.
        $this->assertSame(AgencyClientSubscriptionStatus::Canceled, AgencyClientSubscription::find($enrolled['subscription']->id)->status);
        $this->assertFalse((bool) User::find($client->id)->status);

        // The Agency is untouched.
        $this->assertTrue(Workspace::find($fixture['agencyWorkspace']->id)->is_active);
        $this->assertTrue((bool) User::find($fixture['agencyOwner']->user_id)->status);
        $this->assertSame('terminated', AgencyClientWorkspaceRelationship::query()->where('client_workspace_id', $fixture['clientWorkspace']->id)->firstOrFail()->status->value);
    }

    public function test_deleting_an_agency_owner_cancels_client_billing_but_keeps_independent_clients(): void
    {
        [$fixture, $enrolled] = $this->enrolled();
        $agency = $fixture['agencyOwner']->user;

        $assessment = app(PlatformAccountAssessor::class)->assess($agency);
        $this->assertSame(PlatformAccountAssessor::VERDICT_CLOSE, $assessment['verdict']);
        $this->assertCount(1, $assessment['billing_commitments']['agency_as_agency']);

        $closure = $this->close($agency);

        $this->assertSame(AccountClosureState::Closed, $closure->state, json_encode($closure->pending_items));
        $this->assertCount(1, $this->agencyStripe->callsOf('cancelSubscriptionNow'), 'the client must not keep being charged by a closed Agency');
        $this->assertSame(AgencyClientSubscriptionStatus::Canceled, AgencyClientSubscription::find($enrolled['subscription']->id)->status);

        // The client's own account, Workspace and Business are independent and survive, usable.
        $this->assertTrue((bool) User::find($fixture['clientOwner']->user_id)->status);
        $this->assertTrue(Workspace::find($fixture['clientWorkspace']->id)->is_active);
        $this->assertTrue(DB::table('businesses')->where('id', $fixture['clientBusiness']->id)->exists());
        $this->assertFalse(Workspace::find($fixture['agencyWorkspace']->id)->is_active);
    }

    public function test_an_agency_cancellation_failure_leaves_the_closure_pending_and_retries_to_completion(): void
    {
        [$fixture, $enrolled] = $this->enrolled();
        $client = $fixture['clientOwner']->user;

        $this->agencyStripe->failWith = AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);

        $closure = $this->close($client);

        $this->assertSame(AccountClosureState::Closing, $closure->state);
        $this->assertStringContainsString('may still be charging', implode(' ', $closure->pending_items));
        $this->assertNotSame(AgencyClientSubscriptionStatus::Canceled, AgencyClientSubscription::find($enrolled['subscription']->id)->status);
        $this->assertFalse((bool) User::find($client->id)->status, 'access is blocked even while cancellation is pending');

        $this->agencyStripe->failWith = null;
        $closure = app(PlatformAccountClosureManager::class)->resume($closure->uid, $this->platformAdminId());

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertSame(AgencyClientSubscriptionStatus::Canceled, AgencyClientSubscription::find($enrolled['subscription']->id)->status);
    }
}
