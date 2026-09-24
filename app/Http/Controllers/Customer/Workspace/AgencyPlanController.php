<?php

namespace App\Http\Controllers\Customer\Workspace;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\AgencyBilling\AgencyClientPlanPresenter;
use App\Library\AgencyBilling\AgencyClientSubscriptionManager;
use App\Library\Workspace\AccountFrameAccess;
use App\Models\AgencySaasPlan;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Lane C §C6/§C8 — the CLIENT's own view of what their agency bills them, and
 * the only place a lane-C charge can be authorised.
 *
 * THIS IS THE CONSENT SURFACE, and every route on it belongs to the payer. The
 * agency that offered the plan cannot reach these actions, nor can agency
 * staff, nor can a platform administrator, nor can anyone acting through View
 * As. That refusal is enforced inside
 * `AgencyClientSubscriptionManager` — in the domain, where no future call site
 * can skip it — and this controller adds a 404 in front of it so an actor who
 * may not act does not even learn the surface exists.
 *
 * NO CARD DETAIL EVER REACHES THIS APPLICATION. Every money action here either
 * redirects to Stripe's hosted Checkout or to the agency-hosted Billing Portal;
 * there is no field anywhere on this surface that could accept a card number.
 *
 * OWNER OR ACTIVE ADMIN ONLY, NEVER STAFF — the same rule the Workspace's own
 * Plan & subscription page applies, restated because these actions move money.
 */
class AgencyPlanController extends CustomerBaseController
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly WorkspaceMembershipRepository $memberships,
        private readonly AgencyClientSubscriptionManager $subscriptions,
        private readonly AgencyClientPlanPresenter $presenter,
    ) {
    }

    /**
     * §C8 — review the agency's offer, or the subscription it became.
     *
     * READ-ONLY, so it uses the ordinary account-frame rule rather than the
     * financial one: a client should be able to SEE what they are being billed
     * before they are asked to agree to it.
     */
    public function show(string $workspaceUid): View
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        return view('customer.workspaces.agency-plan', [
            'workspace' => $workspace,
            'agencyPlan' => $this->presenter->present($workspace),
        ]);
    }

    /**
     * §C6 — THE CONSENT ACTION. The client agrees to the terms they have just
     * been shown, and hosted Checkout opens on the agency's own Stripe account.
     */
    public function checkout(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        // A charge is never a stray POST: the client says yes to the exact
        // terms on the page.
        $request->validate(['confirm' => ['required', 'accepted']]);

        try {
            $session = $this->subscriptions->startCheckout(
                (int) Auth::id(),
                $workspace,
                (string) Auth::user()?->email,
                route('customer.workspaces.agency-plan.return', [$workspaceUid]),
                route('customer.workspaces.agency-plan.show', [$workspaceUid]),
            );
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->away((string) $session->url);
    }

    /**
     * §C7 — the Checkout return. It trusts no query flag: it re-reads the
     * session from the agency's account through the SAME shared seam the
     * webhook uses.
     *
     * The webhook alone is sufficient, so this is a convenience for the browser
     * that did come back, never the only way the subscription converges.
     */
    public function checkoutReturn(string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);
        $subscription = $this->subscriptions->findForClientWorkspace($workspace);

        if ($subscription === null || blank($subscription->provider_checkout_session_id)) {
            return redirect()->route('customer.workspaces.agency-plan.show', [$workspaceUid]);
        }

        try {
            $this->subscriptions->confirmCheckoutSession(
                $subscription,
                (string) $subscription->provider_checkout_session_id,
            );
        } catch (AgencyBillingException $e) {
            return redirect()->route('customer.workspaces.agency-plan.show', [$workspaceUid])
                ->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->route('customer.workspaces.agency-plan.show', [$workspaceUid]);
    }

    /**
     * §C7 — upgrade takes effect immediately; downgrade at the end of the
     * period already paid for.
     */
    public function changePlan(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        $data = $request->validate([
            'plan_uid' => ['required', 'string'],
            'confirm' => ['required', 'accepted'],
        ]);

        $plan = AgencySaasPlan::query()->where('uid', $data['plan_uid'])->first() ?? abort(404);

        try {
            $direction = $this->subscriptions->requestPlanChange((int) Auth::id(), $workspace, $plan);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with([
            'status' => 'success',
            'message' => $direction === AgencyClientSubscriptionManager::CHANGE_UPGRADED
                ? __('Your plan has been upgraded and is active now.')
                : __('Your plan will change at the end of your current billing period.'),
        ]);
    }

    /** §C7 — cancellation preserves access through the period already paid for. */
    public function cancel(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        $request->validate(['confirm' => ['required', 'accepted']]);

        try {
            $subscription = $this->subscriptions->requestCancellation((int) Auth::id(), $workspace);
        } catch (AgencyBillingException $e) {
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

    /** §C7 — undo a scheduled cancellation while the period is still running. */
    public function resume(string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        try {
            $this->subscriptions->resumeSubscription((int) Auth::id(), $workspace);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('Your subscription will continue as normal.')]);
    }

    /**
     * §C8 — the agency-hosted Stripe Billing Portal, which is how a client
     * fixes the card their agency charges. No card field exists here.
     */
    public function paymentMethod(string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        try {
            $url = $this->subscriptions->billingPortalUrl(
                (int) Auth::id(),
                $workspace,
                route('customer.workspaces.agency-plan.show', [$workspaceUid]),
                AgencyClientSubscriptionManager::PORTAL_FLOW_PAYMENT_METHOD,
            );
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->away($url);
    }

    /** §C7 — start again after the subscription has fully ended. */
    public function resubscribe(Request $request, string $workspaceUid): RedirectResponse
    {
        $workspace = $this->authorizedWorkspace($workspaceUid);

        $data = $request->validate([
            'plan_uid' => ['required', 'string'],
            'confirm' => ['required', 'accepted'],
        ]);

        $plan = AgencySaasPlan::query()->where('uid', $data['plan_uid'])->first() ?? abort(404);

        try {
            $session = $this->subscriptions->startResubscribeCheckout(
                (int) Auth::id(),
                $workspace,
                $plan,
                (string) Auth::user()?->email,
                route('customer.workspaces.agency-plan.return', [$workspaceUid]),
                route('customer.workspaces.agency-plan.show', [$workspaceUid]),
            );
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->away((string) $session->url);
    }

    /**
     * Owner or active Admin who can see the account frame. Anything else —
     * Staff, a Business-scoped member, the managing agency, a stranger, a
     * missing Workspace — is 404, so existence is never disclosed.
     *
     * The DOMAIN applies this rule again for every money action, plus the View
     * As refusal, so this is a discovery guard rather than the security itself.
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
