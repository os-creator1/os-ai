<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Library\Workspace\AccountFrameAccess;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 21 §10/§13/§4 — the customer's own subscription
 * ACTIONS: change plan, cancel, resume, and fix the payment method.
 *
 * SEPARATE FROM WorkspaceController ON PURPOSE. That controller already
 * renders the Plan & subscription page and is very large; money-moving actions
 * belong together, in one place, where their authorization rule is stated once.
 *
 * THIS IS NOT THE LEGACY SubscriptionController. That one renews, purchases and
 * cancels legacy `Subscription` rows through inherited gateways (§3.1); it is a
 * different product's billing and is deliberately not resurrected here.
 *
 * OWNER OR ACTIVE ADMIN ONLY, NEVER STAFF (§13). The same rule the read-only
 * page already applies, restated here because these actions move money: a
 * Business-scoped or Staff member must not be able to upgrade, cancel, or open
 * a billing portal against somebody else's card. A failure is 404, not 403 —
 * existence is not disclosed to someone who may not see the account frame.
 */
class PlanSubscriptionController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly WorkspaceMembershipRepository $memberships,
        private readonly PlatformSubscriptionManager $subscriptions,
    ) {
    }

    /**
     * §10.2 — upgrade takes effect immediately; downgrade is scheduled for the
     * end of the period the customer has already paid for.
     */
    public function changePlan(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        $data = $request->validate([
            'tier' => ['required', 'string', 'in:core,growth,agency'],
            // §10.2 — the customer is shown what the change will do and has to
            // say yes to it. A plan change is never a stray POST.
            'confirm' => ['required', 'accepted'],
        ]);

        $catalog = WorkspacePlanCatalog::query()->where('tier', $data['tier'])->firstOrFail();

        try {
            $direction = $this->subscriptions->requestPlanChange(
                $workspace, $catalog, (int) $workspace->owner_user_id,
            );
        } catch (PlatformBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with([
            'status' => 'success',
            'message' => $direction === PlatformSubscriptionManager::CHANGE_UPGRADED
                ? __('Your plan has been upgraded and is active now.')
                : __('Your plan will change at the end of your current billing period.'),
        ]);
    }

    /**
     * §10.4 — START AGAIN after a subscription has fully ended.
     *
     * The account is reused, never rebuilt: same Workspace, same Business,
     * same Locations. What is created is a NEW provider subscription, through
     * hosted Checkout, because a canceled Stripe subscription cannot be
     * revived by changing its Price.
     */
    public function resubscribe(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        $data = $request->validate([
            'tier' => ['required', 'string', 'in:core,growth,agency'],
            'confirm' => ['required', 'accepted'],
        ]);

        $catalog = WorkspacePlanCatalog::query()->where('tier', $data['tier'])->firstOrFail();

        try {
            $session = $this->subscriptions->startResubscribeCheckout(
                $workspace,
                $catalog,
                (string) Auth::user()?->email,
                route('customer.workspaces.plan.resubscribe-return', [$workspaceUid]),
                route('customer.workspaces.plan.show', [$workspaceUid]),
            );
        } catch (PlatformBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->away((string) $session->url);
    }

    /**
     * §8.5 — the Checkout return for a re-subscribe. It trusts no query flag:
     * it re-reads the session from the provider through the shared confirm
     * seam, which is the SAME one the webhook uses.
     *
     * The webhook alone is sufficient, so this endpoint is a convenience for
     * the browser that did come back, never the only way the account
     * converges.
     */
    public function resubscribeReturn(string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);
        $subscription = $this->subscriptions->findForWorkspace($workspace);

        if ($subscription === null || blank($subscription->provider_checkout_session_id)) {
            return redirect()->route('customer.workspaces.plan.show', [$workspaceUid]);
        }

        try {
            $this->subscriptions->confirmCheckoutSession((string) $subscription->provider_checkout_session_id);
        } catch (PlatformBillingException $e) {
            return redirect()->route('customer.workspaces.plan.show', [$workspaceUid])
                ->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->route('customer.workspaces.plan.show', [$workspaceUid]);
    }

    /**
     * §10.3 — cancellation preserves access through the paid period. Repeating
     * it is harmless: the provider call is a set-to-true, not a toggle.
     */
    public function cancel(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        $request->validate(['confirm' => ['required', 'accepted']]);

        try {
            $subscription = $this->subscriptions->requestCancellation($workspace);
        } catch (PlatformBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with([
            'status' => 'success',
            'message' => $subscription->current_period_end === null
                ? __('Your subscription will not renew.')
                : __('Your subscription will end on :date. You keep full access until then.', [
                    'date' => $subscription->current_period_end->toFormattedDateString(),
                ]),
        ]);
    }

    /**
     * §10.3 — undo a scheduled cancellation. Offered only because the provider
     * model genuinely supports it: `cancel_at_period_end` is a boolean we can
     * set back to false while the period is still running.
     */
    public function resume(string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        try {
            $this->subscriptions->resumeSubscription($workspace);
        } catch (PlatformBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('Your subscription will continue as normal.')]);
    }

    /**
     * §4 — the payment-method recovery route, reachable from Plan &
     * subscription and from the Grace billing warning.
     *
     * It redirects to Stripe's own hosted Billing Portal. NO CARD DETAIL EVER
     * REACHES THIS APPLICATION — there is no form here to type one into.
     */
    public function paymentMethod(string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        try {
            $url = $this->subscriptions->billingPortalUrl(
                $workspace,
                route('customer.workspaces.plan.show', [$workspaceUid]),
                PlatformSubscriptionManager::PORTAL_FLOW_PAYMENT_METHOD,
            );
        } catch (PlatformBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->away($url);
    }

    /**
     * Owner or active Admin who can see the account frame. Anything else —
     * Staff, a Business-scoped member, a stranger, a missing Workspace — is
     * 404, so existence is never disclosed.
     */
    private function authorizedWorkspace(string $workspaceUid): Workspace
    {
        $workspace = $this->workspaces->findByUid($workspaceUid) ?? abort(404);
        $userId = (int) Auth::id();

        if ((int) $workspace->owner_user_id === $userId) {
            return $workspace;
        }

        $membership = $this->memberships->findByWorkspaceAndUser($workspace, $userId);

        if ($membership === null
            || ! $membership->is_active
            || $membership->role !== WorkspaceMembershipRole::Admin
            || ! AccountFrameAccess::membershipAllows($membership)) {
            abort(404);
        }

        return $workspace;
    }
}
