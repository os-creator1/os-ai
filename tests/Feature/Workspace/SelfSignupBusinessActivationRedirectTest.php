<?php

namespace Tests\Feature\Workspace;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\Business;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Manual acceptance defect 1 (P0) — a self-signup V1 owner whose Workspace
 * already holds an assigned, usable Core/Growth plan (isBusinessFirstAccount()
 * requires a resolved tier, which only exists once a plan is assigned, and
 * CustomerAccountAccessGate already refuses an unusable assignment before
 * this controller ever runs) but whose one Business is still Draft had no
 * way out: WorkspaceController::show() redirected to Home, and Home's own
 * primary action for that exact state (AccountHomePresenter::createUrl())
 * pointed straight back to this same route — an infinite loop, since
 * draftClientActivationUrl() is Agency-invited-client only. Root cause:
 * nothing in the product ever activated a self-signup Business at all.
 *
 * ChatGPT review correction — an assigned plan alone does not prove this is
 * an old broken V1 self-signup: Contract 21 explicitly supports
 * complimentary/manually-assigned Workspaces with no Stripe subscription at
 * all. The self-heal below therefore also requires PROVENANCE — a local
 * PlatformSubscription for this exact Workspace whose current status still
 * grantsAccess() — never used as a feature/access authority (that stays
 * workspace_plan_assignments alone), only as proof this specific Draft
 * Business is a paid V1 self-signup the pre-fix defect stranded.
 *
 * ChatGPT review correction (round 2) — the repair is its own step
 * (WorkspaceController::repairStrandedConfirmedSignupBusiness()), separate
 * from isBusinessFirstAccount()'s Core/Growth-only UI meaning, so a paid
 * Agency self-signup is repaired too without being folded into Core/Growth
 * navigation; and the repair target is exactly Draft, never merely "not
 * Active", so an Inactive Business is left alone rather than redirected to
 * a Settings page it would 404 on.
 */
