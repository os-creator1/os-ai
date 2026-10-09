<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Business\BusinessIndustry;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Http\Controllers\Controller;
use App\Library\Business\BusinessLocaleOptions;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Library\PlatformBilling\V1SignupDraft;
use App\Library\PlatformBilling\V1SignupManager;
use App\Models\Customer;
use App\Models\Language;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\UserRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Implementation Contract 21 §7 — THE canonical V1 signup.
 *
 * The locked flow (every screen is the Business OS auth shell, the same one
 * /login uses):
 *
 *   PLAN → ACCOUNT → BUSINESS → PAYMENT → PROVISIONING → EMAIL VERIFICATION
 *        → first-run onboarding → Home
 *
 * PLAN, ACCOUNT and BUSINESS only collect answers, into `V1SignupDraft` (the
 * session). Nothing durable exists until the customer commits at PAYMENT, so a
 * refresh, Back or abandoned tab can never leave a half-built tenant behind.
 * At the commit the existing durable machinery runs exactly as before: one
 * User, then V1SignupManager provisions one Workspace + one Business + one
 * Primary Location and opens a hosted lane-A Stripe Checkout Session.
 *
 * THIS REPLACES THE LEGACY REGISTER PATH. `Auth\RegisterController` selects a
 * legacy `Plan` row and then one of eight inherited payment gateways. None of
 * that is the V1 product; it stays on disk for inherited installs but is no
 * longer reachable as the customer signup.
 *
 * SIGNUP REQUIRES NO A2P, NO GOOGLE, NO CALENDAR, NO BUSINESS STRIPE CONNECT
 * AND NO TELNYX CONFIGURATION (§7). Those are post-signup checklist items.
 *
 * AGENCY. The Agency plan takes the same flow with one different question set:
 * the BUSINESS step asks for the agency's name, country and time zone and never
 * for a niche, and nothing about a client is fabricated. Provisioning is the
 * unchanged V1 Workspace provisioning — the Agency's own Workspace — and the
 * first client is added from inside the Agency product.
 *
 * THE BROWSER IS NEVER PAYMENT TRUTH (§8.5). `success()` ignores any query
 * flag and re-reads the Checkout Session from the provider, then runs the SAME
 * idempotent activation seam the webhook runs — so a customer who closes the
 * tab still gets a finished account, and one who returns twice still gets
 * exactly one.
 */
class V1SignupController extends Controller
{
    /** How many "still setting up" polls before the screen says it is slow. */
    private const PROVISIONING_PATIENCE = 15;

    public function __construct(
        private readonly PlatformPlanPresenter $plans,
        private readonly V1SignupManager $signup,
        private readonly UserRepository $users,
        private readonly BusinessLocaleOptions $localeOptions,
        private readonly WorkspacePlanAssignmentRepository $assignments,
    ) {
    }

    // =================================================================
    // Guest steps: PLAN → ACCOUNT → BUSINESS → PAYMENT
    // =================================================================

    /**
     * The entry point. A valid plan carried by the pricing link
     * (`/register?plan=growth`) is pinned and the plan step is skipped; a plan
     * already pinned in the draft resumes where the customer left off; anything
     * else begins with plan selection. A foreign, disabled or unavailable plan
     * never proceeds — it lands on plan selection with an explanation.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if ($this->alreadySignedIn()) {
            return $this->redirectSignedInActor();
        }

        if ($this->signupClosed()) {
            return $this->closedView();
        }

        $draft = $this->draft($request);
        $requested = $request->query('plan', $request->query('tier'));

        if ($requested !== null) {
            if (is_string($requested) && $this->sellablePlan($requested) !== null) {
                $draft->setTier($requested);

                return redirect()->route('register.account');
            }

            $draft->forgetTier();

            return $this->planView(errors: ['plan' => __('That plan is not available right now. Please choose one of the plans below.')]);
        }

        if ($draft->tier() !== null && $this->sellablePlan($draft->tier()) !== null) {
            return redirect()->to($this->furthestStepUrl($draft));
        }

        return $this->planView();
    }

    /** STEP 1 — always the plan screen (so "Change plan" works after a plan is pinned). */
    public function planStep(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guestGate()) {
            return $redirect;
        }

