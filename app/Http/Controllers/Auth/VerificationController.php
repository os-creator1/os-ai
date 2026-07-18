<?php

    namespace App\Http\Controllers\Auth;

    use App\Exceptions\GeneralException;
    use App\Helpers\Helper;
    use App\Http\Controllers\Controller;
    use App\Models\User;
    use App\Notifications\WelcomeEmailNotification;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Foundation\Auth\EmailVerificationRequest;
    use Illuminate\Foundation\Auth\VerifiesEmails;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Routing\Redirector;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Validation\Rules\Password;
    use function request;

    class VerificationController extends Controller
    {
        /*
        |--------------------------------------------------------------------------
        | Email Verification Controller
        |--------------------------------------------------------------------------
        |
        | This controller is responsible for handling email verification for any
        | user that recently registered with the application. Emails may also
        | be re-sent if the user didn't receive the original email message.
        |
        */

        use VerifiesEmails;

        /**
         * Where to redirect users after verification.
         *
         * @var string
         */
        protected string $redirectTo = '/login';


        /**
         * The user has been registered.
         *
         * @param EmailVerificationRequest $request
         *
         * @return Application|Redirector|RedirectResponse
         */

        public function verificationVerify(EmailVerificationRequest $request): Redirector|RedirectResponse|Application
        {
            $request->fulfill();

            if (Helper::app_config('user_registration_notification_email')) {
                $user = Auth::user();
                $user->notify(new WelcomeEmailNotification($user->first_name, $user->last_name, $user->email, route('login'), ''));
            }

            return redirect(Helper::home_route());
        }

        /**
         * verify email
         *
         * @return Application|Factory|View
         * @throws GeneralException
         */
        public function verificationNotice(): View|Factory|Application
        {
            // Registration is not enabled
            if ( ! config('account.verify_account')) {
                throw new GeneralException(__('locale.exceptions.user_verification'));
            }

            $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
            ];

            return view('auth.verify')->with(
                ['pageConfigs' => $pageConfigs]
            );
        }

        /**
         * Resend verification email
         *
         *
         * @return RedirectResponse
         */

        public function verificationSend(): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            request()->user()->sendEmailVerificationNotification();

            return back()->with('status', 'resent');
        }

        public function subAccountAccept(string $token)
        {
            // Registration is not enabled
            if ( ! config('account.create_subaccount')) {
                return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sub account is not enabled',
                ]);
            }

            $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
            ];

            return view('auth.subAccount.acceptInvitation')->with(
                [
                    'pageConfigs' => $pageConfigs,
                    'token'       => $token,
                ]
            );

        }

        public function subAccountAcceptSubmit(Request $request, string $token)
        {

            if (config('app.stage') == 'demo') {
                return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            $user = User::where('invitation_token', $token)->firstOrFail();

            $request->validate([
                'password' => ['required', 'string', 'confirmed', Password::default()],
            ]);

            $user->password          = Hash::make($request->password);
            $user->invitation_token  = null; // clear token after use
            $user->email_verified_at = now();
            $user->status            = true;
            $user->save();

            auth()->login($user); // optionally log them in

            return redirect()->route('user.home')->with([
                'status'  => 'success',
                'message' => __('locale.sub_accounts.sub_account_activated'),
            ]);
        }

    }
