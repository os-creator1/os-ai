<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Http\Controllers\Controller;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Library\PlatformBilling\V1SignupManager;
use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\UserRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Implementation Contract 21 §7 — THE canonical V1 signup.
 *
 * Blueprint §6's flow, for real:
 *
 *   name / email / password → business name → niche → basic info →
 *   Core / Growth / Agency → Stripe Checkout (payment method + trial start) →
 *   Workspace + Business + one Primary Location → plan assignment → Home
 *
 * THIS REPLACES THE LEGACY REGISTER PATH. `Auth\RegisterController` selects a
 * legacy `Plan` row and then one of eight inherited payment gateways
 * (Braintree, Authorize.Net, Cash, NowPayments, EasyPay, FedaPay, Vodacom
 * M-Pesa, legacy Stripe). None of that is the V1 product, and none of it
 * produces the canonical V1 plan authority — it produces a legacy
 * `Subscription` plus a FABRICATED complimentary Core assignment (§3.4). The
 * `register` route name now points here; the legacy controller stays on disk
 * for inherited installs but is no longer reachable as the customer signup.
 *
 * SIGNUP REQUIRES NO A2P, NO GOOGLE, NO CALENDAR, NO BUSINESS STRIPE CONNECT
 * AND NO TELNYX CONFIGURATION (§7). Those are post-signup checklist items.
 *
 * STRIPE ONLY, LANE A ONLY. There is deliberately no payment-method dropdown:
 * the only thing this controller can do with money is open a hosted lane-A
 * Checkout Session.
 *
 * THE BROWSER IS NEVER PAYMENT TRUTH (§8.5). `success()` ignores any query
 * flag and re-reads the Checkout Session from the provider, then runs the SAME
 * idempotent activation seam the webhook runs — so a customer who closes the
 * tab still gets a finished account, and one who returns twice still gets
 * exactly one.
 */
