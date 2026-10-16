<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Payments\StripeConnectException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Payments\StripeConnectManager;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Implementation Contract 17 §12.D — the Business-side Stripe Connect
 * onboarding surface for money lane B.
 *
 * THE GATE CHAIN, unchanged from Sub-slice B's DocumentsController: Workspace
 * by uid -> Business inside it -> userCanAccessBusiness() -> Business Active
 * -> the `payments_contracts` capability -> the PaymentsContracts entitlement
 * decision. Every tenancy or entitlement failure is `abort(404)`, never 403,
 * so a foreign Business is indistinguishable from a missing one. The account
 * lifecycle gate (Locked/Grace) is the customer route group's own middleware,
 * exactly as for every other Business surface.
 *
 * ON TOP OF THAT, OWNER-ONLY (§6.2). Establishing or terminating a Business's
 * Stripe relationship is financial consent, so StripeConnectManager re-derives
 * ownership from persistence for every mutating call and refuses an ordinary
 * Admin/Staff member. The read-only status page stays available to anyone who
 * already passed the chain, so staff can SEE whether payments are possible
 * without being able to change it.
 *
 * FAIL-CLOSED WHILE `Planned`. PaymentsContracts is registered Planned until
 * Sub-slice G, so EntitlementManager denies it for every tier and every route
 * here answers 404 today — unreachable by design, not by omission.
 *
 * THE BROWSER NEVER NAMES A STRIPE ACCOUNT. No action on this controller
 * accepts a connected-account id, or any provider identifier, in any form:
 * the account is always read from the Business's own row. A "return" from
 * Stripe is treated as a hint to re-sync, never as truth (§11.6: no browser
 * redirect is ever payment truth).
 *
 * NO MONEY MOVES HERE. There is no PaymentIntent, no charge, no refund and no
 * webhook in this sub-slice — those are E and F.
 */
class BusinessPaymentsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly StripeConnectManager $connect,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    public function show(string $workspaceUid, string $businessUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $connection = $this->connect->liveConnection($business);

        return view('customer.business.payments.connect', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'connection' => $connection,
            'history' => $this->connect->history($business),
            'chargeReady' => $this->connect->isChargeReady($business),
            'isOwner' => $this->actorIsOwner($business),
        ]);
    }

    /**
     * §12.D — create the connected account and send the owner to Stripe's own
     * hosted onboarding.
     */
    public function connect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);

        try {
            $url = $this->connect->connect(
                (int) Auth::id(),
                $business,
                $this->refreshUrl($workspaceUid, $businessUid),
                $this->returnUrl($workspaceUid, $businessUid),
            );
        } catch (StripeConnectException $e) {
            return $this->refuse($workspaceUid, $businessUid, $e);
        }

        return redirect()->away($url);
    }

    /** Server-side session key prefix for one pending OAuth attempt. */
    private const OAUTH_SESSION_PREFIX = 'business_stripe_oauth_pending.';

    /** How long a started-but-not-completed OAuth attempt stays valid. */
    private const OAUTH_STATE_TTL_MINUTES = 10;

    /**
     * "Connect existing Stripe account" — starts Stripe's Standard-account
     * OAuth. No account id is accepted from the browser. A random single-use
     * `state` is bound, server-side in this authenticated session, to the
     * initiating user, Workspace, Business and test/live mode, and expires.
     */
    public function connectExisting(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $state = bin2hex(random_bytes(20));

        try {
            $live = $this->connect->platformIsLive();

            session()->put(self::OAUTH_SESSION_PREFIX . $state, [
                'user_id' => (int) Auth::id(),
                'workspace_uid' => $workspaceUid,
                'business_uid' => $businessUid,
                'business_id' => (int) $business->id,
                'live' => $live,
                'expires_at' => now()->addMinutes(self::OAUTH_STATE_TTL_MINUTES)->timestamp,
            ]);

            $url = $this->connect->oauthAuthorizeUrl(
                (int) Auth::id(),
                $business,
                $state,
                route('customer.business.stripe.connect-existing.callback'),
            );
        } catch (StripeConnectException $e) {
            session()->forget(self::OAUTH_SESSION_PREFIX . $state);

            return $this->refuse($workspaceUid, $businessUid, $e);
        }

        return redirect()->away($url);
    }

    /**
     * Stripe's redirect back, at ONE fixed tenant-free URL. Workspace, Business,
     * user and mode come only from the server-side record stored under `state`,
     * which is consumed FIRST and unconditionally, so a replay finds nothing.
     * Rejections before the record is verified go to the customer home — an
     * unverified request never learns which Business it was for.
     */
    public function existingCallback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $key = self::OAUTH_SESSION_PREFIX . $state;
        $pending = $state !== '' ? session($key) : null;
        session()->forget($key);

        if (
            ! is_array($pending)
            || ! isset($pending['user_id'], $pending['workspace_uid'], $pending['business_uid'], $pending['business_id'], $pending['expires_at'])
            || (int) $pending['expires_at'] < now()->timestamp
            || (int) $pending['user_id'] !== (int) Auth::id()
        ) {
            return redirect()->route('user.home')->with([
                'status' => 'error',
                'message' => StripeConnectException::oauthStateInvalid()->customerMessage(),
            ]);
        }

        // Re-run the full gate chain on the RESOLVED Business: eligibility may
        // have changed since the attempt started.
        $business = $this->business((string) $pending['workspace_uid'], (string) $pending['business_uid']);
        abort_unless((int) $business->id === (int) $pending['business_id'], 404);

        $workspaceUid = (string) $pending['workspace_uid'];
        $businessUid = (string) $pending['business_uid'];

        if ($request->query('error') !== null) {
            return $this->back($workspaceUid, $businessUid)
                ->with(['status' => 'error', 'message' => 'Stripe account connection was not completed.']);
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->refuse($workspaceUid, $businessUid, StripeConnectException::oauthRejected());
        }

        try {
            $this->connect->connectExisting((int) Auth::id(), $business, $code, (bool) ($pending['live'] ?? false));
        } catch (StripeConnectException $e) {
            return $this->refuse($workspaceUid, $businessUid, $e);
        }

        return $this->back($workspaceUid, $businessUid)
            ->with(['status' => 'success', 'message' => 'Stripe account connected.']);
    }

    /**
     * §12.D — Stripe account links are single-use, so resuming an unfinished
     * onboarding mints a fresh one. This is also where Stripe's own
     * `refresh_url` lands when a link expires.
     */
    public function resume(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);

        try {
            $url = $this->connect->resumeOnboarding(
                (int) Auth::id(),
                $business,
                $this->refreshUrl($workspaceUid, $businessUid),
                $this->returnUrl($workspaceUid, $businessUid),
            );
        } catch (StripeConnectException $e) {
            return $this->refuse($workspaceUid, $businessUid, $e);
        }

        return redirect()->away($url);
    }

    /**
     * §11.4 — re-read capability truth from the provider. This is the ONLY
     * thing a return from Stripe does: the redirect itself proves nothing, so
     * the state is always re-derived from the provider rather than from the
     * fact that a browser came back.
     */
    public function refresh(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);

        try {
            $this->connect->syncFromProvider((int) Auth::id(), $business);
        } catch (StripeConnectException $e) {
            return $this->refuse($workspaceUid, $businessUid, $e);
        }

        return $this->back($workspaceUid, $businessUid)
            ->with(['status' => 'success', 'message' => 'Stripe connection updated.']);
    }

    /**
     * §5.7 — terminate the current connection. The row becomes terminal and
     * stays; nothing is rewritten or deleted.
     */
    public function disconnect(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);

        try {
            $disconnected = $this->connect->disconnect((int) Auth::id(), $business);
        } catch (StripeConnectException $e) {
            return $this->refuse($workspaceUid, $businessUid, $e);
        }

        $message = 'Stripe account disconnected. Your Stripe account itself was not changed.';

        if ($disconnected->getAttribute('oauth_revocation') === 'failed') {
            $message .= ' We could not revoke our access at Stripe; you can remove it under Settings > Connected platforms in your Stripe Dashboard.';
        }

        return $this->back($workspaceUid, $businessUid)
            ->with(['status' => 'success', 'message' => $message]);
    }

    /**
     * An owner-only refusal is a 404, exactly like every other authorization
     * failure on this chain: a non-owner must not be able to tell "you are
     * not the owner" from "this Business does not exist". Every other refusal
     * is calm copy back on the page.
     */
    private function refuse(string $workspaceUid, string $businessUid, StripeConnectException $e): RedirectResponse
    {
        if ($e->reason === StripeConnectException::NOT_OWNER) {
            abort(404);
        }

        return $this->back($workspaceUid, $businessUid)
            ->with(['status' => 'error', 'message' => $e->customerMessage()]);
    }

    private function back(string $workspaceUid, string $businessUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.payments.connect.show', [$workspaceUid, $businessUid]);
    }

    private function returnUrl(string $workspaceUid, string $businessUid): string
    {
        return route('customer.workspaces.businesses.payments.connect.show', [$workspaceUid, $businessUid]);
    }

    private function refreshUrl(string $workspaceUid, string $businessUid): string
    {
        return route('customer.workspaces.businesses.payments.connect.resume', [$workspaceUid, $businessUid]);
    }

    /**
     * The §6.1/§6.4 chain, identical to DocumentsController's. Its own method
     * so there is exactly one place deciding "is lane B reachable for this
     * Business", and so a test can replace only the entitlement step.
     */
    private function business(string $workspaceUid, string $businessUid): Business
    {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorize('payments_contracts');
        abort_unless($this->entitlementAllows($workspace, $business), 404);

        return $business;
    }

    protected function entitlementAllows(Workspace $workspace, Business $business): bool
    {
        return $this->entitlements
            ->decide($workspace, $business, PlatformFeature::PaymentsContracts->value, (int) Auth::id())
            ->allowed;
    }

    private function actorIsOwner(Business $business): bool
    {
        $actorUserId = (int) Auth::id();

        if ((int) $business->customer_id === $actorUserId) {
            return true;
        }

        $workspace = $business->workspace_id === null ? null : Workspace::query()->find($business->workspace_id);

        return $workspace !== null && (int) $workspace->owner_user_id === $actorUserId;
    }
}
