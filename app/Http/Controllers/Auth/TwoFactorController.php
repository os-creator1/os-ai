<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Notifications\TwoFactorCode;
use App\Repositories\Contracts\AccountRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Session;

class TwoFactorController extends Controller
{

    /**
     * @var AccountRepository
     */
    protected AccountRepository $account;

    /**
     * Create a new controller instance.
     *
     * @param  AccountRepository  $account
     */
    public function __construct(AccountRepository $account)
    {
        $this->middleware('guest');
        $this->account = $account;
    }


    /**
     * @return Application|Factory|View
     */
    public function index(): View|Factory|Application
    {
        $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
        ];

        return view('/auth/twoFactor', [
                'pageConfigs' => $pageConfigs,
        ]);
    }

    /**
     * @return Application|Factory|View
     */
    public function backUpCode(): View|Factory|Application
    {
        $pageConfigs = [
                'bodyClass' => "bg-full-screen-image",
                'blankPage' => true,
        ];

        return view('/auth/twoFactorBackUp', [
                'pageConfigs' => $pageConfigs,
        ]);
    }

    /**
     * verify two factor code
     *
     * @param  Request  $request
     *
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $request->validate([
                'two_factor_code' => 'integer|required|min:6',
        ]);

        $user = auth()->user();

        $expired = $user->two_factor_expires_at !== null && now()->greaterThan($user->two_factor_expires_at);

        if ( ! $expired && $user->two_factor_code !== null && $request->input('two_factor_code') == $user->two_factor_code) {

            $user->resetTwoFactorCode();

            Session::put('two-factor-login-success', 'success');

            $this->account->redirectAfterLogin($user);

            Session::reflash();

            return redirect(Helper::home_route());
        }

        Session::reflash();

        return redirect()->back()->with([
                'status'  => 'error',
                'message' => __('locale.auth.two_factor_code_not_matched'),
        ]);
    }


    /**
     * verify with backup code
     *
     * @param  Request  $request
     *
     * @return Application|RedirectResponse|Redirector
     */
    public function updateBackUpCode(Request $request): Redirector|RedirectResponse|Application
    {

        if (config('app.stage') == 'demo') {
            return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $request->validate([
                'two_factor_code' => 'integer|required|min:6',
        ]);

        $user = auth()->user();

        $backUpCode = json_decode($user->two_factor_backup_code, true);


        if (isset($backUpCode) && is_array($backUpCode) && in_array($request->input('two_factor_code'), $backUpCode)) {

            // A backup code is single-use: consume it so a leaked/used code cannot be replayed.
            $remaining = array_values(array_filter($backUpCode, fn ($c) => (string) $c !== (string) $request->input('two_factor_code')));
            $user->forceFill(['two_factor_backup_code' => json_encode($remaining)])->save();

            $user->resetTwoFactorCode();

            Session::flash('two-factor-login-success', 'success');

            $this->account->redirectAfterLogin($user);

            Session::reflash();

            return redirect(Helper::home_route());
        }

        return redirect()->back()->with([
                'status'  => 'error',
                'message' => __('locale.auth.two_factor_code_not_matched'),
        ]);
    }

    /**
     * resend two factor code
     *
     * @return RedirectResponse
     */

    public function resend(): RedirectResponse
    {

        if (config('app.stage') == 'demo') {
            return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
            ]);
        }

        $user = auth()->user();
        $user->generateTwoFactorCode();
        $user->notify(new TwoFactorCode());

        return redirect()->back()->with([
                'status'  => 'success',
                'message' => __('locale.auth.two_factor_code_sent'),
        ]);
    }


}