        return $this->planView(selected: $this->draft($request)->tier());
    }

    public function selectPlan(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guestGate()) {
            return $redirect;
        }

        $tier = $request->input('tier');

        if (! is_string($tier) || $this->sellablePlan($tier) === null) {
            return redirect()->route('register.plan')
                ->withErrors(['plan' => __('That plan is not available right now. Please choose one of the plans below.')]);
        }

        $this->draft($request)->setTier($tier);

        return redirect()->route('register.account');
    }

    /** STEP 2 — ACCOUNT. */
    public function accountStep(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guestGate()) {
            return $redirect;
        }

        $draft = $this->draft($request);

        if ($redirect = $this->requirePlan($draft)) {
            return $redirect;
        }

        return view('auth.signup.account', [
            'step' => 2,
            'plan' => $this->sellablePlan((string) $draft->tier()),
            'account' => $draft->account() ?? [],
            'languages' => $this->enabledLanguages(),
        ]);
    }

    public function storeAccount(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guestGate()) {
            return $redirect;
        }

        $draft = $this->draft($request);

        if ($redirect = $this->requirePlan($draft)) {
            return $redirect;
        }

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // The canonical application language (users.locale): only an enabled
            // Language may be chosen; omitted, the install default applies.
            'locale' => ['nullable', 'string', Rule::in($this->enabledLanguages()->pluck('code')->all())],
        ]);

        if ($validator->fails()) {
            return redirect()->route('register.account')
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors($validator->errors());
        }

        $data = $validator->validated();

        $draft->setAccount([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'],
            'password' => $data['password'],
            'locale' => $data['locale'] ?? null,
        ]);

        return redirect()->route('register.business');
    }

    /** STEP 3 — BUSINESS (or, on the Agency branch, the agency's own basics). */
    public function businessStep(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guestGate()) {
            return $redirect;
        }

        $draft = $this->draft($request);

        if ($redirect = $this->requirePlan($draft) ?? $this->requireAccount($draft)) {
            return $redirect;
        }

        $tier = (string) $draft->tier();

        return view('auth.signup.business', [
            'step' => 3,
            'plan' => $this->sellablePlan($tier),
            'isAgency' => $tier === 'agency',
            'business' => $draft->business() ?? [],
            'industries' => BusinessIndustry::cases(),
            'countries' => $this->localeOptions->countries(),
            'timezones' => $this->localeOptions->timezones(),
        ]);
    }

    public function storeBusiness(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guestGate()) {
            return $redirect;
        }

        $draft = $this->draft($request);

        if ($redirect = $this->requirePlan($draft) ?? $this->requireAccount($draft)) {
            return $redirect;
        }

        $isAgency = $draft->tier() === 'agency';

        $validator = Validator::make($request->all(), [
            'business_name' => ['required', 'string', 'max:191'],
            // The niche is what the Blueprint installer resolves against. An
            // Agency has no niche to declare: it is not a client Business.
            'industry' => $isAgency
                ? ['nullable']
                : ['required', 'string', Rule::in(array_column(BusinessIndustry::cases(), 'value'))],
            // The SAME canonical country/timezone lists the customer Business
            // form uses (BusinessLocaleOptions) — never a second list, and a
            // forged value is refused exactly like a choice that never existed.
            'country_code' => ['required', 'string', Rule::in(array_keys($this->localeOptions->countries()))],
            'timezone' => ['required', 'timezone'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('register.business')
                ->withInput()
                ->withErrors($validator->errors());
        }

        $data = $validator->validated();
        $currency = $this->defaultCurrencyFor($data['country_code']);

        if ($currency === null) {
            // No active currency at all: the Business's wallet could never
            // resolve one, so refuse here rather than strand the account.
            return redirect()->route('register.business')
                ->withInput()
                ->withErrors(['country_code' => __('Signup is temporarily unavailable for this country. Please try again shortly.')]);
        }

        $draft->setBusiness([
            'business_name' => $data['business_name'],
            'industry' => $isAgency ? BusinessIndustry::Other->value : $data['industry'],
            'country_code' => $data['country_code'],
            'timezone' => $data['timezone'],
            'currency_code' => $currency,
        ]);

        return redirect()->route('register.payment');
    }

    /** STEP 4 — plan confirmation; the one place the customer commits. */
    public function paymentStep(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->guestGate()) {
            return $redirect;
        }

        $draft = $this->draft($request);

        if ($redirect = $this->requirePlan($draft) ?? $this->requireAccount($draft) ?? $this->requireBusiness($draft)) {
            return $redirect;
        }

        return view('auth.signup.payment', [
            'step' => 4,
            'plan' => $this->sellablePlan((string) $draft->tier()),
            'account' => $draft->account(),
            'business' => $draft->business(),
            'isAgency' => $draft->tier() === 'agency',
        ]);
    }

    /**
     * §7 — the commit. Create the account, provision Workspace + Business +
     * one Primary Location, and open Checkout.
     *
     * NOTHING IS MARKED PAID HERE. The durable rows exist so a webhook can
     * always recover the signup, and the plan assignment waits for provider
     * confirmation.
     */
    public function startPayment(Request $request): View|RedirectResponse
    {
        // §7 — guest only. An authenticated actor must never be able to create
        // a second account here and be switched into it.
        if ($redirect = $this->guestGate()) {
            return $redirect;
        }

        $draft = $this->draft($request);

        if ($redirect = $this->requirePlan($draft) ?? $this->requireAccount($draft) ?? $this->requireBusiness($draft)) {
            return $redirect;
        }

        $account = (array) $draft->account();
        $business = (array) $draft->business();
        $password = $draft->password();

        if ($password === null) {
            return redirect()->route('register.account')
                ->withErrors(['password' => __('Please choose your password again to continue.')]);
        }

        // The email was unique when the account step accepted it; it may not
        // be now (another signup, a long-open tab). Refuse before any write.
        if (User::query()->where('email', $account['email'])->exists()) {
            return redirect()->route('register.account')
                ->withErrors(['email' => __('That email address is already registered. Sign in instead, or use a different email.')]);
        }

        $catalog = WorkspacePlanCatalog::query()->where('tier', (string) $draft->tier())->firstOrFail();

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
            'first_name' => $account['first_name'],
            'last_name' => $account['last_name'] ?? '',
            'email' => $account['email'],
            'password' => $password,
            'status' => true,
            'phone' => null,
            'is_customer' => true,
        ], true);

        // The chosen application language (users.locale, the one canonical
        // preference). UserRepository::store() always applies the install
        // default, so the choice is saved here.
        if (! empty($account['locale']) && $account['locale'] !== $user->locale) {
            $user->forceFill(['locale' => $account['locale']])->save();
        }

        $customer = Customer::query()->where('user_id', $user->id)->firstOrFail();

        // The account is durable from here on; the draft (and the password it
        // briefly held) is no longer needed, and a replayed submit now meets
        // the signed-in guard instead of creating a second account.
        $draft->forget();

        Auth::login($user, true);
        // Apply the chosen language to this very session (LocaleMiddleware reads it).
        session(['locale' => $user->locale]);
        $request->session()->regenerate();

        $this->sendVerificationEmail($user);

        try {
            $session = $this->signup->startSubscription(
                $customer,
                $business,
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

    // =================================================================
    // Authenticated re-entry: Checkout return, cancel, resume
    // =================================================================

    /**
     * §8.5 — the Checkout return. It does NOT trust a `?success=true` flag; it
     * resolves the session from the provider and runs the shared activation
     * seam. Until the provider confirms a paying subscription the customer sees
     * the calm "setting up" screen, which re-checks on its own.
     */
    public function success(Request $request): View|RedirectResponse
    {
        $customer = $this->actingCustomer();

        if ($customer !== null && $this->isCompleted($customer)) {
            return $this->handOff();
        }

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

        if ($customer !== null && $this->isCompleted($customer)) {
            $request->session()->forget('v1_signup_waits');

            return $this->handOff();
        }

        $waits = (int) $request->session()->get('v1_signup_waits', 0) + 1;
        $request->session()->put('v1_signup_waits', $waits);

        return view('auth.signup.provisioning', [
            'slow' => $waits > self::PROVISIONING_PATIENCE,
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

        if ($this->isCompleted($customer)) {
            return redirect()->route('user.home');
        }

        $subscription = $this->pendingSubscriptionForActor();
        $pinned = $subscription === null
            ? null
            : WorkspacePlanCatalog::query()->find($subscription->workspace_plan_catalog_id)?->tier?->value;

        return view('auth.v1-signup-plan', [
            'plans' => $this->plans->sellablePlans(),
            // The plan the customer already chose stays selected, so a
            // cancelled or failed payment resumes on the same plan.
            'pinnedTier' => $pinned,
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

        if ($this->isCompleted($customer)) {
            return redirect()->route('user.home');
        }

        $plans = collect($this->plans->sellablePlans());

        $validator = Validator::make($request->all(), [
            'tier' => ['required', 'string', 'in:' . $plans->pluck('tier_value')->implode(',')],
        ]);

        if ($validator->fails()) {
            return redirect()->route('signup.plan')->withErrors($validator->errors());
        }

        $catalog = WorkspacePlanCatalog::query()->where('tier', $validator->validated()['tier'])->firstOrFail();

        try {
            // §7 — RE-ENTRY, not provisioning. This deliberately does NOT call
            // startSubscription(): that path provisions, and provisioning
            // upserts the Primary Location, which would silently overwrite the
            // country, timezone and niche this account already chose with this
            // form's defaults. Resume changes the pending checkout and nothing
            // else.
            $session = $this->signup->restartCheckout(
                $customer,
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

    // =================================================================
    // Helpers
    // =================================================================

    /** @return \Illuminate\Support\Collection<int, Language> */
    private function enabledLanguages(): \Illuminate\Support\Collection
    {
        return Language::query()->where('status', 1)->orderBy('name')->get(['code', 'name']);
    }

    private function draft(Request $request): V1SignupDraft
    {
        return new V1SignupDraft($request->session());
    }

    /**
     * Registration is unavailable when the owner has closed it, or when there
     * is nothing sellable to offer. Both render the same branded screen.
     */
    private function signupClosed(): bool
    {
        return ! config('account.can_register') || count($this->plans->sellablePlans()) === 0;
    }

    private function closedView(): View
    {
        return view('auth.signup.closed');
    }

    /**
     * The guest boundary shared by every step, enforced here as well as by the
     * route's `guest` middleware.
     *
     * The inherited `RedirectIfAuthenticated` computes a home route and then
     * falls through to the next middleware anyway, so on this installation
     * `guest` does not actually stop an authenticated request. Without this
     * check, a signed-in customer could POST with a different email, create a
     * second User, and have Auth::login() silently switch them into it.
     * Fixing that shared middleware would change every `guest` route in the
     * application, so signup states its own boundary and sends the actor to
     * the path that actually serves them.
     */
    private function guestGate(): View|RedirectResponse|null
    {
        if ($this->alreadySignedIn()) {
            return $this->redirectSignedInActor();
        }

        return $this->signupClosed() ? $this->closedView() : null;
    }

    private function alreadySignedIn(): bool
    {
        return Auth::check();
    }

    private function redirectSignedInActor(): RedirectResponse
    {
        $customer = $this->actingCustomer();

        // Someone with a finished signup — or no customer record at all —
        // belongs at home; someone who has an account but no plan yet belongs
        // on the resumable plan screen. Never on a second guest signup.
        return $customer === null || $this->isCompleted($customer)
            ? redirect()->route('user.home')
            : redirect()->route('signup.plan');
    }

    /** @return array<string, mixed>|null */
    private function sellablePlan(string $tier): ?array
    {
        foreach ($this->plans->sellablePlans() as $plan) {
            if ($plan['tier_value'] === $tier) {
                return $plan;
            }
        }

        return null;
    }

    /** A pinned plan that is no longer sellable is dropped, not trusted. */
    private function requirePlan(V1SignupDraft $draft): ?RedirectResponse
    {
        $tier = $draft->tier();

        if ($tier !== null && $this->sellablePlan($tier) !== null) {
            return null;
        }

        if ($tier !== null) {
            $draft->forgetTier();

            return redirect()->route('register.plan')
                ->withErrors(['plan' => __('That plan is not available right now. Please choose one of the plans below.')]);
        }

        return redirect()->route('register.plan');
    }

    private function requireAccount(V1SignupDraft $draft): ?RedirectResponse
    {
        return $draft->account() === null || $draft->password() === null
            ? redirect()->route('register.account')
            : null;
    }

    private function requireBusiness(V1SignupDraft $draft): ?RedirectResponse
    {
        return $draft->business() === null ? redirect()->route('register.business') : null;
    }

    /** Where a returning visitor with a pinned plan should pick up. */
    private function furthestStepUrl(V1SignupDraft $draft): string
    {
        if ($draft->account() === null || $draft->password() === null) {
            return route('register.account');
        }

        return $draft->business() === null ? route('register.business') : route('register.payment');
    }

    /** @param  array<string, string>  $errors */
    private function planView(?string $selected = null, array $errors = []): View
    {
        $view = view('auth.signup.plan', [
            'step' => 1,
            'plans' => $this->plans->sellablePlans(),
            'selected' => $selected,
        ]);

        return $errors === [] ? $view : $view->withErrors($errors);
    }

    /**
     * One wallet currency per Business, resolved without asking the customer a
     * fifth question: the country's own tender when ICU knows exactly one,
     * otherwise USD, otherwise the first active currency. It is the SAME
     * canonical, active list UsageWalletManager can resolve.
     */
    private function defaultCurrencyFor(string $countryCode): ?string
    {
        $offered = $this->localeOptions->currencies();

        if ($offered === []) {
            return null;
        }

        $byCountry = $this->localeOptions->defaultCurrencyByCountry();

        if (isset($byCountry[$countryCode], $offered[$byCountry[$countryCode]])) {
            return $byCountry[$countryCode];
        }

        return isset($offered['USD']) ? 'USD' : (string) array_key_first($offered);
    }

    /**
     * The verification email goes out when the account is created, never
     * gating the purchase. A mail outage must not undo a signup: the customer
     * can resend it from the verification screen.
     */
    private function sendVerificationEmail(User $user): void
    {
        if (! config('account.verify_account') || $user->hasVerifiedEmail()) {
            return;
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * After provisioning: a customer who still has to verify their email is
     * shown that screen; everyone else goes Home, where the existing
     * first-run onboarding takes over.
     */
    private function handOff(): RedirectResponse
    {
        $user = Auth::user();

        if (config('account.verify_account') && $user !== null && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->route('user.home')->with([
            'status' => 'success',
            'message' => __('Your account is ready.'),
        ]);
    }

    /** Whether this customer's Workspace already holds a plan: a finished signup. */
    private function isCompleted(Customer $customer): bool
    {
        $workspaceId = Workspace::query()->where('owner_user_id', $customer->user_id)->orderBy('id')->value('id');

        return $workspaceId !== null && $this->assignments->findByWorkspaceId((int) $workspaceId) !== null;
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
