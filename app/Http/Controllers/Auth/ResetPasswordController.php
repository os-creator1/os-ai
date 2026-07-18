<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    protected string $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * @param  Request  $request
     * @param $token
     *
     * @return Application|Factory|View
     */
    public function showResetForm(Request $request, $token = null): View|Factory|Application
    {
        $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
        ];

        return view('auth.passwords.reset')->with(
                ['token' => $token, 'email' => $request->input('email'), 'pageConfigs' => $pageConfigs]
        );
    }

    public function reset(Request $request): RedirectResponse
    {
        if (config('app.stage') == 'demo') {
            return redirect()->route('login')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $rules = [
                'token'    => 'required',
                'email'    => 'required|email',
                'password' => ['required', 'string', 'confirmed', \Illuminate\Validation\Rules\Password::default()],
        ];

        if (config('no-captcha.login')) {
            $rules['g-recaptcha-response'] = 'required|recaptchav3:reset,0.5';
        }

        $messages  = [
                'g-recaptcha-response.required'    => __('locale.auth.recaptcha_required'),
                'g-recaptcha-response.recaptchav3' => __('locale.auth.recaptcha_required'),
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return redirect()->route('password.reset', ['token' => $request->input('token')])->withInput($request->only('email'))->with([
                    'status'  => 'warning',
                    'message' => $validator->errors()->first(),
            ]);
        }



        $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) use ($request) {
                    $user->forceFill([
                            'password' => Hash::make($password),
                    ])->save();

                    $user->setRememberToken(Str::random(60));

                    event(new PasswordReset($user));
                }
        );

        return $status == Password::PASSWORD_RESET
                ? redirect()->route('login')->with([
                        'status'  => 'success',
                        'message' => __('locale.auth.password_reset_successfully'),
                ])
                : back()->with([
                        'status'  => 'error',
                        'message' => __($status),
                ]);
    }

}
