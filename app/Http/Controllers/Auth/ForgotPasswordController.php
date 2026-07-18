<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;


class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

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
     * @return Application|Factory|View
     */
    public function showLinkRequestForm(): View|Factory|Application
    {
        $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
        ];

        return view('/auth/passwords/email', [
                'pageConfigs' => $pageConfigs,
        ]);
    }


    public function sendResetLinkEmail(Request $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->route('password.request')->withInput($request->only('email'))->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $rules = [
                'email' => 'required|email|exists:users',
        ];

        $messages = [
                'email.exists' => __('locale.auth.user_not_exist'),
        ];

        if (config('no-captcha.login')) {
            $rules['g-recaptcha-response']             = 'required|recaptchav3:email,0.5';
            $messages['g-recaptcha-response.required'] = __('locale.auth.recaptcha_required');
            $messages['g-recaptcha-response.recaptchav3']  = __('locale.auth.recaptcha_required');
        }

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return redirect()->route('password.request')->withInput($request->only('email'))->with([
                    'status'  => 'warning',
                    'message' => $validator->errors()->first(),
            ]);
        }

        $status = Password::sendResetLink(
                $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
                ? back()->with([
                        'status'  => 'success',
                        'message' => __('locale.auth.reset_link_sent'),
                ])
                : back()->with([
                        'status'  => 'error',
                        'message' => __($status),
                ]);
    }

}
