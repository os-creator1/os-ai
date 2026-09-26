<?php

namespace App\Http\Controllers\Customer\Agency;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\AgencyBilling\AgencyStripeConnectionStatus;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Exceptions\Workspace\AgencyWorkspaceNotEligibleException;
use App\Http\Controllers\Controller;
use App\Library\AgencyBilling\AgencyClientSubscriptionManager;
use App\Library\AgencyBilling\AgencySaasPlanManager;
use App\Library\AgencyBilling\AgencyStripeConnectManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencySaasPlan;
use App\Models\Currency;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Lane C §C8 — the AGENCY's own SaaS surface: connect the account that receives
 * the revenue, publish resale plans, offer them to managed clients, and see
 * what is being earned.
 *
 * TWO DIFFERENT AUTHORITY RULES, AND THE DIFFERENCE IS DELIBERATE.
 *
 *   READING is Agency-team work. Any active Agency member with agency authority
 *   may see the Clients list and the subscription states on it, exactly as
 *   Blueprint §28 already allows for ordinary client management.
 *
 *   WRITING ANYTHING COMMERCIAL is the Agency OWNER's alone — connecting
 *   Stripe, creating and publishing plans, and offering a plan to a client.
 *   Blueprint §2 reserves financial configuration to the owner and §26 keeps
 *   Staff out of billing entirely, so Agency team membership grants client
 *   MANAGEMENT and never authority over the Agency's money.
 *
 * The owner rule is asserted inside the managers, not here, so no future call
 * site can skip it. This controller adds a 404 in front of it, so an actor who
 * may not act does not even learn the surface exists.
 *
 * NOTHING HERE TAKES A CLIENT'S MONEY. The one action that charges a card lives
 * on the CLIENT's side (§C6), because financial consent belongs to whoever is
 * being charged.
 */
