<?php

namespace Tests\Feature\V1Acceptance\AccountShell;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\ClientWorkspaceInvitation;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\V1Acceptance\AccountShell\Concerns\DrivesAccountJourneys;
use Tests\TestCase;

/**
 * V1 FINAL ACCEPTANCE 01 — journeys A, B and C: a brand-new Core, Growth and
 * Agency customer, from an anonymous visitor to a rendered Home.
 *
 * Every step goes through the product's own routes (guest `/register`, the
 * Stripe test seam, the checkout-return endpoint, the signed verification
 * link, `/dashboard`). The point is the INTEGRATED result: exactly one User,
 * one Workspace, one Business, one Primary Location, the right plan, the right
 * subscription state and a shell that actually opens the Business.
 */
class SignupJourneyTest extends TestCase
{
    use RefreshDatabase;
    use DrivesAccountJourneys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootJourney();
    }

    /** @return array<string, array{0: string, 1: WorkspacePlanTier, 2: bool}> */
    public static function tiers(): array
    {
        return [
            'core (journey A)' => ['core', WorkspacePlanTier::Core, true],
            'growth (journey B)' => ['growth', WorkspacePlanTier::Growth, true],
            'agency (journey C)' => ['agency', WorkspacePlanTier::Agency, false],
        ];
    }

    // =================================================================
    // The whole journey, per tier
    // =================================================================

    #[DataProvider('tiers')]
    public function test_a_new_customer_goes_from_anonymous_visitor_to_home_with_exactly_one_account(string $tier, WorkspacePlanTier $planTier, bool $hasTrial): void
    {
        Notification::fake();

        // 0. Anonymous: the product is closed, the signup page is open.
        $this->get(route('user.home'))->assertUnauthorized();
        $this->get(route('register'))->assertOk();

        // 1. Register.
        $account = $this->registerOnTier($tier);
        $user = $account['user'];

        // Exactly one of everything, and nothing is paid yet.
        $this->assertSame([
            'users' => 1,
            'workspaces' => 1,
            'businesses' => 1,
            'locations' => 1,
            'primary_locations' => 1,
            'platform_subscriptions' => 1,
            'plan_assignments' => 0,
            'wallets' => 1,
            'payer_assignments' => 1,
        ], $this->identityCounts($user), 'Provisioning must create exactly one tenant, and no plan before payment.');

        $this->assertSame(PlatformSubscriptionStatus::Pending, $account['subscription']->status);
        $this->assertSame($planTier->value, DB::table('workspace_plan_catalog')
            ->where('id', $account['subscription']->workspace_plan_catalog_id)->value('tier'));
        $this->assertSame(BusinessStatus::Draft, $account['business']->status, 'Unpaid: the Business is not live.');
        $this->assertSame('USD', $account['business']->currency_code);

        // The customer is told to verify their email, and an email really goes
        // out (the deployment default is ACCOUNT_VERIFICATION=true).
        Notification::assertSentTo($user, VerifyEmail::class);

        // 2. Retry and refresh before paying — never a second tenant, and the
        //    pending identity is the same row every time.
        $pendingUid = $account['subscription']->uid;
        $this->get(route('signup.plan'))->assertOk();
        $this->get(route('signup.cancelled'))->assertRedirect(route('signup.plan'));
        $this->post(route('signup.resume'), ['tier' => $tier])->assertRedirectContains('checkout.stripe.test');
        $this->post(route('signup.resume'), ['tier' => $tier])->assertRedirectContains('checkout.stripe.test');

        $this->assertSame(0, $this->identityCounts($user)['plan_assignments'], 'Retrying checkout never grants a plan.');
        $this->assertSame(1, $this->identityCounts($user)['workspaces']);
        $this->assertSame(1, $this->identityCounts($user)['businesses']);
        $this->assertSame(1, $this->identityCounts($user)['locations']);
        $this->assertSame(1, $this->identityCounts($user)['platform_subscriptions']);
        $this->assertSame($pendingUid, $this->accountOf($account['form']['email'])['subscription']->uid, 'The pending subscription identity is stable across retries.');

        // 3. Pay on Stripe's hosted page; the browser returns — repeatedly.
        $providerSubscriptionId = $this->stripe->completeCheckout((string) $this->accountOf($account['form']['email'])['subscription']->provider_checkout_session_id);
        $this->get(route('signup.success'))->assertRedirect(route('user.home'));
        $this->get(route('signup.success'))->assertRedirect(route('user.home'));
        $this->get(route('signup.success'))->assertRedirect(route('user.home'));

        $account = $this->accountOf($account['form']['email']) + ['form' => $account['form']];
        $workspace = $account['workspace'];

        $assignment = WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $assignment->status);
        $this->assertFalse((bool) $assignment->is_complimentary, 'A paid signup is never a complimentary grant.');
        $this->assertSame($planTier, app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace)->tier);

        $subscription = $account['subscription']->refresh();
        $this->assertSame($hasTrial ? PlatformSubscriptionStatus::Trialing : PlatformSubscriptionStatus::Active, $subscription->status);
        $this->assertSame($providerSubscriptionId, $subscription->provider_subscription_id);

        $decision = app(CustomerAccountAccessResolver::class)->resolve($workspace);
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertSame($hasTrial ? 'plan_trial' : 'usable', $decision->reason);

        // The Business goes live with the plan, from the same activation seam
        // the webhook uses.
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);

        // 4. Home is behind the email-verification gate…
        $this->get(route('user.home'))->assertRedirect(route('verification.notice'));

        // …and opens once the signed link is followed.
        $this->verifyEmailThroughSignedLink($user);
        $home = $this->get(route('user.home'))->assertOk();

        // 5. The shell is a Business shell, not a dead account hop.
        $keys = $this->menuKeys($home->getContent());
        $this->assertContains('home', $keys);
        $this->assertContains('contacts', $keys);
        $this->assertContains('settings', $keys);

        // 6. Still one tenant after every refresh.
        $this->get(route('user.home'))->assertOk();
        $this->get(route('user.home'))->assertOk();
        $this->assertSame([
            'users' => 1,
            'workspaces' => 1,
            'businesses' => 1,
            'locations' => 1,
            'primary_locations' => 1,
            'platform_subscriptions' => 1,
            'plan_assignments' => 1,
            'wallets' => 1,
            'payer_assignments' => 1,
        ], $this->identityCounts($user));
    }

    // =================================================================
    // A — Core: the shell matches Core, and Growth/Agency are not reachable
    // =================================================================

    public function test_core_shell_offers_core_and_a_guessed_growth_or_agency_route_is_refused(): void
    {
        $account = $this->completeJourney('core');
        $home = $this->get(route('user.home'))->assertOk();
        $keys = $this->menuKeys($home->getContent());

        // Core's Business shell: the canonical entitlements Core carries.
        foreach (['home', 'conversations', 'contacts', 'opportunities', 'calendar', 'forms', 'automations', 'website', 'seo', 'seo-overview', 'seo-keywords', 'settings'] as $expected) {
            $this->assertContains($expected, $keys, "Core must show [{$expected}].");
        }

        // Growth-only and Agency-only entries do not leak into Core's menu.
        foreach (['gbp', 'seo-audit', 'seo-citations', 'seo-reviews', 'prospecting'] as $notCore) {
            $this->assertNotContains($notCore, $keys, "Core must not show [{$notCore}].");
        }
        // The Core account is a Business shell, not the Agency account frame.
        $this->assertNotContains('accounts', $keys);

        $workspace = $account['workspace'];
        $business = $account['business'];
        $scoped = [$workspace->uid, $business->uid];

        // Guessing the route of a feature Core is not entitled to is refused —
        // the menu being absent was never the authorization.
        $this->get(route('customer.workspaces.businesses.gbp.index', $scoped))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.seo.audit.index', $scoped))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.seo.citations.index', $scoped))->assertNotFound();
        $this->get(route('customer.workspaces.businesses.seo.reviews.index', $scoped))->assertNotFound();

        // Agency management surfaces do not exist for a Core Workspace.
        $this->get(route('customer.workspaces.clients.index', [$workspace->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.agency.white-label.show', [$workspace->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.prospecting.overview', [$workspace->uid]))->assertNotFound();
        $this->post(route('customer.workspaces.client-invitations.store', [$workspace->uid]), ['email' => 'client@example.test'])
            ->assertSessionHas('status', 'error');
        $this->assertSame(0, ClientWorkspaceInvitation::query()->count(), 'A Core Workspace cannot invite an Agency client.');
    }

    // =================================================================
    // B — Growth: Growth-only features resolve through the same entitlement
    // =================================================================

    public function test_growth_shell_adds_growth_entitlements_and_still_refuses_agency_surfaces(): void
    {
        $account = $this->completeJourney('growth');
        $home = $this->get(route('user.home'))->assertOk();
        $keys = $this->menuKeys($home->getContent());

        foreach (['gbp', 'seo-audit', 'seo-citations', 'seo-reviews'] as $growthOnly) {
            $this->assertContains($growthOnly, $keys, "Growth must show [{$growthOnly}].");
        }
        $this->assertNotContains('accounts', $keys, 'Core-only assumptions: Growth is a Business shell, not an account frame.');
        $this->assertNotContains('prospecting', $keys);

        $workspace = $account['workspace'];
        $business = $account['business'];
        $scoped = [$workspace->uid, $business->uid];

        // The decision the menu shows IS the entitlement system's decision.
        $entitlements = app(EntitlementManager::class);
        foreach (['google_business_profile_module', 'seo_module'] as $feature) {
            $this->assertTrue(
                $entitlements->snapshotBusinessFeatureDecisions($workspace, $business, [$feature], (int) $account['user']->id)[$feature]->allowed,
                "[{$feature}] must resolve as entitled for Growth through EntitlementManager."
            );
        }

        $this->get(route('customer.workspaces.businesses.seo.audit.index', $scoped))->assertOk();

        // Agency-only surfaces stay unreachable.
        $this->get(route('customer.workspaces.clients.index', [$workspace->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.agency.white-label.show', [$workspace->uid]))->assertNotFound();
        $this->get(route('customer.workspaces.prospecting.overview', [$workspace->uid]))->assertNotFound();
    }

    // =================================================================
    // C — Agency: its own ordinary Business, plus Agency management surfaces
    // =================================================================

    public function test_agency_gets_its_own_workspace_business_and_location_and_no_client_businesses(): void
    {
        $account = $this->completeJourney('agency');
        $workspace = $account['workspace'];

        // Its own normal tenant — and ONLY that. No client Business is ever
        // created inside the Agency's own Workspace.
        $this->assertSame(1, Business::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame((int) $account['user']->id, (int) $account['business']->customer_id);
        $this->assertSame(0, DB::table('agency_client_workspace_relationships')->where('agency_workspace_id', $workspace->id)->count());
        $this->assertSame(WorkspacePlanTier::Agency, app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace)->tier);

        // Ordinary Business operation is available for the Agency's own Business.
        $home = $this->get(route('user.home'))->assertOk();
        $keys = $this->menuKeys($home->getContent());
        foreach (['home', 'contacts', 'conversations', 'settings'] as $expected) {
            $this->assertContains($expected, $keys, "The Agency's own Business shell must show [{$expected}].");
        }
        $this->get(route('customer.workspaces.businesses.people.index', [$workspace->uid, $account['business']->uid]))->assertOk();

        // The Agency management surfaces are reachable through the account frame.
        $this->switchToAccount($workspace)->assertRedirect();
        $accountHome = $this->get(route('user.home'))->assertOk();
        $accountKeys = $this->menuKeys($accountHome->getContent());
        $this->assertContains('accounts', $accountKeys, 'Client accounts is an Agency surface.');
        $this->assertContains('prospecting', $accountKeys);
        $this->get(route('customer.workspaces.clients.index', [$workspace->uid]))->assertOk();
        $this->get(route('customer.workspaces.agency.white-label.show', [$workspace->uid]))->assertOk();
    }
}
