<?php

namespace App\Library\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Business\BusinessManager;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementation Contract 21 §7 — the canonical V1 signup, orchestrated.
 *
 * Blueprint §6's flow, with the ordering chosen for DURABILITY rather than for
 * the order the screens happen to appear in:
 *
 *   1. PROVISION FIRST, because none of it costs money. The Workspace, its one
 *      Business and that Business's Primary Location are created together and
 *      committed before Stripe is ever contacted.
 *   2. THEN TAKE THE MONEY. Checkout runs against the durable
 *      PlatformSubscription row anchored to that Workspace.
 *   3. ONLY THEN ASSIGN THE PLAN and install the Blueprint, from a
 *      PROVIDER-CONFIRMED subscription.
 *
 * WHY THIS ORDER. The alternative — stashing a half-built account in the
 * session and materialising it on return from Stripe — loses the whole signup
 * if the session dies, and is unreachable from the webhook, which is the one
 * path guaranteed to arrive. Provisioning first means the confirmation step
 * only ever has to do the part that genuinely depends on payment.
 *
 * AN ABANDONED CHECKOUT IS A KNOWN, SAFE STATE. It leaves a Workspace with NO
 * plan assignment, which `CustomerAccountAccessResolver` already treats as a
 * distinct pre-existing case ("an unassigned Workspace … onboarding/plan
 * selection owns it"). It is never a paid account, and §7's "no successful
 * provider result → no fabricated paid Active state" holds by construction:
 * the assignment simply does not exist yet.
 *
 * SIGNUP REQUIRES NO A2P, NO GOOGLE CONNECTION, NO CALENDAR AND NO BUSINESS
 * STRIPE CONNECT (§7). Those are post-signup checklist items, and nothing here
 * touches them.
 *
 * LANE A ONLY. This class never reads or writes a Business's own connected
 * Stripe account, a usage wallet, or any legacy Plan/Subscription row (§2/§4).
 */
final class V1SignupManager
{
    public function __construct(
        private readonly WorkspaceManager $workspaces,
        private readonly BusinessManager $businesses,
        private readonly PlatformSubscriptionManager $subscriptions,
        private readonly NicheBlueprintInstaller $blueprints,
    ) {
    }

    /**
     * Steps 1 and 2 — provision, then open checkout.
     *
     * @param  array{business_name: string, industry: string, timezone?: ?string, country_code?: ?string, location_name?: ?string}  $draft
     *
     * @throws PlatformBillingException
     */
    public function startSubscription(
        Customer $customer,
        array $draft,
        WorkspacePlanCatalog $catalog,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult {
        if (! $catalog->isSellable()) {
            throw PlatformBillingException::because(
                (bool) $catalog->available_for_signup && (bool) $catalog->is_active
                    ? PlatformBillingException::TIER_NOT_PRICED
                    : PlatformBillingException::TIER_NOT_AVAILABLE
            );
        }

        $workspace = $this->provision($customer, $draft);

        return $this->subscriptions->startCheckout(
            $workspace,
            $catalog,
            (string) $customer->user->email,
            $successUrl,
            $cancelUrl,
        );
    }

    /**
     * Step 3 — confirm from PROVIDER TRUTH, then assign the plan and install
     * the Blueprint.
     *
     * Safe to call repeatedly: the finalizer is idempotent and the assignment
     * is created only when there is not one already, so the browser returning
     * to the success URL and the webhook arriving can both call this without
     * racing into two assignments.
     *
     * @throws PlatformBillingException
     */
    public function completeSignup(string $checkoutSessionId): ?Workspace
    {
        $subscription = $this->subscriptions->confirmCheckoutSession($checkoutSessionId);

        if ($subscription === null) {
            // Nothing confirmed. §7 — no fabricated paid state.
            return null;
        }

        $workspace = Workspace::query()->find($subscription->workspace_id);

        if ($workspace === null) {
            return null;
        }

        $this->activate($workspace, $subscription);

        return $workspace->fresh();
    }

    /**
     * The part that genuinely depends on a confirmed subscription: the V1 plan
     * assignment, and the entitlement-filtered Blueprint installation that
     * depends on it.
     */
    public function activate(Workspace $workspace, PlatformSubscription $subscription): void
    {
        $catalog = WorkspacePlanCatalog::query()->find($subscription->workspace_plan_catalog_id);

        if ($catalog === null) {
            return;
        }

        $assignment = app(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class)
            ->findByWorkspaceId((int) $workspace->id);

        if ($assignment === null) {
            $this->subscriptions->assignPlanFromConfirmedSubscription(
                $workspace,
                $subscription,
                $catalog->tier,
                (int) $workspace->owner_user_id,
            );
        }

        $this->installBlueprint($workspace);
    }

    /**
     * Blueprint §22 — the niche's Blueprint is installed into the Business,
     * filtered to what the chosen plan entitles.
     *
     * A Blueprint failure must not fail the signup: the customer has paid and
     * their account exists. The setup checklist on Home is what surfaces
     * anything still missing (Blueprint §8), and
     * `InstallMissingBlueprintComponentsCommand` already exists to reconcile.
     */
    private function installBlueprint(Workspace $workspace): void
    {
        $business = Business::query()->where('workspace_id', $workspace->id)->first();

        if ($business === null) {
            return;
        }

        try {
            $this->blueprints->installForBusiness($business);
        } catch (Throwable $e) {
            Log::warning('V1 signup: Blueprint installation deferred.', [
                'business_id' => $business->id,
                'exception' => class_basename($e),
            ]);
        }
    }

    /**
     * Blueprint §6 — "Workspace + Business + Primary Location created
     * together". Contract 13 already makes one Business per Workspace
     * structural, so this creates exactly one of each.
     *
     * @param  array<string, mixed>  $draft
     */
    private function provision(Customer $customer, array $draft): Workspace
    {
        $existing = Workspace::query()
            ->where('owner_user_id', $customer->user_id)
            ->orderBy('id')
            ->first();

        // Re-entering signup after abandoning checkout must not create a
        // second Workspace for the same person.
        $workspace = $existing ?? $this->workspaces->createWorkspace(
            (int) $customer->user_id,
            (string) $draft['business_name'],
        );

        $business = Business::query()->where('workspace_id', $workspace->id)->first();

        if ($business === null) {
            $business = $this->businesses->createBusinessForNewWorkspace($customer, $workspace, [
                'name' => (string) $draft['business_name'],
                'industry' => (string) $draft['industry'],
                'timezone' => $draft['timezone'] ?? null,
                'country_code' => $draft['country_code'] ?? null,
            ]);
        }

        $this->businesses->upsertPrimaryLocation($customer, $business, [
            'name' => $draft['location_name'] ?? (string) $draft['business_name'],
            'country_code' => $draft['country_code'] ?? 'US',
            'service_mode' => 'storefront',
        ]);

        return $workspace->fresh();
    }
}