class SelfSignupBusinessActivationRedirectTest extends TestCase
{
    use CreatesPlatformSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeStripe();
    }

    /**
     * The realistic stuck-account shape: a real assigned Growth plan AND a
     * real, provider-confirmed, access-granting local PlatformSubscription
     * for the same Workspace (subscribedWorkspace() drives the actual §7
     * signup spine — start checkout, complete it, confirm from provider
     * truth, assign the plan) — with the Business forced back to Draft to
     * reproduce exactly what the pre-fix defect left behind: everything
     * else about this account is genuinely paid, only the Business was
     * never activated.
     *
     * @return array{customer: \App\Models\Customer, business: Business, workspace: \App\Models\Workspace}
     */
    private function stuckSelfSignupAccount(): array
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Stuck Business', BusinessStatus::Draft);

        $this->assertTrue($fixture['subscription']->status->grantsAccess(), 'Fixture sanity: the subscription must genuinely grant access.');

        return ['customer' => $fixture['customer'], 'business' => $business, 'workspace' => $fixture['workspace']];
    }

    public function test_a_stuck_self_signup_account_is_self_healed_and_reaches_business_settings(): void
    {
        $fixture = $this->stuckSelfSignupAccount();
        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $response->assertRedirect(route('customer.workspaces.businesses.settings.show', [$fixture['workspace']->uid, $fixture['business']->uid]));
    }

    public function test_it_never_loops_back_to_home(): void
    {
        $fixture = $this->stuckSelfSignupAccount();
        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $response->assertRedirect();
        $this->assertNotSame(route('user.home'), $response->headers->get('Location'));
    }

    public function test_the_business_is_actually_activated_not_just_redirected_around(): void
    {
        $fixture = $this->stuckSelfSignupAccount();
        $this->authenticateAs($fixture['customer']);

        $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $business = Business::find($fixture['business']->id);
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
    }

    /**
     * ChatGPT review correction (Finding 2) — a complimentary or otherwise
     * manually-assigned Workspace (Contract 21's own supported shape) has a
     * real WorkspacePlanAssignment but NO PlatformSubscription row at all:
     * there is no provider-confirmed V1 signup this Draft Business could be
     * the stranded remainder of. Visiting this page must never activate it.
     */
    public function test_a_complimentary_assigned_workspace_with_no_subscription_is_never_self_healed(): void
    {
        $fixture = $this->createIndependentWorkspaceBusiness(status: BusinessStatus::Draft);
        app(EntitlementManager::class)->assignFirstPlan(
            $fixture['workspace'], WorkspacePlanTier::Growth, $this->platformAdminId(), 'complimentary fixture', true, 0
        );

        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $response->assertRedirect(route('user.home'));
        $this->assertSame(BusinessStatus::Draft, Business::find($fixture['business']->id)->status);
    }

    /**
     * ChatGPT review correction (Finding 2) — a PlatformSubscription that
     * exists but whose CURRENT local status no longer grantsAccess() (here,
     * Canceled) proves the opposite of what the self-heal requires: this
     * account is not, or no longer, paid. It must not be waved through
     * merely because a subscription row happens to exist.
     */
    public function test_a_non_granting_subscription_is_never_self_healed(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Lapsed Business', BusinessStatus::Draft);
        $fixture['subscription']->forceFill(['status' => PlatformSubscriptionStatus::Canceled])->save();

        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $response->assertRedirect(route('user.home'));
        $this->assertSame(BusinessStatus::Draft, Business::find($business->id)->status);
    }

    /**
     * ChatGPT review correction (Finding 1) — Agency V1 signup provisions
     * through the exact same V1SignupManager path Core/Growth does, and can
     * be stranded Draft by the exact same pre-fix defect, but
     * isBusinessFirstAccount() is deliberately Core/Growth-only UI
     * vocabulary. The repair must still apply to a paid Agency self-signup
     * — and, having applied, must NOT force the Core/Growth Business
     * Settings redirect: an Agency account keeps its normal account-frame
     * overview (a 200 render), never converted into Core/Growth navigation
     * semantics just because this repair also reaches it.
     */
    public function test_a_stuck_paid_agency_self_signup_is_repaired_without_becoming_business_first(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Stuck Agency Business', BusinessStatus::Draft);
        $this->assertTrue($fixture['subscription']->status->grantsAccess(), 'Fixture sanity: the subscription must genuinely grant access.');

        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        // Repaired, but never redirected: a redirect here would mean the
        // Core/Growth Business-first branch fired, which must never happen
        // for an Agency Workspace.
        $response->assertOk();

        $business = Business::find($business->id);
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
    }

    /**
     * ChatGPT review correction (Finding 3) — the repair target must be
     * exactly Draft, never merely "not Active". An Inactive Business is
     * left exactly as it is (activateForConfirmedSignup() only ever
     * transitions a genuinely Draft row), and the controller must not
     * redirect to Business Settings on the strength of a repair attempt
     * alone: Settings requires an Active Business, so redirecting an
     * Inactive one there would only 404.
     */
    public function test_an_inactive_business_is_left_alone_and_never_redirected_to_settings(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Inactive Business', BusinessStatus::Inactive);
        $this->assertTrue($fixture['subscription']->status->grantsAccess(), 'Fixture sanity: the subscription must genuinely grant access.');

        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.show', $fixture['workspace']->uid));

        $response->assertRedirect(route('user.home'));
        $this->assertSame(BusinessStatus::Inactive, Business::find($business->id)->status);
    }

    /**
     * A genuine Agency-managed Client Workspace must never be self-healed
     * through this path — activation there is deliberately the client
     * owner's own explicit confirmation step over real placeholder data
     * (ClientBusinessActivationController), never an automatic flip. Gives
     * the Client Workspace a real, access-granting PlatformSubscription too
     * (the (currently synthetic, since Client Workspaces carry no billing
     * today) provenance the self-heal would otherwise accept), so the
     * relationship check is proven to take precedence in its own right,
     * never merely riding on a missing subscription.
     */
    public function test_an_agency_managed_client_workspace_is_never_self_healed(): void
    {
        $managed = $this->createAgencyManagedClient();
        DB::table('businesses')->where('id', $managed['clientBusiness']->id)->update(['status' => BusinessStatus::Draft->value, 'activated_at' => null]);
        app(EntitlementManager::class)->assignFirstPlan(
            $managed['clientWorkspace'], WorkspacePlanTier::Growth, $this->platformAdminId(), 'test fixture', true, 0
        );
        $this->givePlatformSubscription($managed['clientWorkspace'], PlatformSubscriptionStatus::Active);

        $this->authenticateAs($managed['clientOwner']);

        $response = $this->get(route('customer.workspaces.show', $managed['clientWorkspace']->uid));

        $response->assertRedirect(route('user.home'));
        $this->assertSame(BusinessStatus::Draft, Business::find($managed['clientBusiness']->id)->status);
    }

    /**
     * A real, provider-confirmed PlatformSubscription for a Workspace that
     * already has its plan assigned some other way (assignFirstPlan()
     * directly, not through Checkout) — drives the same startCheckout() /
     * completeCheckout() / confirmCheckoutSession() spine subscribedWorkspace()
     * uses internally, just against an EXISTING Workspace rather than a
     * freshly-created one, so every NOT-NULL/unique column
     * (local_idempotency_key, billing_cycle_snapshot, uid, ...) is filled
     * exactly as it would be in production. Used only to prove the
     * self-heal's relationship-check independently of the "no subscription
     * at all" confound — never a realistic signup fixture on its own.
     */
    private function givePlatformSubscription(Workspace $workspace, PlatformSubscriptionStatus $status): PlatformSubscription
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $manager = app(PlatformSubscriptionManager::class);

        $session = $manager->startCheckout($workspace, $catalog, 'owner@example.test', 'https://app.test/done', 'https://app.test/cancel');
        $this->stripe->completeCheckout($session->sessionId);
        $subscription = $manager->confirmCheckoutSession($session->sessionId);
        $subscription->forceFill(['status' => $status])->save();

        return $subscription->refresh();
    }
}
