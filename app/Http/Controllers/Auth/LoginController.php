<?php

    namespace App\Http\Controllers\Auth;

    use App\Exceptions\GeneralException;
    use App\Helpers\Helper;
    use App\Http\Controllers\Controller;
    use App\Models\User;
    use App\Notifications\LoginConfirmation;
    use App\Repositories\Contracts\AccountRepository;
    use Carbon\Carbon;
    use Exception;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Foundation\Auth\AuthenticatesUsers;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\Response;
    use Illuminate\Routing\Redirector;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Session;
    use Illuminate\Support\Facades\Validator;
    use Illuminate\Support\Str;
    use Intervention\Image\Exception\NotReadableException;
    use Intervention\Image\Facades\Image;
    use Laravel\Socialite\Facades\Socialite;
    use Psr\Container\ContainerExceptionInterface;
    use Psr\Container\NotFoundExceptionInterface;
    use Symfony\Component\HttpFoundation\BinaryFileResponse;
    use function in_array;

    class LoginController extends Controller
    {
        /*
        |--------------------------------------------------------------------------
        | Login Controller
        |--------------------------------------------------------------------------
        |
        | This controller handles authenticating users for the application and
        | redirecting them to your home screen. The controller uses a trait
        | to conveniently provide its functionality to your applications.
        |
        */

        use AuthenticatesUsers;


        protected array $supportedProviders = [
            'facebook',
            'google',
            'github',
            'twitter',
        ];

        /**
         * @var AccountRepository
         */
        protected AccountRepository $account;

        /**
         * Create a new controller instance.
         *
         * @param AccountRepository $account
         */
        public function __construct(AccountRepository $account)
        {
            $this->middleware('guest')->except('logout', 'avatar', 'downloadSampleFile');
            $this->account = $account;
        }

        // Login
        public function showLoginForm(): View|Factory|Redirector|Application|RedirectResponse
        {

            if (auth()->check()) {
                return redirect(Helper::home_route());
            }

            $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
            ];

            return view('/auth/login', [
                'pageConfigs' => $pageConfigs,
            ]);
        }


        /**
         * @param Request $request
         *
         * @return RedirectResponse
         */
        public function login(Request $request): RedirectResponse
        {
            $rules = [
                'email'       => 'required|string|email|min:3',
                'password'    => 'required|string|min:3|max:50',
                'remember_me' => 'boolean',
            ];

            if (config('no-captcha.login')) {
                $rules['g-recaptcha-response'] = 'required|recaptchav3:login,0.5';
            }

            $messages = [
                'g-recaptcha-response.required'    => __('locale.auth.recaptcha_required'),
                'g-recaptcha-response.recaptchav3' => __('locale.auth.recaptcha_required'),
            ];

            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return redirect()->back()->withInput($request->only('email'))->with([
                    'status'  => 'warning',
                    'message' => $validator->errors()->first(),
                ]);
            }

            try {

                $credentials = request(['email', 'password', 'status' => 1]);

                if ( ! Auth::attempt($credentials, $request->input('remember'))) {
                    return redirect()->back()->withInput($request->only('email'))->with([
                        'status'  => 'error',
                        'message' => __('locale.auth.failed'),
                    ]);
                }

                // A fresh credential login must always face its own 2FA challenge: never inherit the
                // "already passed 2FA" flag of whichever account was signed in on this session before.
                $request->session()->forget('two-factor-login-success');

                if ( ! Auth::user()->status) {

                    Auth::logout();

                    return redirect()->back()->withInput($request->only('email'))->with([
                        'status'  => 'error',
                        'message' => __('locale.auth.disabled'),
                    ]);
                }


                $user = Auth::user();

                $this->account->redirectAfterLogin($user);

                if (config('app.stage' != 'demo')) {
                    if (Helper::app_config('login_notification_email') && $user->is_admin) {
                        $user->notify(new LoginConfirmation());
                    }

                    if ($user->is_customer && $user->customer->getNotifications()['login'] == 'yes') {
                        $user->notify(new LoginConfirmation());
                    }
                }

                $request->session()->put('password_changed_at', Auth::user()->password_changed_at?->timestamp);

                // A clicked (already signature-checked) email-verification link is completed first;
                // otherwise someone who opened a client invitation or a private-offer link goes back to it.
                $destination = redirect(\App\Library\PlatformBilling\PendingVerification::uri() ?? \App\Library\Workspace\ClientInvitationReturn::pull() ?? \App\Library\PlatformBilling\Offers\OfferReturn::url() ?? Helper::home_route());

                // Customers get no unsolicited welcome-back popup; only the admin portal keeps its greeting.
                if (\App\Library\Navigation\CustomerShellComposer::isCustomerPortal($user)) {
                    return $destination;
                }

                return $destination->with([
                    'status'  => 'success',
                    'message' => __('locale.auth.welcome_come_back', ['name' => $user->displayName()]),
                ]);

            } catch (Exception $exception) {
                return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }

        }


        /**
         * get customer avatar
         *
         * @param User $user
         *
         * @return mixed
         */
        public function avatar(User $user): mixed
        {

            if ( ! empty($user->imagePath())) {

                try {
                    $image = Image::make($user->imagePath());
                } catch (NotReadableException) {
                    $user->image = null;
                    $user->save();

                    $image = Image::make(public_path('images/profile/profile.jpg'));
                }
            } else {
                $image = Image::make(public_path('images/profile/profile.jpg'));
            }

            return $image->response();
        }

        /**
         * Log the user out of the application.
         *
         * @param Request $request
         *
         * @return Application|RedirectResponse|Response|Redirector
         * @throws ContainerExceptionInterface
         * @throws NotFoundExceptionInterface
         */
        public function logout(Request $request): Response|Redirector|Application|RedirectResponse
        {

            if ($admin_id = Session::get('admin_user_id')) {
                // Impersonate mode, back to original User
                session()->forget('admin_user_id');
                session()->forget('admin_user_name');
                session()->forget('temp_user_id');
                session()->forget('permissions');

                auth()->loginUsingId((int) $admin_id);

                session(['permissions' => auth()->user()->getPermissions()]);

                return redirect()->to(\App\Helpers\Helper::adminHomeUrl());
            }

            if ($parent_id = Session::get('parent_user_id')) {
                // Impersonate mode, back to original User
                session()->forget('parent_user_id');
                session()->forget('parent_user_name');
                session()->forget('temp_user_id');
                session()->forget('permissions');

                auth()->loginUsingId((int) $parent_id);


                $permissions = collect(json_decode(auth()->user()->customer->permissions, true));
                session(['permissions' => $permissions]);

                return redirect()->route('user.home');
            }

            $this->guard()->logout();

            $request->session()->invalidate();

            if ($this->loggedOut($request)) {
                return $this->loggedOut($request)->with([
                    'status'  => 'success',
                    'message' => 'Logout was successfully done',
                ]);
            } else {
                return redirect('/login');
            }
        }


        /**
         * Get the throttle key for the given request.
         *
         * @param Request $request
         *
         * @return string
         */
        protected function throttleKey(Request $request): string
        {
            return Str::lower($request->ip());
        }


        /** Only the four supported drivers, and only when the operator configured their credentials. */
        private function socialProviderAvailable(string $provider): bool
        {
            return in_array($provider, $this->supportedProviders, true) && filled(config('services.' . $provider . '.client_id'));
        }

        /**
         * redirect socialite
         *
         * @param $provider
         *
         * @return \Symfony\Component\HttpFoundation\RedirectResponse]
         */

        public function redirectToProvider($provider): \Symfony\Component\HttpFoundation\RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! $this->socialProviderAvailable($provider)) {
                return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => __('locale.auth.socialite.unacceptable', ['provider' => $provider]),
                ]);
            }

            // A plain "sign in" start: forget any signup intent left by an earlier, abandoned attempt.
            session()->forget('social_intent');

            return Socialite::driver($provider)->redirect();
        }

        /**
         * handle socialite provider callback data
         *
         * @param $provider
         *
         * @return RedirectResponse
         */
        public function handleProviderCallback($provider): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            if ( ! $this->socialProviderAvailable($provider)) {
                return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => __('locale.auth.socialite.unacceptable', ['provider' => $provider]),
                ]);
            }

            // Set by V1SignupController::googleRedirect() only: this OAuth round trip began from the signup flow.
            $intent = (string) session()->pull('social_intent', '');
            $fromSignup = $intent === 'signup';

            try {
                $data = Socialite::driver($provider)->user();
            } catch (\Throwable $e) {
                // invalid/expired OAuth state, denied consent, provider outage: never a server error, never a login or an account
                return redirect()->route($fromSignup ? 'register.account' : 'login')->with([
                    'status'  => 'error',
                    'message' => __('locale.auth.failed'),
                ]);
            }

            try {
                $resolved = $this->account->resolveSocialIdentity($provider, $data);
            } catch (GeneralException $e) {
                return redirect()->route($fromSignup ? 'register.account' : 'login')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }

            // A brand-new, provider-verified identity: no User is created here. It is carried into the canonical V1 signup
            // (plan -> business -> checkout), which creates the account once at commit, with no password to invent.
            if ($resolved['user'] === null) {
                return $this->continueSignupWithIdentity($resolved['identity'], $fromSignup);
            }

            try {
                $user = $resolved['user'];

                if ( ! $user->status) {
                    return redirect()->route('login')->with([
                        'status'  => 'error',
                        'message' => __('locale.auth.disabled'),
                    ]);
                }

                // Existing 2FA, disabled-account and admin protections stay in force (redirectAfterLogin issues the 2FA challenge).
                $this->account->redirectAfterLogin($user);

                auth()->login($user, true);
                request()->session()->regenerate();   // no session fixation across the OAuth round trip

                if ($user->active_portal == 'customer') {
                    // An account that exists but never finished signup resumes the legitimate plan / checkout process.
                    return $this->signupIsIncomplete($user) ? redirect()->route('signup.plan') : redirect()->route('user.home');
                }

                return redirect()->to(\App\Helpers\Helper::adminHomeUrl())->with([
                    'status'  => 'success',
                    'message' => __('locale.auth.welcome_come_back', ['name' => $user->displayName()]),
                ]);

            } catch (GeneralException $e) {
                return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /** A customer whose signup never produced a Workspace with a plan. */
        private function signupIsIncomplete(User $user): bool
        {
            if ($user->is_admin || ! $user->customer) {
                return false;
            }

            $workspaceId = \App\Models\Workspace::query()->where('owner_user_id', $user->id)->orderBy('id')->value('id');

            return $workspaceId === null || app(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspaceId) === null;
        }

        /**
         * Carry a provider-verified identity into the canonical V1 signup draft. The draft holds it in the session until checkout, so
         * nothing durable exists until the customer commits; a closed registration setting is honoured here too.
         *
         * @param  array<string, mixed>  $identity
         */
        private function continueSignupWithIdentity(array $identity, bool $fromSignup): RedirectResponse
        {
            if ( ! config('account.can_register')) {
                return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.registration_disabled'),
                ]);
            }

            // Signup is offered with Google only; another provider's new identity never becomes a signup account.
            if (($identity['provider'] ?? null) !== 'google') {
                return redirect()->route('login')->with(['status' => 'error', 'message' => __('locale.auth.failed')]);
            }

            $draft = new \App\Library\PlatformBilling\V1SignupDraft(request()->session());
            $draft->setProviderAccount($identity);
            request()->session()->regenerate();   // keeps the draft, rotates the session id

            // The plan the customer already chose is kept; otherwise they choose one first.
            return $draft->tier() !== null
                ? redirect()->route('register.business')
                : redirect()->route('register.plan')->with(['status' => 'success', 'message' => __('Google confirmed your email. Choose a plan to finish creating your account.')]);
        }

        /**
         * download application all import sample file
         *
         * @return BinaryFileResponse
         */
        public function downloadSampleFile(): BinaryFileResponse
        {
            return response()->download(storage_path('app/import_file_demo.csv'));
        }

        /*
         * test or debug or var_dump function
         */
        public function debug(): bool|string|RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $backUpCode = [];
            for ($i = 0; $i < 8; $i++) {
                $backUpCode[] = rand(100000, 999999);
            }

            return json_encode($backUpCode);

        }

    }