class V1SignupController extends Controller
{
    public function __construct(
        private readonly PlatformPlanPresenter $plans,
        private readonly V1SignupManager $signup,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * §7 — the signup page. Plans come from the V1 catalog, filtered to what
     * is genuinely sellable right now.
     */
    public function show(): View
    {
        return view('auth.v1-signup', [
            'plans' => $this->plans->sellablePlans(),
            'industries' => BusinessIndustry::cases(),
        ]);
    }

    /**
     * §7 — create the account, provision Workspace + Business + one Primary
     * Location, and open Checkout.
     *
     * NOTHING IS MARKED PAID HERE. The durable rows exist so a webhook can
     * always recover the signup, and the plan assignment waits for provider
     * confirmation.
     */
    public function store(Request $request): RedirectResponse
    {
        $plans = collect($this->plans->sellablePlans());

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'business_name' => ['required', 'string', 'max:191'],
            // The niche. It survives into the Business row and is what the
            // Blueprint installer resolves against.
            'industry' => ['required', 'string', 'in:' . implode(',', array_column(BusinessIndustry::cases(), 'value'))],
            'country_code' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'timezone'],
            // Only a tier the catalog says is sellable may be submitted.
            'tier' => ['required', 'string', 'in:' . $plans->pluck('tier_value')->implode(',')],
        ]);

        if ($validator->fails()) {
            return redirect()->route('register')->withInput($request->except('password', 'password_confirmation'))
                ->withErrors($validator->errors());
        }

        $data = $validator->validated();
        $catalog = WorkspacePlanCatalog::query()->where('tier', $data['tier'])->firstOrFail();

        // THE EXISTING USER+CUSTOMER PRIMITIVE, and deliberately not
        // AccountRepository::register().
        //
        // `store(..., $confirmed: true)` is the same call register() makes: it
        // hashes the password with Hash::make (no plaintext is ever persisted),
        // enforces `unique:users.email`, creates the Customer row with the
        // standard permissions and notification preferences, and issues the
        // API token.
        //
        // What it does NOT do is register()'s two legacy side effects: a
        // notification hard-coded to `user_id => 1`, which throws a foreign-key
        // violation on any install where the platform admin is not literally
        // user 1 and would take the whole signup down with it, and an implicit
        // login we would rather perform explicitly. Neither is part of V1
        // signup, and neither creates a legacy Subscription.
        $user = $this->users->store([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? '',
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => true,
            'phone' => null,
            'is_customer' => true,
        ], true);

        $customer = Customer::query()->where('user_id', $user->id)->firstOrFail();

        Auth::login($user, true);

        try {
            $session = $this->signup->startSubscription(
                $customer,
                [
                    'business_name' => $data['business_name'],
                    'industry' => $data['industry'],
                    'country_code' => $data['country_code'],
                    'timezone' => $data['timezone'],
                ],
                $catalog,
                route('signup.success'),
                route('signup.cancelled'),
            );
        } catch (PlatformBillingException $e) {
            return redirect()->route('signup.plan')->with([
                'status' => 'error',
                'message' => $e->customerMessage(),
            ]);
        }

        return redirect()->away((string) $session->url);
    }

    /**
     * §8.5 — the Checkout return. It does NOT trust a `?success=true` flag; it
     * resolves the session from the provider and runs the shared activation
     * seam.
     */
    public function success(): RedirectResponse
    {
        $subscription = $this->pendingSubscriptionForActor();

        if ($subscription === null) {
            return redirect()->route('signup.plan');
        }

        try {
            $this->signup->completeSignup((string) $subscription->provider_checkout_session_id);
        } catch (PlatformBillingException $e) {
            return redirect()->route('signup.plan')->with([
                'status' => 'error',
                'message' => $e->customerMessage(),
            ]);
        }

        // Home — `user.home`, the existing post-login destination. The customer
        // is already authenticated from registration, so this is a redirect,
        // never a second sign-in.
        return redirect()->route('user.home')->with([
            'status' => 'success',
            'message' => __('Your account is ready.'),
        ]);
    }

    /**
     * §7 — the customer backed out of Checkout. The account is NOT deleted: it
     * is a truthful, recoverable, unassigned state, and restarting checkout
     * must not create a second Workspace.
     */
    public function cancelled(): RedirectResponse
    {
        return redirect()->route('signup.plan')->with([
            'status' => 'info',
            'message' => __('No payment was taken. Choose a plan whenever you are ready.'),
        ]);
    }

    /** The resumable plan-selection screen for an account that exists but has no plan. */
    public function plan(): View|RedirectResponse
    {
        $customer = $this->actingCustomer();

        if ($customer === null) {
            return redirect()->route('register');
        }

        $subscription = $this->pendingSubscriptionForActor();

        return view('auth.v1-signup-plan', [
            'plans' => $this->plans->sellablePlans(),
            'businessName' => $subscription === null ? null : optional(
                Workspace::query()->find($subscription->workspace_id)
            )->name,
        ]);
    }

    /**
     * §7 — restart Checkout for an account that already exists. Provisioning is
     * re-entrant, so this creates no second Workspace, Business or Location.
     */
    public function resume(Request $request): RedirectResponse
    {
        $customer = $this->actingCustomer();

        if ($customer === null) {
            return redirect()->route('register');
        }

        $plans = collect($this->plans->sellablePlans());

        $validator = Validator::make($request->all(), [
            'tier' => ['required', 'string', 'in:' . $plans->pluck('tier_value')->implode(',')],
        ]);

        if ($validator->fails()) {
            return redirect()->route('signup.plan')->withErrors($validator->errors());
        }

        $workspace = Workspace::query()->where('owner_user_id', $customer->user_id)->orderBy('id')->first();
        $catalog = WorkspacePlanCatalog::query()->where('tier', $validator->validated()['tier'])->firstOrFail();

        try {
            $session = $this->signup->startSubscription(
                $customer,
                [
                    'business_name' => (string) ($workspace?->name ?? $customer->user->displayName()),
                    'industry' => BusinessIndustry::Other->value,
                    'country_code' => 'US',
                    'timezone' => config('app.timezone'),
                ],
                $catalog,
                route('signup.success'),
                route('signup.cancelled'),
            );
        } catch (PlatformBillingException $e) {
            return redirect()->route('signup.plan')->with([
                'status' => 'error',
                'message' => $e->customerMessage(),
            ]);
        }

        return redirect()->away((string) $session->url);
    }

    private function actingCustomer(): ?Customer
    {
        $userId = Auth::id();

        return $userId === null ? null : Customer::query()->where('user_id', $userId)->first();
    }

    /**
     * The signed-in actor's own pending lane-A subscription. Resolved from the
     * actor's Workspace, never from a request parameter, so a session cannot be
     * pointed at somebody else's checkout.
     */
    private function pendingSubscriptionForActor(): ?PlatformSubscription
    {
        $customer = $this->actingCustomer();

        if ($customer === null) {
            return null;
        }

        $workspaceIds = Workspace::query()->where('owner_user_id', $customer->user_id)->pluck('id');

        return PlatformSubscription::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->whereNotNull('provider_checkout_session_id')
            ->orderByDesc('id')
            ->first();
    }
}