class AgencySaasController extends Controller
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly AgencyStripeConnectManager $connections,
        private readonly AgencySaasPlanManager $plans,
        private readonly AgencyClientSubscriptionManager $subscriptions,
    ) {
    }

    // =====================================================================
    // §C5.1 — the account that receives the Agency's revenue
    // =====================================================================

    public function stripe(string $workspaceUid): View
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);
        $connection = $this->connections->liveConnection($agencyWorkspace);

        return view('customer.agency.saas.stripe', [
            'agencyWorkspace' => $agencyWorkspace,
            'connection' => $connection,
            'isOwner' => $this->isOwner($agencyWorkspace),
            'chargeReady' => $this->connections->isChargeReady($agencyWorkspace),
            'history' => $this->connections->history($agencyWorkspace),
        ]);
    }

    public function connect(Request $request, string $workspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);

        $data = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        try {
            $url = $this->connections->connect(
                (int) Auth::id(),
                $agencyWorkspace,
                mb_strtoupper($data['country']),
                $data['email'] ?? null,
                route('customer.workspaces.agency.saas.stripe', [$workspaceUid]),
                route('customer.workspaces.agency.saas.stripe', [$workspaceUid]),
            );
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        // Stripe's own hosted onboarding. No identity document, bank detail or
        // credential is ever typed into this application.
        return redirect()->away($url);
    }

    public function resumeOnboarding(string $workspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);

        try {
            $url = $this->connections->resumeOnboarding(
                (int) Auth::id(),
                $agencyWorkspace,
                route('customer.workspaces.agency.saas.stripe', [$workspaceUid]),
                route('customer.workspaces.agency.saas.stripe', [$workspaceUid]),
            );
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->away($url);
    }

    public function syncConnection(string $workspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);

        try {
            $this->connections->syncFromProvider((int) Auth::id(), $agencyWorkspace);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('Stripe account status refreshed.')]);
    }

    public function disconnect(Request $request, string $workspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);

        $request->validate(['confirm' => ['required', 'accepted']]);

        try {
            $this->connections->disconnect((int) Auth::id(), $agencyWorkspace);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with([
            'status' => 'success',
            // Honest about what it does and does not do (§C5.1).
            'message' => __('Disconnected. No new client subscriptions can be started, and existing ones were not cancelled on your behalf.'),
        ]);
    }

    /**
     * The server-side session key one pending OAuth attempt lives under,
     * namespaced so it can never collide with anything else in the session.
     */
    private const OAUTH_SESSION_PREFIX = 'agency_stripe_oauth_pending.';

    /** How long a started-but-not-completed OAuth attempt stays valid. */
    private const OAUTH_STATE_TTL_MINUTES = 10;

    /**
     * "Connect existing Stripe account" — starts Stripe's own hosted OAuth
     * flow (never an account-id text field: that would let an Agency name an
     * account it does not control, or silently duplicate one).
     *
     * TASK 1 — ONE FIXED, PLATFORM-WIDE CALLBACK URL. Stripe's own OAuth
     * settings register exactly one `redirect_uri` per platform; a URL that
     * varied per Agency Workspace (the previous `{workspaceUid}/.../callback`
     * shape) could never be registered there. The fixed callback below
     * carries no workspace in its own URL at all — which Agency Workspace,
     * which user, and when this attempt expires are instead bound
     * server-side, keyed by the random `state` value, in THIS SAME
     * authenticated session (Laravel's own session store — no new
     * infrastructure). The callback resolves the Agency exclusively from
     * that stored record, never from any request parameter.
     */
    public function connectExistingStart(string $workspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);

        $state = bin2hex(random_bytes(20));

        session()->put(self::OAUTH_SESSION_PREFIX . $state, [
            'agency_workspace_id' => (int) $agencyWorkspace->id,
            'user_id' => (int) Auth::id(),
            'expires_at' => now()->addMinutes(self::OAUTH_STATE_TTL_MINUTES)->timestamp,
        ]);

        try {
            $url = $this->connections->oauthAuthorizeUrl(
                (int) Auth::id(),
                $agencyWorkspace,
                $state,
                route('customer.agency.stripe.connect-existing.callback'),
            );
        } catch (AgencyBillingException $e) {
            session()->forget(self::OAUTH_SESSION_PREFIX . $state);

            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return redirect()->away($url);
    }

    /**
     * Stripe's own redirect back from the OAuth flow above, at the ONE fixed
     * URL every Agency shares (Task 1) — this action deliberately takes no
     * `{workspaceUid}` route parameter at all, so there is nothing here an
     * attacker could substitute. The Agency, the initiating user and this
     * attempt's freshness all come from the server-side record
     * `connectExistingStart()` stored under this exact `state` — never from
     * `$request`. The record is consumed (forgotten) UNCONDITIONALLY and
     * FIRST, before any of it is trusted, so a replay of the same `state`
     * (a resubmission, or an attacker who observed one redirect) always
     * finds nothing on a second attempt.
     *
     * Every rejection path below redirects to a SAFE, workspace-agnostic
     * destination (the customer home) with an honest, non-disclosing
     * message — never the Agency's own Stripe page, since an unverified
     * request must never be trusted enough to know which Agency to show.
     */
    public function connectExistingCallback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $sessionKey = self::OAUTH_SESSION_PREFIX . $state;
        $pending = $state !== '' ? session($sessionKey) : null;
        session()->forget($sessionKey);

        if (
            ! is_array($pending)
            || ! isset($pending['agency_workspace_id'], $pending['user_id'], $pending['expires_at'])
            || (int) $pending['expires_at'] < now()->timestamp
        ) {
            // Covers all of: no state given, unknown/already-consumed state
            // (replay), and an expired attempt — every one of them is
            // "this attempt cannot be trusted", answered identically.
            return $this->oauthFailureRedirect(AgencyBillingException::because(AgencyBillingException::OAUTH_STATE_MISMATCH)->customerMessage());
        }

        // CROSS-USER PROTECTION. The session cookie alone is not proof
        // enough — the record names the exact authenticated user who
        // started this attempt, and only that user may complete it.
        if ((int) $pending['user_id'] !== (int) Auth::id()) {
            return $this->oauthFailureRedirect(AgencyBillingException::because(AgencyBillingException::OAUTH_STATE_MISMATCH)->customerMessage());
        }

        $agencyWorkspace = $this->workspaces->findById((int) $pending['agency_workspace_id']);

        if ($agencyWorkspace === null) {
            return $this->oauthFailureRedirect(AgencyBillingException::because(AgencyBillingException::OAUTH_STATE_MISMATCH)->customerMessage());
        }

        // Re-asserted against the RESOLVED workspace, not merely trusted
        // from having been stored — the same 404-first rule every other
        // action on this controller applies, in case eligibility changed
        // between starting and completing this attempt.
        $agencyWorkspace = $this->authorizeAgencyWorkspace($agencyWorkspace);
        $stripeAccountRoute = route('customer.workspaces.agency.saas.stripe', [$agencyWorkspace->uid]);

        // The state is now genuinely verified, so it is safe to send the
        // user back to THEIR OWN Agency's page for every outcome from here
        // on, including a plain decline on Stripe's own page.
        if ($request->query('error') !== null) {
            return redirect($stripeAccountRoute)->with([
                'status' => 'error',
                'message' => __('Stripe account connection was not completed.'),
            ]);
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect($stripeAccountRoute)->with([
                'status' => 'error',
                'message' => AgencyBillingException::because(AgencyBillingException::OAUTH_FAILED)->customerMessage(),
            ]);
        }

        try {
            $connection = $this->connections->connectExisting((int) Auth::id(), $agencyWorkspace, $code);
        } catch (AgencyBillingException $e) {
            // TASK 4 — a real, useful message: Stripe genuinely refuses some
            // existing accounts (for example one already controlled by
            // another platform), and the honest answer is "try a different
            // account, or create a new one" — never a promise that every
            // existing account is eligible.
            return redirect($stripeAccountRoute)->with([
                'status' => 'error',
                'message' => $e->reason === AgencyBillingException::OAUTH_FAILED
                    ? __('That Stripe account could not be connected. It may already be controlled by another platform, or the authorization may have expired. You can try a different existing account, or create a new Stripe account instead.')
                    : $e->customerMessage(),
            ]);
        }

        if ($connection->status === AgencyStripeConnectionStatus::Incompatible) {
            return redirect($stripeAccountRoute)->with([
                'status' => 'error',
                'message' => __('That Stripe account\'s fee, loss-liability, requirement-collection or Dashboard configuration is not compatible with this platform, so it cannot be enabled for client payments. Disconnect it and connect a different account, or create a new one instead.'),
            ]);
        }

        return redirect($stripeAccountRoute)->with([
            'status' => 'success',
            'message' => __('Stripe account connected.'),
        ]);
    }

    /**
     * A rejection before the Agency can be safely resolved (missing,
     * expired, replayed, or cross-user state) never has a trustworthy
     * "this Agency's own page" to return to — it goes to the ordinary
     * customer home instead, with an honest error, never silently ignored.
     */
    private function oauthFailureRedirect(string $message): RedirectResponse
    {
        return redirect()->route('user.home')->with(['status' => 'error', 'message' => $message]);
    }

    // =====================================================================
    // §C3.2/§C5.2 — the Agency's own resale plans
    // =====================================================================

    public function plans(string $workspaceUid): View
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);

        return view('customer.agency.saas.plans', [
            'agencyWorkspace' => $agencyWorkspace,
            'plans' => $this->plans->forAgency($agencyWorkspace),
            'subscriberCounts' => $this->plans->subscriberCounts($agencyWorkspace),
            'isOwner' => $this->isOwner($agencyWorkspace),
            'chargeReady' => $this->connections->isChargeReady($agencyWorkspace),
            'resellableTiers' => AgencySaasPlanManager::resellableTiers(),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function storePlan(Request $request, string $workspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'tier' => ['required', 'string', 'in:' . implode(',', AgencySaasPlan::RESELLABLE_TIERS)],
            'price' => ['required', 'regex:/\A\d+(\.\d{1,2})?\z/'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
            'trial_enabled' => ['nullable', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $currency = Currency::query()->findOrFail($data['currency_id']);

        try {
            $this->plans->create((int) Auth::id(), $agencyWorkspace, [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'tier' => $data['tier'],
                'price' => $data['price'],
                'currency_id' => (int) $currency->id,
                'currency_code' => (string) $currency->code,
                'billing_cycle' => $data['billing_cycle'],
                'trial_enabled' => (bool) ($data['trial_enabled'] ?? false),
                'trial_days' => $data['trial_days'] ?? null,
            ]);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('Plan created. Connect its Stripe price, then publish it.')]);
    }

    public function updatePlan(Request $request, string $workspaceUid, string $planUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);
        $plan = $this->authorizedPlan($agencyWorkspace, $planUid);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'regex:/\A\d+(\.\d{1,2})?\z/'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
            'trial_enabled' => ['nullable', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $currency = Currency::query()->findOrFail($data['currency_id']);

        try {
            $this->plans->update((int) Auth::id(), $plan, [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price' => $data['price'],
                'currency_id' => (int) $currency->id,
                'currency_code' => (string) $currency->code,
                'billing_cycle' => $data['billing_cycle'],
                'trial_enabled' => (bool) ($data['trial_enabled'] ?? false),
                'trial_days' => $data['trial_days'] ?? null,
            ], $data['reason'] ?? null);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with([
            'status' => 'success',
            // The honest consequence, stated rather than discovered.
            'message' => __('Plan updated. Changing the price unpublishes it until a matching Stripe price is connected; existing subscribers keep the terms they agreed to.'),
        ]);
    }

    public function bindPrice(Request $request, string $workspaceUid, string $planUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);
        $plan = $this->authorizedPlan($agencyWorkspace, $planUid);

        $data = $request->validate([
            // Empty means "create one for me on my own Stripe account".
            'provider_price_id' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $this->plans->bindProviderPrice((int) Auth::id(), $plan, $data['provider_price_id'] ?? null);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('Stripe price connected and verified against your own Stripe account.')]);
    }

    public function publishPlan(string $workspaceUid, string $planUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);
        $plan = $this->authorizedPlan($agencyWorkspace, $planUid);

        try {
            $this->plans->publish((int) Auth::id(), $plan);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('Plan published. You can now offer it to your clients.')]);
    }

    public function unpublishPlan(string $workspaceUid, string $planUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);
        $plan = $this->authorizedPlan($agencyWorkspace, $planUid);

        try {
            $this->plans->unpublish((int) Auth::id(), $plan);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with([
            'status' => 'success',
            'message' => __('Plan unpublished. Existing subscribers are unaffected.'),
        ]);
    }

    // =====================================================================
    // §C6 — offering a plan to a managed client
    // =====================================================================

    public function offer(Request $request, string $workspaceUid, string $clientWorkspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);
        $clientWorkspace = $this->linkedClient($agencyWorkspace, $clientWorkspaceUid);

        $data = $request->validate([
            'plan_uid' => ['required', 'string'],
            'confirm' => ['required', 'accepted'],
        ]);

        $plan = $this->authorizedPlan($agencyWorkspace, $data['plan_uid']);

        try {
            $this->subscriptions->offer((int) Auth::id(), $agencyWorkspace, $clientWorkspace, $plan);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with([
            'status' => 'success',
            // The Agency is told plainly that nothing has been charged.
            'message' => __('Plan offered. Your client reviews the terms and authorises the payment themselves — nothing has been charged.'),
        ]);
    }

    public function withdrawOffer(string $workspaceUid, string $clientWorkspaceUid): RedirectResponse
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);
        $clientWorkspace = $this->linkedClient($agencyWorkspace, $clientWorkspaceUid);

        try {
            $this->subscriptions->withdrawOffer((int) Auth::id(), $agencyWorkspace, $clientWorkspace);
        } catch (AgencyBillingException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => __('Offer withdrawn.')]);
    }

    // =====================================================================
    // §C8 — the Agency's own revenue, labelled as the Agency's
    // =====================================================================

    public function revenue(string $workspaceUid): View
    {
        $agencyWorkspace = $this->authorizedAgency($workspaceUid);

        $subscriptions = $this->subscriptions->forAgency($agencyWorkspace);
        $plans = $this->plans->forAgency($agencyWorkspace)->keyBy('id');

        $rows = $subscriptions->map(function (AgencyClientSubscription $subscription) use ($plans): array {
            $client = Workspace::query()->find($subscription->client_workspace_id);

            return [
                'client_name' => $client?->name,
                'client_uid' => $client?->uid,
                'plan_name' => $plans[$subscription->agency_saas_plan_id]->name ?? null,
                'status' => $subscription->status->value,
                'price' => $subscription->price_snapshot,
                'currency_code' => $subscription->currency_code,
                'billing_cycle' => $subscription->billing_cycle_snapshot,
                'current_period_end' => $subscription->current_period_end,
                'needs_attention' => in_array($subscription->status, [
                    AgencyClientSubscriptionStatus::PastDue,
                    AgencyClientSubscriptionStatus::Unpaid,
                    AgencyClientSubscriptionStatus::Paused,
                ], true),
            ];
        })->values();

        // Recurring revenue the AGENCY bills, grouped by the currency it is
        // billed in. Deliberately NOT summed across currencies: a single
        // number would be a fiction.
        $recurringByCurrency = [];

        foreach ($subscriptions as $subscription) {
            if (! $subscription->status->grantsAccess() || $subscription->price_snapshot === null) {
                continue;
            }

            $code = (string) $subscription->currency_code;
            $recurringByCurrency[$code] = bcadd(
                (string) ($recurringByCurrency[$code] ?? '0.00'),
                (string) $subscription->price_snapshot,
                2,
            );
        }

        return view('customer.agency.saas.revenue', [
            'agencyWorkspace' => $agencyWorkspace,
            'rows' => $rows,
            'recurringByCurrency' => $recurringByCurrency,
            'attentionCount' => $rows->where('needs_attention', true)->count(),
        ]);
    }

    // =====================================================================
    // Authorization
    // =====================================================================

    /**
     * The same rule Contract 08A's Clients screen already applies: agency
     * authority for this exact Agency Workspace, plus live Agency management
     * eligibility. 404 rather than 403, so existence is never disclosed.
     */
    private function authorizedAgency(string $workspaceUid): Workspace
    {
        $agencyWorkspace = $this->workspaces->findByUid($workspaceUid) ?? abort(404);

        return $this->authorizeAgencyWorkspace($agencyWorkspace);
    }

    /**
     * The same authority/eligibility check as authorizedAgency() above,
     * factored out so a Workspace already resolved another way (Task 1's
     * OAuth callback resolves one from verified server-side state, never a
     * uid in the request) is asserted through the identical rule rather
     * than a second copy of it.
     */
    private function authorizeAgencyWorkspace(Workspace $agencyWorkspace): Workspace
    {
        if (! $this->relationships->actorHasAgencyAuthority((int) Auth::id(), $agencyWorkspace)) {
            abort(404);
        }

        try {
            $this->relationships->assertAgencyWorkspaceHasManagementEligibility($agencyWorkspace);
        } catch (AgencyWorkspaceNotEligibleException) {
            abort(404);
        }

        return $agencyWorkspace;
    }

    private function isOwner(Workspace $agencyWorkspace): bool
    {
        return (int) $agencyWorkspace->owner_user_id === (int) Auth::id();
    }

    /** A plan that belongs to THIS Agency, or 404. */
    private function authorizedPlan(Workspace $agencyWorkspace, string $planUid): AgencySaasPlan
    {
        return AgencySaasPlan::query()
            ->where('agency_workspace_id', $agencyWorkspace->id)
            ->where('uid', $planUid)
            ->first() ?? abort(404);
    }

    /**
     * A Workspace this EXACT Agency currently, actively manages. A terminated
     * relationship, one belonging to a different Agency, and an unrelated
     * Workspace are refused identically.
     */
    private function linkedClient(Workspace $agencyWorkspace, string $clientWorkspaceUid): Workspace
    {
        $clientWorkspace = $this->workspaces->findByUid($clientWorkspaceUid) ?? abort(404);

        $relationship = $this->relationships->findActiveForClientWorkspace((int) $clientWorkspace->id);

        if ($relationship === null || (int) $relationship->agency_workspace_id !== (int) $agencyWorkspace->id) {
            abort(404);
        }

        return $clientWorkspace;
    }
}
