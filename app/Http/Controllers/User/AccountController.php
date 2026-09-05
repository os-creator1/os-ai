<?php

    namespace App\Http\Controllers\User;

    use App\Exceptions\GeneralException;
    use App\Helpers\Helper;
    use App\Http\Controllers\Controller;
    use App\Http\Requests\Account\PayPayment;
    use App\Http\Requests\Account\TopUpUnitsRequest;
    use App\Http\Requests\Accounts\ChangePasswordRequest;
    use App\Http\Requests\Accounts\UpdateUserInformationRequest;
    use App\Http\Requests\Accounts\UpdateUserRequest;
    use App\Library\MPesa;
    use App\Library\MPGS;
    use App\Library\Tool;
    use App\Models\Announcements;
    use App\Models\AppConfig;
    use App\Models\Country;
    use App\Models\Customer;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\Invoices;
    use App\Models\Language;
    use App\Models\Notifications;
    use App\Models\PaymentMethods;
    use App\Models\Plan;
    use App\Models\PlansCoverageCountries;
    use App\Models\PlanSendingCreditPrice;
    use App\Models\Senderid;
    use App\Models\Subscription;
    use App\Models\SubscriptionLog;
    use App\Models\SubscriptionTransaction;
    use App\Models\User;
    use App\Notifications\TwoFactorCode;
    use App\Notifications\WelcomeEmailNotification;
    use App\Repositories\Contracts\AccountRepository;
    use Braintree\Gateway;
    use Carbon\Carbon;
    use Exception;
    use GuzzleHttp\Exception\GuzzleException;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Contracts\Foundation\Application;
    use Illuminate\Contracts\View\Factory;
    use Illuminate\Contracts\View\View;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\RedirectResponse;
    use Illuminate\Http\Request;
    use Illuminate\Routing\Redirector;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Facades\Session;
    use Illuminate\Support\Str;
    use Intervention\Image\Exception\NotReadableException;
    use Intervention\Image\Facades\Image;
    use JetBrains\PhpStorm\NoReturn;
    use MercadoPago\Client\Payment\PaymentClient;
    use MercadoPago\Exceptions\MPApiException;
    use MercadoPago\MercadoPagoConfig;
    use Mollie\Api\MollieApiClient;
    use MyFatoorah\Library\API\Payment\MyFatoorahPaymentStatus;
    use net\authorize\api\constants\ANetEnvironment;
    use net\authorize\api\contract\v1 as AnetAPI;
    use net\authorize\api\controller as AnetController;
    use Paynow\Payments\Paynow;
    use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
    use PaypalServerSdkLib\Environment;
    use PaypalServerSdkLib\Exceptions\ApiException;
    use PaypalServerSdkLib\Exceptions\OAuthProviderException;
    use PaypalServerSdkLib\Logging\LoggingConfigurationBuilder;
    use PaypalServerSdkLib\Logging\RequestLoggingConfigurationBuilder;
    use PaypalServerSdkLib\Logging\ResponseLoggingConfigurationBuilder;
    use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
    use Psr\Log\LogLevel;
    use RuntimeException;
    use Selcom\ApigwClient\Client;
    use SimpleXMLElement;
    use Stripe\Exception\ApiErrorException;
    use Stripe\StripeClient;

    class AccountController extends Controller
    {
        protected AccountRepository $account;

        /**
         * RegisterController constructor.
         */
        public function __construct(AccountRepository $account)
        {
            $this->account = $account;
        }

        /**
         * show profile page
         */
        public function index(): View|Factory|Application
        {
            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['name' => Auth::user()->displayName()],
            ];

            $languages = Language::where('status', 1)->get();

            $user = Auth::user();

            return view('auth.profile.index', compact('breadcrumbs', 'languages', 'user'));
        }

        /**
         * get avatar
         */
        public function avatar(Request $request): mixed
        {
            if ( ! empty($uid = $request->input('uid'))) {
                $user = User::where('uid', $uid)->first();
            } else {
                $user = Auth::user();
            }

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
         * update avatar
         */
        public function updateAvatar(Request $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = Auth::user();

            try {
                // Upload and save image
                if ($request->hasFile('image') && $request->file('image')->isValid()) {
                    // Remove old images
                    $user->removeImage();
                    $user->image = $user->uploadImage($request->file('image'));
                    $user->save();

                    return redirect()->route('user.account')->with([
                        'status'  => 'success',
                        'message' => __('locale.customer.avatar_update_successful'),
                    ]);
                }

                return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => __('locale.exceptions.invalid_image'),
                ]);

            } catch (Exception $exception) {
                return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        /**
         * remove avatar
         */
        public function removeAvatar(): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $user = Auth::user();
            // Remove old images
            $user->removeImage();
            $user->image = null;
            $user->save();

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.customer.avatar_remove_successful'),
            ]);
        }


        public function switchView(Request $request): RedirectResponse
        {
            $user = Auth::user();

            if (config('app.stage') == 'demo') {
                $user = User::where('id', 1)->first();
                Auth::login($user, true);
            }

            switch ($request->portal) {
                case 'customer':
                    if ($user->is_customer == 0) {
                        return redirect()->route('login')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.invalid_action'),
                        ]);
                    }

                    $user->last_access_at = Carbon::now();

                    $user->active_portal = 'customer';
                    $user->save();

                    if ($user->customer == null) {
                        Customer::create([
                            'user_id'       => $user->id,
                            'phone'         => '8801721970168',
                            'permissions'   => Customer::customerPermissions(),
                            'notifications' => json_encode([
                                'login'        => 'no',
                                'sender_id'    => 'yes',
                                'keyword'      => 'yes',
                                'subscription' => 'yes',
                                'promotion'    => 'yes',
                                'profile'      => 'yes',
                            ]),
                        ]);
                    }

                    $permissions = collect(json_decode($user->customer->permissions, true));
                    session(['permissions' => $permissions]);

                    return redirect()->route('user.home')->with([
                        'status'  => 'success',
                        'message' => __('locale.auth.welcome_come_back', ['name' => $user->displayName()]),
                    ]);

                case 'admin':
                    if ($user->is_admin == 0) {
                        return redirect()->route('login')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.invalid_action'),
                        ]);
                    }

                    $user->last_access_at = Carbon::now();

                    $user->active_portal = 'admin';

                    $user->save();

                    session(['permissions' => $user->getPermissions()]);

                    return redirect()->route('admin.home')->with([
                        'status'  => 'success',
                        'message' => __('locale.auth.welcome_come_back', ['name' => $user->displayName()]),
                    ]);

                default:
                    return redirect()->route('login')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                    ]);
            }
        }

        /**
         * profile update
         */
        public function update(UpdateUserRequest $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);

            }

            $input = $request->all();

            $data = $this->account->update($input);

            if (isset($data->getData()->status)) {
                return redirect()->route('user.account')->withInput(['tab' => 'account'])->with([
                    'status'  => $data->getData()->status,
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('user.account')->withInput(['tab' => 'account'])->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /**
         * changed password
         */
        public function changePassword(ChangePasswordRequest $request): Redirector|Application|RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->withInput(['tab' => 'security'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);

            }

            $user = Auth::user();

            // 1. Change the password
            $user->password = Hash::make($request->password);

// 2. Invalidate the "Remember Me" token (removes cookie persistence)
            $user->setRememberToken(Str::random(60));
            $user->password_changed_at = Carbon::now();
            $user->save();

// 3. Log out the current session
            Auth::logout();

// 4. Clear session data and regenerate the CSRF token
            $request->session()->invalidate();
            $request->session()->regenerateToken();


            return redirect('/login')->with([
                'status'  => 'success',
                'message' => 'Password was successfully changed',
            ]);

        }

        public function twoFactorAuthentication($status): Factory|View|Application|RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);

            }

            $user = Auth::user();

            if ($status == 'disabled') {
                $user->update([
                    'two_factor' => false,
                ]);
            }

            if ($user->two_factor_code == null && $user->two_factor_expires_at == null) {
                $user->generateTwoFactorCode();
                $user->notify(new TwoFactorCode(route('user.account.twofactor.auth', ['status' => $status])));
            }

            return view('auth.profile._update_two_factor_auth', compact('status'));

        }

        /**
         * update two-factor auth
         */
        public function updateTwoFactorAuthentication($status, Request $request): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);

            }

            $request->validate([
                'two_factor_code' => 'integer|required|min:6',
            ]);

            $user = Auth::user();

            if ($request->input('two_factor_code') == $user->two_factor_code) {
                $user->resetTwoFactorCode();
                if ($status == 'enable') {
                    $backup_codes = $user->generateTwoFactorBackUpCode();
                    $user->update([
                        'two_factor'             => true,
                        'two_factor_backup_code' => $backup_codes,
                    ]);

                    return redirect()->route('user.account')->withInput(['tab' => 'two_factor'])->with([
                        'status'      => 'success',
                        'backup_code' => $backup_codes,
                        'message'     => 'Two-Factor Authentication was successfully enabled',
                    ]);
                }

                $user->update([
                    'two_factor' => false,
                ]);

                return redirect()->route('user.account')->withInput(['tab' => 'two_factor'])->with([
                    'status'  => 'success',
                    'message' => 'Two-Factor Authentication was successfully disabled',
                ]);
            }

            return redirect()->back()->with([
                'status'  => 'error',
                'message' => __('locale.auth.two_factor_code_not_matched'),
            ]);
        }

        public function generateTwoFactorAuthenticationCode(): RedirectResponse
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);

            }

            $user = Auth::user();

            $backup_codes = $user->generateTwoFactorBackUpCode();
            $user->update([
                'two_factor_backup_code' => $backup_codes,
            ]);

            return redirect()->back()->with([
                'status'      => 'success',
                'backup_code' => $backup_codes,
                'message'     => 'Backup codes successfully generated',
            ]);
        }

        /**
         * update information
         */
        public function updateInformation(UpdateUserInformationRequest $request): RedirectResponse
        {

            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);

            }

            $input = $request->except('_token');

            $customer = Auth::user()->customer;

            $defaultNotifications = [
                'login'        => 'no',
                'sender_id'    => 'no',
                'keyword'      => 'no',
                'subscription' => 'no',
                'promotion'    => 'no',
                'profile'      => 'no',
            ];

            if (isset($input['notifications']) && count($input['notifications']) > 0) {
                $notifications          = array_merge($defaultNotifications, $input['notifications']);
                $input['notifications'] = json_encode($notifications);
            } else {
                $input['notifications'] = json_encode($defaultNotifications);
            }

            $data = $customer->update($input);

            if ($data) {
                return redirect()->route('user.account')->withInput(['tab' => 'information'])->with([
                    'status'  => 'success',
                    'message' => __('locale.customer.profile_was_successfully_updated'),
                ]);
            }

            return redirect()->route('user.account')->withInput(['tab' => 'information'])->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);
        }

        /**
         * @return JsonResponse
         *
         * @throws RuntimeException
         */
        public function delete(Request $request)
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);

            }

            if ( ! config('account.can_delete')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Account delete option is disabled.',
                ]);

            }

            $this->account->delete();

            auth()->logout();
            $request->session()->flush();
            $request->session()->regenerate();

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.auth.disabled'),
            ]);
        }

        #[NoReturn]
        public function notifications(Request $request): void
        {

            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'notification_type',
                4 => 'message',
                5 => 'mark_read',
                6 => 'action',
            ];

            $totalData = Notifications::where('user_id', Auth::user()->id)->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $notifications = Notifications::where('user_id', Auth::user()->id)->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $notifications = Notifications::where('user_id', Auth::user()->id)->whereLike(['uid', 'notification_type', 'message'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = Notifications::where('user_id', Auth::user()->id)->whereLike(['uid', 'notification_type', 'message'], $search)->count();
            }

            $data = [];
            if ( ! empty($notifications)) {
                foreach ($notifications as $notification) {

                    if ($notification->mark_read == 1) {
                        $status = 'checked';
                    } else {
                        $status = '';
                    }

                    $nestedData['responsive_id']     = '';
                    $nestedData['uid']               = $notification->uid;
                    $nestedData['notification_type'] = ucfirst($notification->notification_type);
                    $nestedData['message']           = $notification->message;
                    $nestedData['mark_read']         = "<div class='form-check form-switch form-check-primary'>
                <input type='checkbox' class='form-check-input get_status' id='status_$notification->uid' data-id='$notification->uid' name='status' $status>
                <label class='form-check-label' for='status_$notification->uid'>
                  <span class='switch-icon-left'><i data-feather='check'></i> </span>
                  <span class='switch-icon-right'><i data-feather='x'></i> </span>
                </label>
              </div>";
                    $data[]                          = $nestedData;

                }
            }

            $json_data = [
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            echo json_encode($json_data);
            exit();
        }

        /**
         * mark notification status
         *
         *
         * @throws GeneralException
         */
        public function notificationToggle(Notifications $notification): JsonResponse
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            try {

                if ($notification->update(['mark_read' => ! $notification->mark_read])) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => 'Notification read status was successfully changed',
                    ]);
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            } catch (ModelNotFoundException $exception) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $exception->getMessage(),
                ]);
            }

        }

        public function notificationBatchAction(Request $request): JsonResponse
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $action = $request->get('action');
            $ids    = $request->get('ids');

            switch ($action) {
                case 'destroy':

                    Notifications::where('user_id', Auth::user()->id)->whereIn('uid', $ids)->delete();

                    return response()->json([
                        'status'  => 'success',
                        'message' => 'Notifications was successfully deleted',
                    ]);

                case 'read':

                    Notifications::where('user_id', Auth::user()->id)->whereIn('uid', $ids)->update([
                        'mark_read' => true,
                    ]);

                    return response()->json([
                        'status'  => 'success',
                        'message' => 'Mark notifications as read',
                    ]);

            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.exceptions.invalid_action'),
            ]);

        }

        public function deleteNotification(Notifications $notification): JsonResponse
        {
            Notifications::where('uid', $notification->uid)->where('user_id', Auth::user()->id)->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Notification was successfully deleted',
            ]);
        }

        /**
         * top up
         */
        public function topUp(): Factory|View|Application
        {
            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => Auth::user()->displayName()],
                ['name' => __('locale.labels.top_up')],
            ];

            return \view('customer.Accounts.top_up', compact('breadcrumbs'));

        }

        public function checkoutTopUp(TopUpUnitsRequest $request)
        {
            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => Auth::user()->displayName()],
                ['name' => __('locale.labels.top_up')],
            ];

            $pageConfigs = [
                'bodyClass' => 'ecommerce-application',
            ];

            $amount = $request->input('add_unit');

            $unitInfo = PlanSendingCreditPrice::where('unit_from', '<=', $amount)->where('unit_to', '>=', $amount)->where('plan_id', Auth::user()->customer->activeSubscription()->plan_id)
                ->first();

            if ( ! $unitInfo) {

                return redirect()->back()->with([
                    'status'  => 'error',
                    'message' => 'Sorry! Your amount is not within our range',
                ]);
            }

            $sms_unit        = round($amount / $unitInfo->per_credit_cost);
            $payment_methods = PaymentMethods::where('status', true)->get();


            $country   = Country::where('name', Auth::user()->customer->country)->first();
            $taxAmount = 0;
            $taxRate   = 0;
            if ($country) {
                $taxRate = AppConfig::getTaxByCountry($country);
                if ($taxRate > 0) {
                    $taxAmount = ($amount * $taxRate) / 100;
                }
            }

            $totalAmount = $amount + $taxAmount;

            return \view('customer.Accounts.checkout_top_up', compact('breadcrumbs', 'amount', 'sms_unit', 'pageConfigs', 'payment_methods', 'totalAmount', 'taxAmount', 'taxRate'));
        }

        public function payTopUp(PayPayment $request): View|Factory|RedirectResponse|Application
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $data = $this->account->payPayment($request->except('_token'));

            if (isset($data->getData()->status)) {

                if ($data->getData()->status == 'success') {

                    if ($request->payment_methods == PaymentMethods::TYPE_BRAINTREE) {
                        return view('customer.Payments.braintree', [
                            'token'    => $data->getData()->token,
                            'post_url' => route('customer.top_up.braintree', ['user_id' => Auth::user()->id, 'sms_unit' => $request->sms_unit]),
                        ]);
                    }

                    if ($request->payment_methods == PaymentMethods::TYPE_STRIPE) {
                        return view('customer.Payments.stripe', [
                            'session_id'      => $data->getData()->session_id,
                            'publishable_key' => $data->getData()->publishable_key,
                        ]);
                    }

                    if ($request->payment_methods == PaymentMethods::TYPE_AUTHORIZE_NET) {

                        $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

                        return view('customer.Payments.authorize_net', [
                            'months'   => $months,
                            'post_url' => route('customer.top_up.authorize_net', ['user_id' => Auth::user()->id, 'sms_unit' => $request->sms_unit, 'price' => $request->price]),
                        ]);
                    }

                    if ($request->payment_methods == PaymentMethods::TYPE_CASH) {
                        return view('customer.Payments.offline', [
                            'data'      => $data->getData()->data,
                            'type'      => 'top_up',
                            'post_data' => $data->getData()->post_data,
                            'sms_unit'  => $data->getData()->sms_unit,
                        ]);
                    }

                    /*Version 3.5*/

                    if ($request->payment_methods == PaymentMethods::TYPE_VODACOMMPESA) {
                        return view('customer.Payments.vodacom_mpesa', [
                            'post_url' => route('customer.top_up.vodacommpesa', ['user_id' => Auth::user()->id, 'sms_unit' => $request->sms_unit, 'price' => $request->price]),
                        ]);
                    }

                    /*Version 3.6*/

                    if ($request->payment_methods == PaymentMethods::TYPE_EASYPAY) {
                        return view('customer.Payments.easypay', [
                            'data'         => $data->getData()->data,
                            'request_type' => 'top_up',
                            'post_data'    => $data->getData()->price,
                            'sms_unit'     => $data->getData()->sms_unit,

                        ]);
                    }

                    if ($request->payment_methods == PaymentMethods::TYPE_NOWPAYMENTS) {
                        return view('customer.Payments.nowpayments', [
                            'pay_address' => $data->getData()->pay_address,
                            'valid_unit'  => $data->getData()->valid_unit,
                            'type'        => 'top_up',
                            'post_data'   => $data->getData()->post_data,
                            'sms_unit'    => $data->getData()->sms_unit,
                            'payment_id'  => $data->getData()->payment_id,
                            'data'        => $data->getData()->data,
                        ]);
                    }

                    if ($request->payment_methods == PaymentMethods::TYPE_FEDAPAY) {

                        $user = User::find($data->getData()->user_id);

                        return view('customer.Payments.fedapay', [
                            'public_key' => $data->getData()->public_key,
                            'amount'     => $data->getData()->price,
                            'first_name' => $user->first_name,
                            'last_name'  => $user->last_name,
                            'email'      => $user->email,
                            'item_name'  => 'Payment for sms unit',
                            'postData'   => [
                                'user_id'      => $user->id,
                                'request_type' => 'top_up',
                                'post_data'    => $data->getData()->price,
                                'sms_unit'     => $data->getData()->sms_unit,
                            ],
                        ]);
                    }

                    if (isset($data->getData()->redirect_url)) {
                        return redirect()->to($data->getData()->redirect_url);
                    } else {
                        return redirect()->route('user.home')->with([
                            'status'  => 'error',
                            'message' => 'Redirect URL not found',
                        ]);
                    }

                }

                return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => $data->getData()->message,
                ]);
            }

            return redirect()->route('user.home')->with([
                'status'  => 'error',
                'message' => __('locale.exceptions.something_went_wrong'),
            ]);

        }

        /*Version 3.1*/
        /*
    |--------------------------------------------------------------------------
    | Registration Payment
    |--------------------------------------------------------------------------
    |
    |
    |
    */

        /**
         * @throws Exception
         */
        public function successfulRegisterPayment(User $user, Plan $plan, PaymentMethods $payment_method, Request $request): RedirectResponse
        {
            $price = Session::get('price');
            if ($price == null) {
                $price = $plan->price;
            }

            $country = Country::where('name', $user->customer->country)->first();
            // $price
            $taxAmount    = 0;
            $total_amount = $price;

            if ($country) {
                $taxRate = AppConfig::getTaxByCountry($country);
                if ($taxRate > 0) {
                    $taxAmount    = ($price * $taxRate) / 100;
                    $total_amount = $price + $taxAmount;
                }
            }


            switch ($payment_method->type) {

                case PaymentMethods::TYPE_PAYPAL:

                    $token = Session::get('paypal_payment_id');

                    if ($request->get('token') == $token) {
                        $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_PAYPAL)->first();

                        if ($paymentMethod) {
                            $credentials = json_decode($paymentMethod->options);

                            try {
                                $client = PaypalServerSdkClientBuilder::init()
                                    ->clientCredentialsAuthCredentials(
                                        ClientCredentialsAuthCredentialsBuilder::init(
                                            $credentials->client_id,
                                            $credentials->secret
                                        )
                                    );

                                if ($credentials->environment == 'sandbox') {
                                    $client->environment(Environment::SANDBOX);
                                } else {
                                    $client->environment(Environment::PRODUCTION);
                                }

// Add logging configuration and build the client
                                $client = $client->loggingConfiguration(
                                    LoggingConfigurationBuilder::init()
                                        ->level(LogLevel::INFO)
                                        ->requestConfiguration(RequestLoggingConfigurationBuilder::init()->body(true))
                                        ->responseConfiguration(ResponseLoggingConfigurationBuilder::init()->headers(true))
                                )->build();


                                $collect = [
                                    'id'     => $token,
                                    'prefer' => 'return=minimal',
                                ];

                                try {
                                    $response = $client->getOrdersController()->ordersCapture($collect);

                                    if ($response->isError()) {

                                        $description = data_get($response->getResult(), 'details.0.description', 'No description available');

                                        return redirect()->route('user.home')->with([
                                            'status'  => 'error',
                                            'message' => $description,
                                        ]);
                                    }

                                    if ($response->isSuccess() && $response->getResult()->getStatus() != null && $response->getResult()->getStatus() == 'COMPLETED' && ! empty($response->getResult()->getId())) {

                                        $invoice = Invoices::create([
                                            'user_id'        => $user->id,
                                            'currency_id'    => $plan->currency_id,
                                            'payment_method' => $paymentMethod->id,
                                            'amount'         => $price,
                                            'tax'            => $taxAmount,
                                            'total_amount'   => $total_amount,
                                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                                            'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                            'transaction_id' => $response->getResult()->getId(),
                                            'status'         => Invoices::STATUS_PAID,
                                        ]);

                                        if ($invoice) {

                                            $subscription                         = new Subscription();
                                            $subscription->user_id                = $user->id;
                                            $subscription->start_at               = Carbon::now();
                                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                                            $subscription->plan_id                = $plan->getBillableId();
                                            $subscription->end_period_last_days   = '10';
                                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                            $subscription->end_at                 = null;
                                            $subscription->end_by                 = null;
                                            $subscription->payment_method_id      = $paymentMethod->id;
                                            $subscription->save();

                                            // add transaction
                                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                                'end_at'                 => $subscription->end_at,
                                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                            ]);

                                            // add log
                                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                                'plan'  => $subscription->plan->getBillableName(),
                                                'price' => $subscription->plan->getBillableFormattedPrice(),
                                            ]);

                                            $user->sms_unit          = $plan->getOption('sms_max');
                                            $user->email_verified_at = Carbon::now();
                                            $user->save();

                                            $this->sendWelcomeEmail($user);

                                            //Add default Sender id
                                            $this->planSenderID($plan, $user);

                                            return redirect()->route('user.home')->with([
                                                'status'  => 'success',
                                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                                            ]);
                                        }

                                        $user->delete();

                                        return redirect()->route('register')->with([
                                            'status'  => 'error',
                                            'message' => __('locale.exceptions.something_went_wrong'),
                                        ]);

                                    }

                                    $user->delete();

                                    return redirect()->route('user.home')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);

                                } catch (ApiException $exception) {

                                    $user->delete();

                                    return redirect()->route('user.home')->with([
                                        'status'  => 'error',
                                        'message' => $exception->getMessage(),
                                    ]);
                                }

                            } catch (OAuthProviderException $exception) {

                                $user->delete();

                                return redirect()->route('user.home')->with([
                                    'status'  => 'error',
                                    'message' => $exception->getMessage(),
                                ]);
                            }
                        }

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.invalid_action'),
                        ]);
                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                    ]);

                case PaymentMethods::TYPE_STRIPE:
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', 'stripe')->first();

                    if ($paymentMethod) {
                        $credentials = json_decode($paymentMethod->options);
                        $secret_key  = $credentials->secret_key;
                        $session_id  = Session::get('session_id');

                        $stripe = new StripeClient($secret_key);

                        try {
                            $response = $stripe->checkout->sessions->retrieve($session_id);

                            if ($response->payment_status == 'paid') {
                                $invoice = Invoices::create([
                                    'user_id'        => $user->id,
                                    'currency_id'    => $plan->currency_id,
                                    'payment_method' => $paymentMethod->id,
                                    'amount'         => $price,
                                    'tax'            => $taxAmount,
                                    'total_amount'   => $total_amount,
                                    'type'           => Invoices::TYPE_SUBSCRIPTION,
                                    'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                    'transaction_id' => $response->payment_intent,
                                    'status'         => Invoices::STATUS_PAID,
                                ]);

                                if ($invoice) {

                                    $subscription                         = new Subscription();
                                    $subscription->user_id                = $user->id;
                                    $subscription->start_at               = Carbon::now();
                                    $subscription->status                 = Subscription::STATUS_ACTIVE;
                                    $subscription->plan_id                = $plan->getBillableId();
                                    $subscription->end_period_last_days   = '10';
                                    $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                    $subscription->end_at                 = null;
                                    $subscription->end_by                 = null;
                                    $subscription->payment_method_id      = $paymentMethod->id;
                                    $subscription->save();

                                    // add transaction
                                    $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                        'end_at'                 => $subscription->end_at,
                                        'current_period_ends_at' => $subscription->current_period_ends_at,
                                        'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                        'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                        'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    // add log
                                    $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                        'plan'  => $subscription->plan->getBillableName(),
                                        'price' => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    $user->sms_unit          = $plan->getOption('sms_max');
                                    $user->email_verified_at = Carbon::now();
                                    $user->save();

                                    $this->sendWelcomeEmail($user);

                                    //Add default Sender id
                                    $this->planSenderID($plan, $user);

                                    return redirect()->route('user.home')->with([
                                        'status'  => 'success',
                                        'message' => __('locale.payment_gateways.payment_successfully_made'),
                                    ]);
                                }

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                                ]);

                            }

                        } catch (ApiErrorException $e) {

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $e->getMessage(),
                            ]);
                        }

                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                    ]);

                case PaymentMethods::TYPE_2CHECKOUT:
                case PaymentMethods::TYPE_PAYU:
                case PaymentMethods::TYPE_COINPAYMENTS:
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', $payment_method->type)->first();

                    if ($paymentMethod) {
                        $invoice = Invoices::create([
                            'user_id'        => $user->id,
                            'currency_id'    => $plan->currency_id,
                            'payment_method' => $paymentMethod->id,
                            'amount'         => $price,
                            'tax'            => $taxAmount,
                            'total_amount'   => $total_amount,
                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                            'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                            'transaction_id' => $plan->uid,
                            'status'         => Invoices::STATUS_PAID,
                        ]);

                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                'end_at'                 => $subscription->end_at,
                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                'plan'  => $subscription->plan->getBillableName(),
                                'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                'status'  => 'success',
                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                case PaymentMethods::TYPE_PAYNOW:
                    $pollurl = Session::get('paynow_poll_url');
                    if (isset($pollurl)) {
                        $paymentMethod = PaymentMethods::where('status', true)->where('type', 'paynow')->first();

                        if ($paymentMethod) {
                            $credentials = json_decode($paymentMethod->options);

                            $paynow = new Paynow(
                                $credentials->integration_id,
                                $credentials->integration_key,
                                route('customer.callback.paynow'),
                                route('user.registers.payment_success', ['user' => $user->uid, 'plan' => $plan->uid, 'payment_method' => $paymentMethod->uid])
                            );

                            try {
                                $response = $paynow->pollTransaction($pollurl);

                                if ($response->paid()) {

                                    $invoice = Invoices::create([
                                        'user_id'        => $user->id,
                                        'currency_id'    => $plan->currency_id,
                                        'payment_method' => $paymentMethod->id,
                                        'amount'         => $price,
                                        'tax'            => $taxAmount,
                                        'total_amount'   => $total_amount,
                                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                                        'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                        'transaction_id' => $response->reference(),
                                        'status'         => Invoices::STATUS_PAID,
                                    ]);

                                    if ($invoice) {

                                        $subscription                         = new Subscription();
                                        $subscription->user_id                = $user->id;
                                        $subscription->start_at               = Carbon::now();
                                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                                        $subscription->plan_id                = $plan->getBillableId();
                                        $subscription->end_period_last_days   = '10';
                                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                        $subscription->end_at                 = null;
                                        $subscription->end_by                 = null;
                                        $subscription->payment_method_id      = $paymentMethod->id;
                                        $subscription->save();

                                        // add transaction
                                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                            'end_at'                 => $subscription->end_at,
                                            'current_period_ends_at' => $subscription->current_period_ends_at,
                                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        // add log
                                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                            'plan'  => $subscription->plan->getBillableName(),
                                            'price' => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        $user->sms_unit          = $plan->getOption('sms_max');
                                        $user->email_verified_at = Carbon::now();
                                        $user->save();

                                        $this->sendWelcomeEmail($user);

                                        //Add default Sender id
                                        $this->planSenderID($plan, $user);

                                        return redirect()->route('user.home')->with([
                                            'status'  => 'success',
                                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                                        ]);
                                    }

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);
                                }

                            } catch (Exception $ex) {

                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => $ex->getMessage(),
                                ]);
                            }

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'info',
                                'message' => __('locale.sender_id.payment_cancelled'),
                            ]);
                        }

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.payment_gateways.not_found'),
                        ]);
                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                    ]);

                case PaymentMethods::TYPE_INSTAMOJO:
                    $payment_request_id = Session::get('payment_request_id');

                    if ($request->payment_request_id == $payment_request_id) {
                        if ($request->payment_status == 'Completed') {

                            $paymentMethod = PaymentMethods::where('status', true)->where('type', 'instamojo')->first();

                            $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $price,
                                'tax'            => $taxAmount,
                                'total_amount'   => $total_amount,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                'transaction_id' => $request->payment_id,
                                'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        }

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'  => 'info',
                            'message' => $request->payment_status,
                        ]);
                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'info',
                        'message' => __('locale.payment_gateways.payment_info_not_found'),
                    ]);

                case PaymentMethods::TYPE_PAYUMONEY:

                    $status      = $request->status;
                    $firstname   = $request->firstname;
                    $amount      = $request->amount;
                    $txnid       = $request->txnid;
                    $posted_hash = $request->hash;
                    $key         = $request->key;
                    $productinfo = $request->productinfo;
                    $email       = $request->email;
                    $salt        = '';

                    // Salt should be same Post Request
                    if (isset($request->additionalCharges)) {
                        $additionalCharges = $request->additionalCharges;
                        $retHashSeq        = $additionalCharges . '|' . $salt . '|' . $status . '|||||||||||' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amount . '|' . $txnid . '|' . $key;
                    } else {
                        $retHashSeq = $salt . '|' . $status . '|||||||||||' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amount . '|' . $txnid . '|' . $key;
                    }
                    $hash = hash('sha512', $retHashSeq);
                    if ($hash != $posted_hash) {

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'  => 'info',
                            'message' => __('locale.exceptions.invalid_action'),
                        ]);
                    }

                    if ($status == 'Completed') {

                        $paymentMethod = PaymentMethods::where('status', true)->where('type', 'payumoney')->first();

                        $invoice = Invoices::create([
                            'user_id'        => $user->id,
                            'currency_id'    => $plan->currency_id,
                            'payment_method' => $paymentMethod->id,
                            'amount'         => $price,
                            'tax'            => $taxAmount,
                            'total_amount'   => $total_amount,
                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                            'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                            'transaction_id' => $txnid,
                            'status'         => Invoices::STATUS_PAID,
                        ]);

                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                'end_at'                 => $subscription->end_at,
                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                'plan'  => $subscription->plan->getBillableName(),
                                'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                'status'  => 'success',
                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);
                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $status,
                    ]);

                case PaymentMethods::TYPE_ORANGEMONEY:
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_ORANGEMONEY)->first();

                    if (isset($request->status)) {
                        if ($request->status == 'SUCCESS') {

                            $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $price,
                                'tax'            => $taxAmount,
                                'total_amount'   => $total_amount,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                'transaction_id' => $request->txnid,
                                'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        }

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'  => 'info',
                            'message' => $request->status,
                        ]);
                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                case PaymentMethods::TYPE_DIRECTPAYONLINE:
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_DIRECTPAYONLINE)->first();

                    if ($paymentMethod) {
                        $credentials = json_decode($paymentMethod->options);

                        if ($credentials->environment == 'production') {
                            $payment_url = 'https://secure.3gdirectpay.com';
                        } else {
                            $payment_url = 'https://secure1.sandbox.directpay.online';
                        }

                        $companyToken     = $credentials->company_token;
                        $TransactionToken = $request->TransactionToken;

                        $postXml = <<<POSTXML
<?xml version="1.0" encoding="utf-8"?>
        <API3G>
          <CompanyToken>$companyToken</CompanyToken>
          <Request>verifyToken</Request>
          <TransactionToken>$TransactionToken</TransactionToken>
        </API3G>
POSTXML;

                        $curl = curl_init();
                        curl_setopt_array($curl, [
                            CURLOPT_URL            => $payment_url . '/API/v6/',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 30,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => false,
                            CURLOPT_POSTFIELDS     => $postXml,
                            CURLOPT_HTTPHEADER     => [
                                'cache-control: no-cache',
                            ],
                        ]);

                        $response = curl_exec($curl);
                        curl_close($curl);

                        if ($response != '') {
                            $xml = new SimpleXMLElement($response);

                            // Check if token was created successfully
                            if ($xml->xpath('Result')[0] != '000') {

                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.invalid_action'),
                                ]);

                            }

                            if (isset($request->TransID) && isset($request->CCDapproval)) {
                                $invoice_exist = Invoices::where('transaction_id', $request->TransID)->first();
                                if ( ! $invoice_exist) {

                                    $invoice = Invoices::create([
                                        'user_id'        => $user->id,
                                        'currency_id'    => $plan->currency_id,
                                        'payment_method' => $paymentMethod->id,
                                        'amount'         => $price,
                                        'tax'            => $taxAmount,
                                        'total_amount'   => $total_amount,
                                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                                        'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                        'transaction_id' => $request->TransID,
                                        'status'         => Invoices::STATUS_PAID,
                                    ]);

                                    if ($invoice) {

                                        $subscription                         = new Subscription();
                                        $subscription->user_id                = $user->id;
                                        $subscription->start_at               = Carbon::now();
                                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                                        $subscription->plan_id                = $plan->getBillableId();
                                        $subscription->end_period_last_days   = '10';
                                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                        $subscription->end_at                 = null;
                                        $subscription->end_by                 = null;
                                        $subscription->payment_method_id      = $paymentMethod->id;
                                        $subscription->save();

                                        // add transaction
                                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                            'end_at'                 => $subscription->end_at,
                                            'current_period_ends_at' => $subscription->current_period_ends_at,
                                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        // add log
                                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                            'plan'  => $subscription->plan->getBillableName(),
                                            'price' => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        $user->sms_unit          = $plan->getOption('sms_max');
                                        $user->email_verified_at = Carbon::now();
                                        $user->save();

                                        $this->sendWelcomeEmail($user);

                                        //Add default Sender id
                                        $this->planSenderID($plan, $user);

                                        return redirect()->route('user.home')->with([
                                            'status'  => 'success',
                                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                                        ]);
                                    }

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);

                                }

                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                                ]);

                            }

                        }
                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                    ]);

                case PaymentMethods::TYPE_PAYGATEGLOBAL:
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_PAYGATEGLOBAL)->first();

                    if ($paymentMethod) {

                        $parameters = [
                            'auth_token' => $paymentMethod->api_key,
                            'identify'   => $request->identify,
                        ];

                        try {

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, 'https://paygateglobal.com/api/v2/status');
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            $response = curl_exec($ch);
                            curl_close($ch);

                            $get_response = json_decode($response, true);

                            if (isset($get_response) && is_array($get_response) && array_key_exists('status', $get_response)) {
                                if ($get_response['success'] == 0) {
                                    $invoice_exist = Invoices::where('transaction_id', $request->tx_reference)->first();
                                    if ( ! $invoice_exist) {

                                        $invoice = Invoices::create([
                                            'user_id'        => $user->id,
                                            'currency_id'    => $plan->currency_id,
                                            'payment_method' => $paymentMethod->id,
                                            'amount'         => $price,
                                            'tax'            => $taxAmount,
                                            'total_amount'   => $total_amount,
                                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                                            'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                            'transaction_id' => $request->tx_reference,
                                            'status'         => Invoices::STATUS_PAID,
                                        ]);

                                        if ($invoice) {

                                            $subscription                         = new Subscription();
                                            $subscription->user_id                = $user->id;
                                            $subscription->start_at               = Carbon::now();
                                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                                            $subscription->plan_id                = $plan->getBillableId();
                                            $subscription->end_period_last_days   = '10';
                                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                            $subscription->end_at                 = null;
                                            $subscription->end_by                 = null;
                                            $subscription->payment_method_id      = $paymentMethod->id;
                                            $subscription->save();

                                            // add transaction
                                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                                'end_at'                 => $subscription->end_at,
                                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                            ]);

                                            // add log
                                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                                'plan'  => $subscription->plan->getBillableName(),
                                                'price' => $subscription->plan->getBillableFormattedPrice(),
                                            ]);

                                            $user->sms_unit          = $plan->getOption('sms_max');
                                            $user->email_verified_at = Carbon::now();
                                            $user->save();

                                            $this->sendWelcomeEmail($user);

                                            //Add default Sender id
                                            $this->planSenderID($plan, $user);

                                            return redirect()->route('user.home')->with([
                                                'status'  => 'success',
                                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                                            ]);
                                        }

                                        return redirect()->route('register')->with([
                                            'status'  => 'error',
                                            'message' => __('locale.exceptions.something_went_wrong'),
                                        ]);

                                    }

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);

                                }

                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'info',
                                    'message' => 'Waiting for administrator approval',
                                ]);
                            }

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        } catch (Exception $e) {

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                case PaymentMethods::TYPE_CINETPAY:

                    $paymentMethod  = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_CINETPAY)->first();
                    $transaction_id = $request->transaction_id;
                    $credentials    = json_decode($paymentMethod->options);

                    $payment_data = [
                        'apikey'         => $credentials->api_key,
                        'site_id'        => $credentials->site_id,
                        'transaction_id' => $transaction_id,
                    ];

                    try {

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => $credentials->payment_url . '/check',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => json_encode($payment_data),
                            CURLOPT_HTTPHEADER     => [
                                'content-type: application/json',
                                'cache-control: no-cache',
                            ],
                        ]);

                        $response = curl_exec($curl);
                        $err      = curl_error($curl);

                        curl_close($curl);

                        if ($response === false) {

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => 'Php curl show false value. Please contact with your provider',
                            ]);

                        }

                        if ($err) {

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $err,
                            ]);
                        }

                        $result = json_decode($response, true);

                        if (is_array($result) && array_key_exists('code', $result) && array_key_exists('message', $result)) {
                            if ($result['code'] == '00') {

                                $invoice = Invoices::create([
                                    'user_id'        => $user->id,
                                    'currency_id'    => $plan->currency_id,
                                    'payment_method' => $paymentMethod->id,
                                    'amount'         => $price,
                                    'tax'            => $taxAmount,
                                    'total_amount'   => $total_amount,
                                    'type'           => Invoices::TYPE_SUBSCRIPTION,
                                    'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                    'transaction_id' => $transaction_id,
                                    'status'         => Invoices::STATUS_PAID,
                                ]);

                                if ($invoice) {

                                    $subscription                         = new Subscription();
                                    $subscription->user_id                = $user->id;
                                    $subscription->start_at               = Carbon::now();
                                    $subscription->status                 = Subscription::STATUS_ACTIVE;
                                    $subscription->plan_id                = $plan->getBillableId();
                                    $subscription->end_period_last_days   = '10';
                                    $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                    $subscription->end_at                 = null;
                                    $subscription->end_by                 = null;
                                    $subscription->payment_method_id      = $paymentMethod->id;
                                    $subscription->save();

                                    // add transaction
                                    $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                        'end_at'                 => $subscription->end_at,
                                        'current_period_ends_at' => $subscription->current_period_ends_at,
                                        'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                        'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                        'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    // add log
                                    $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                        'plan'  => $subscription->plan->getBillableName(),
                                        'price' => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    $user->sms_unit          = $plan->getOption('sms_max');
                                    $user->email_verified_at = Carbon::now();
                                    $user->save();

                                    $this->sendWelcomeEmail($user);

                                    //Add default Sender id
                                    $this->planSenderID($plan, $user);

                                    return redirect()->route('user.home')->with([
                                        'status'  => 'success',
                                        'message' => __('locale.payment_gateways.payment_successfully_made'),
                                    ]);
                                }

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                                ]);

                            }

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $result['message'],
                            ]);
                        }

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'       => 'error',
                            'redirect_url' => __('locale.exceptions.something_went_wrong'),
                        ]);
                    } catch (Exception $ex) {

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'       => 'error',
                            'redirect_url' => $ex->getMessage(),
                        ]);
                    }

                case PaymentMethods::TYPE_PAYHERELK:

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_PAYHERELK)->first();
                    if ($paymentMethod) {
                        $credentials = json_decode($paymentMethod->options);

                        try {

                            if ($credentials->environment == 'sandbox') {
                                $auth_url    = 'https://sandbox.payhere.lk/merchant/v1/oauth/token';
                                $payment_url = 'https://sandbox.payhere.lk/merchant/v1/payment/search?order_id=' . $request->get('order_id');
                            } else {
                                $auth_url    = 'https://payhere.lk/merchant/v1/oauth/token';
                                $payment_url = 'https://payhere.lk/merchant/v1/payment/search?order_id=' . $request->get('order_id');
                            }

                            $headers = [
                                'Content-Type: application/x-www-form-urlencoded',
                                'Authorization: Basic ' . base64_encode("$credentials->app_id:$credentials->app_secret"),
                            ];

                            $ch = curl_init();

                            curl_setopt($ch, CURLOPT_URL, $auth_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                            $auth_data = curl_exec($ch);

                            if (curl_errno($ch)) {
                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => curl_error($ch),
                                ]);
                            }

                            curl_close($ch);

                            $result = json_decode($auth_data, true);

                            if (is_array($result)) {
                                if (array_key_exists('error_description', $result)) {

                                    $user->delete();

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => $result['error_description'],
                                    ]);
                                }

                                $headers = [
                                    'Content-Type: application/json',
                                    'Authorization: Bearer ' . $result['access_token'],
                                ];

                                $curl = curl_init();

                                curl_setopt($curl, CURLOPT_URL, $payment_url);
                                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

                                $payment_data = curl_exec($curl);

                                if (curl_errno($curl)) {

                                    $user->delete();

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => curl_error($curl),
                                    ]);
                                }
                                curl_close($curl);

                                $result = json_decode($payment_data, true);

                                if (is_array($result)) {
                                    if (array_key_exists('error_description', $result)) {

                                        $user->delete();

                                        return redirect()->route('register')->with([
                                            'status'  => 'error',
                                            'message' => $result['error_description'],
                                        ]);
                                    }

                                    if (array_key_exists('status', $result) && $result['status'] == '-1') {
                                        $user->delete();

                                        return redirect()->route('register')->with([
                                            'status'  => 'error',
                                            'message' => $result['msg'],
                                        ]);
                                    }

                                    if (array_key_exists('status', $result) && $result['status'] == '1') {

                                        $invoice = Invoices::create([
                                            'user_id'        => $user->id,
                                            'currency_id'    => $plan->currency_id,
                                            'payment_method' => $paymentMethod->id,
                                            'amount'         => $price,
                                            'tax'            => $taxAmount,
                                            'total_amount'   => $total_amount,
                                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                                            'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                            'transaction_id' => $request->order_id,
                                            'status'         => Invoices::STATUS_PAID,
                                        ]);

                                        if ($invoice) {

                                            $subscription                         = new Subscription();
                                            $subscription->user_id                = $user->id;
                                            $subscription->start_at               = Carbon::now();
                                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                                            $subscription->plan_id                = $plan->getBillableId();
                                            $subscription->end_period_last_days   = '10';
                                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                            $subscription->end_at                 = null;
                                            $subscription->end_by                 = null;
                                            $subscription->payment_method_id      = $paymentMethod->id;
                                            $subscription->save();

                                            // add transaction
                                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                                'end_at'                 => $subscription->end_at,
                                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                            ]);

                                            // add log
                                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                                'plan'  => $subscription->plan->getBillableName(),
                                                'price' => $subscription->plan->getBillableFormattedPrice(),
                                            ]);

                                            $user->sms_unit          = $plan->getOption('sms_max');
                                            $user->email_verified_at = Carbon::now();
                                            $user->save();

                                            $this->sendWelcomeEmail($user);

                                            //Add default Sender id
                                            $this->planSenderID($plan, $user);

                                            return redirect()->route('user.home')->with([
                                                'status'  => 'success',
                                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                                            ]);
                                        }
                                    }

                                    $user->delete();

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);

                                }

                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                                ]);
                            }

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);
                        } catch (Exception $exception) {
                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $exception->getMessage(),
                            ]);
                        }
                    }
                    break;

                case PaymentMethods::TYPE_MOLLIE:

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_MOLLIE)->first();

                    if ($paymentMethod) {
                        $credentials = json_decode($paymentMethod->options);

                        $mollie = new MollieApiClient();
                        $mollie->setApiKey($credentials->api_key);

                        $payment_id = Session::get('payment_id');

                        $payment = $mollie->payments->get($payment_id);

                        if ($payment->isPaid()) {

                            $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $price,
                                'tax'            => $taxAmount,
                                'total_amount'   => $total_amount,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                'transaction_id' => $payment_id,
                                'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        }

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                    ]);

                case PaymentMethods::TYPE_SELCOMMOBILE:

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_SELCOMMOBILE)->first();
                    if ($paymentMethod) {
                        $credentials = json_decode($paymentMethod->options);

                        $orderStatusArray = [
                            'order_id' => $plan->uid,
                        ];

                        $client = new Client($credentials->payment_url, $credentials->api_key, $credentials->api_secret);

                        // path relative to base url
                        $orderStatusPath = '/checkout/order-status';

                        // create order minimal
                        try {
                            $response = $client->getFunc($orderStatusPath, $orderStatusArray);

                            if (isset($response) && is_array($response) && array_key_exists('data', $response) && array_key_exists('result', $response)) {
                                if ($response['result'] == 'SUCCESS' && array_key_exists('0', $response['data']) && $response['data'][0]['payment_status'] == 'COMPLETED') {

                                    $invoice = Invoices::create([
                                        'user_id'        => $user->id,
                                        'currency_id'    => $plan->currency_id,
                                        'payment_method' => $paymentMethod->id,
                                        'amount'         => $price,
                                        'tax'            => $taxAmount,
                                        'total_amount'   => $total_amount,
                                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                                        'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                        'transaction_id' => $response['data'][0]['transid'],
                                        'status'         => Invoices::STATUS_PAID,
                                    ]);

                                    if ($invoice) {

                                        $subscription                         = new Subscription();
                                        $subscription->user_id                = $user->id;
                                        $subscription->start_at               = Carbon::now();
                                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                                        $subscription->plan_id                = $plan->getBillableId();
                                        $subscription->end_period_last_days   = '10';
                                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                        $subscription->end_at                 = null;
                                        $subscription->end_by                 = null;
                                        $subscription->payment_method_id      = $paymentMethod->id;
                                        $subscription->save();

                                        // add transaction
                                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                            'end_at'                 => $subscription->end_at,
                                            'current_period_ends_at' => $subscription->current_period_ends_at,
                                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        // add log
                                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                            'plan'  => $subscription->plan->getBillableName(),
                                            'price' => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        $user->sms_unit          = $plan->getOption('sms_max');
                                        $user->email_verified_at = Carbon::now();
                                        $user->save();

                                        $this->sendWelcomeEmail($user);

                                        //Add default Sender id
                                        $this->planSenderID($plan, $user);

                                        return redirect()->route('user.home')->with([
                                            'status'  => 'success',
                                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                                        ]);
                                    }

                                    $user->delete();

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);

                                } else {

                                    $user->delete();

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => $response['message'],
                                    ]);
                                }
                            }

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $response,
                            ]);

                        } catch (Exception $exception) {
                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => $exception->getMessage(),
                            ]);
                        }

                    }
                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                    ]);

                case PaymentMethods::TYPE_MPGS:

                    $order_id = $request->input('order_id');

                    if (empty($order_id)) {
                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => 'Payment error: Invalid transaction.',
                        ]);
                    }

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_MPGS)->first();

                    if ( ! $paymentMethod) {
                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.payment_gateways.not_found'),
                        ]);

                    }

                    $credentials = json_decode($paymentMethod->options);

                    $config = [
                        'payment_url'             => $credentials->payment_url,
                        'api_version'             => $credentials->api_version,
                        'merchant_id'             => $credentials->merchant_id,
                        'authentication_password' => $credentials->authentication_password,
                    ];

                    $paymentData = [
                        'order_id' => $order_id,
                    ];

                    $mpgs   = new MPGS($config, $paymentData);
                    $result = $mpgs->process_response();

                    if (isset($result->getData()->status) && isset($result->getData()->message)) {
                        if ($result->getData()->status == 'success') {

                            $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $price,
                                'tax'            => $taxAmount,
                                'total_amount'   => $total_amount,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                'transaction_id' => $result->getData()->transaction_id,
                                'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        }

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => $result->getData()->message,
                        ]);
                    }

                    return redirect()->route('customer.subscriptions.purchase', $plan->uid)->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                case PaymentMethods::TYPE_0XPROCESSING:

                    $order_id = $request->input('order_id');

                    if (empty($order_id)) {
                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => 'Payment error: Invalid transaction.',
                        ]);
                    }

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_0XPROCESSING)->first();

                    if ( ! $paymentMethod) {
                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.payment_gateways.not_found'),
                        ]);

                    }

                    $invoice = Invoices::create([
                        'user_id'        => $user->id,
                        'currency_id'    => $plan->currency_id,
                        'payment_method' => $paymentMethod->id,
                        'amount'         => $price,
                        'tax'            => $taxAmount,
                        'total_amount'   => $total_amount,
                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                        'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                        'transaction_id' => $order_id,
                        'status'         => Invoices::STATUS_PAID,
                    ]);

                    if ($invoice) {

                        $subscription                         = new Subscription();
                        $subscription->user_id                = $user->id;
                        $subscription->start_at               = Carbon::now();
                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                        $subscription->plan_id                = $plan->getBillableId();
                        $subscription->end_period_last_days   = '10';
                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                        $subscription->end_at                 = null;
                        $subscription->end_by                 = null;
                        $subscription->payment_method_id      = $paymentMethod->id;
                        $subscription->save();

                        // add transaction
                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                            'end_at'                 => $subscription->end_at,
                            'current_period_ends_at' => $subscription->current_period_ends_at,
                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        // add log
                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                            'plan'  => $subscription->plan->getBillableName(),
                            'price' => $subscription->plan->getBillableFormattedPrice(),
                        ]);

                        $user->sms_unit          = $plan->getOption('sms_max');
                        $user->email_verified_at = Carbon::now();
                        $user->save();

                        $this->sendWelcomeEmail($user);

                        //Add default Sender id
                        $this->planSenderID($plan, $user);

                        return redirect()->route('user.home')->with([
                            'status'  => 'success',
                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                        ]);
                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.something_went_wrong'),
                    ]);

                case PaymentMethods::TYPE_MYFATOORAH:

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_MYFATOORAH)->first();

                    if ($paymentMethod && isset($request->paymentId)) {
                        $credentials = json_decode($paymentMethod->options);

                        if ($credentials->environment == 'sandbox') {
                            $isTestMode = true;
                        } else {
                            $isTestMode = false;
                        }

                        $config = [
                            'apiKey' => $credentials->api_token,
                            'vcCode' => $credentials->country_iso_code,
                            'isTest' => $isTestMode,
                        ];

                        try {
                            $mfObj = new MyFatoorahPaymentStatus($config);
                            $data  = $mfObj->getPaymentStatus($request->paymentId, 'paymentId');

                            if ($data->InvoiceError) {
                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => 'Your payment was ' . $data->InvoiceError,
                                ]);
                            }

                            if ($data->InvoiceStatus == 'Paid') {

                                $invoice = Invoices::create([
                                    'user_id'        => $user->id,
                                    'currency_id'    => $plan->currency_id,
                                    'payment_method' => $paymentMethod->id,
                                    'amount'         => $price,
                                    'tax'            => $taxAmount,
                                    'total_amount'   => $total_amount,
                                    'type'           => Invoices::TYPE_SUBSCRIPTION,
                                    'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                    'transaction_id' => $data->InvoiceId,
                                    'status'         => Invoices::STATUS_PAID,
                                ]);

                                if ($invoice) {

                                    $subscription                         = new Subscription();
                                    $subscription->user_id                = $user->id;
                                    $subscription->start_at               = Carbon::now();
                                    $subscription->status                 = Subscription::STATUS_ACTIVE;
                                    $subscription->plan_id                = $plan->getBillableId();
                                    $subscription->end_period_last_days   = '10';
                                    $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                    $subscription->end_at                 = null;
                                    $subscription->end_by                 = null;
                                    $subscription->payment_method_id      = $paymentMethod->id;
                                    $subscription->save();

                                    // add transaction
                                    $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                        'end_at'                 => $subscription->end_at,
                                        'current_period_ends_at' => $subscription->current_period_ends_at,
                                        'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                        'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                        'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    // add log
                                    $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                        'plan'  => $subscription->plan->getBillableName(),
                                        'price' => $subscription->plan->getBillableFormattedPrice(),
                                    ]);

                                    $user->sms_unit          = $plan->getOption('sms_max');
                                    $user->email_verified_at = Carbon::now();
                                    $user->save();

                                    $this->sendWelcomeEmail($user);

                                    //Add default Sender id
                                    $this->planSenderID($plan, $user);

                                    return redirect()->route('user.home')->with([
                                        'status'  => 'success',
                                        'message' => __('locale.payment_gateways.payment_successfully_made'),
                                    ]);
                                }

                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                                ]);

                            }

                            return redirect()->route('customer.subscriptions.purchase', $plan->uid)->with([
                                'status'  => 'error',
                                'message' => 'Your payment was ' . $data->InvoiceStatus,
                            ]);
                        } catch (Exception $ex) {

                            return redirect()->route('customer.subscriptions.purchase', $plan->uid)->with([
                                'status'  => 'error',
                                'message' => $ex->getMessage(),
                            ]);
                        }

                    }

                    return redirect()->route('customer.subscriptions.purchase', $plan->uid)->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                    ]);

                case PaymentMethods::TYPE_MAYA:
                    $reference = Session::get('reference');
                    if ($reference == null) {
                        $reference = $request->reference;
                    }

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_MAYA)->first();

                    if ($paymentMethod) {
                        $credentials = json_decode($paymentMethod->options);

                        if ($credentials->environment == 'sandbox') {
                            $payment_url = 'https://pg-sandbox.paymaya.com/payments/v1/payment-rrns/' . $reference;
                        } else {
                            $payment_url = 'https://pg.paymaya.com/payments/v1/payment-rrns/' . $reference;
                        }

                        try {

                            $client = new \GuzzleHttp\Client();

                            $response = $client->request('GET', $payment_url, [
                                'headers' => [
                                    'accept'        => 'application/json',
                                    'authorization' => 'Basic ' . base64_encode($credentials->secret_key),
                                ],
                            ]);

                            $data = json_decode($response->getBody()->getContents(), true);

                            if (is_array($data)) {
                                if (array_key_exists('0', $data) && array_key_exists('status', $data[0]) && $data[0]['status'] == 'PAYMENT_SUCCESS') {

                                    $invoice = Invoices::create([
                                        'user_id'        => $user->id,
                                        'currency_id'    => $plan->currency_id,
                                        'payment_method' => $paymentMethod->id,
                                        'amount'         => $price,
                                        'tax'            => $taxAmount,
                                        'total_amount'   => $total_amount,
                                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                                        'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                        'transaction_id' => $data[0]['id'],
                                        'status'         => Invoices::STATUS_PAID,
                                    ]);

                                    if ($invoice) {

                                        $subscription                         = new Subscription();
                                        $subscription->user_id                = $user->id;
                                        $subscription->start_at               = Carbon::now();
                                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                                        $subscription->plan_id                = $plan->getBillableId();
                                        $subscription->end_period_last_days   = '10';
                                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                        $subscription->end_at                 = null;
                                        $subscription->end_by                 = null;
                                        $subscription->payment_method_id      = $paymentMethod->id;
                                        $subscription->save();

                                        // add transaction
                                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                            'end_at'                 => $subscription->end_at,
                                            'current_period_ends_at' => $subscription->current_period_ends_at,
                                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        // add log
                                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                            'plan'  => $subscription->plan->getBillableName(),
                                            'price' => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        $user->sms_unit          = $plan->getOption('sms_max');
                                        $user->email_verified_at = Carbon::now();
                                        $user->save();

                                        $this->sendWelcomeEmail($user);

                                        //Add default Sender id
                                        $this->planSenderID($plan, $user);

                                        return redirect()->route('user.home')->with([
                                            'status'  => 'success',
                                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                                        ]);
                                    }

                                    $user->delete();

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);

                                } else if (array_key_exists('code', $data) && array_key_exists('message', $data)) {

                                    $user->delete();

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => $data['message'],
                                    ]);
                                }

                                $user->delete();

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => __('locale.exceptions.something_went_wrong'),
                                ]);

                            }

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);
                        } catch (GuzzleException $e) {
                            // Extract JSON part from the error string
                            if (preg_match('/{.*}/', $e->getMessage(), $matches)) {
                                // Decode the JSON to an associative array
                                $errorData = json_decode($matches[0], true);

                                // Get the message value
                                $message = $errorData['message'] ?? null;

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => $message,
                                ]);
                            } else {

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => 'No JSON found in the error message.',
                                ]);
                            }
                        }

                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                    ]);

                case PaymentMethods::TYPE_RAZORPAY:

                    $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_RAZORPAY)->first();

                    if ($paymentMethod) {

                        $order_id  = Session::get('razorpay_payment_link_reference_id');
                        $paymentId = $request->input('razorpay_payment_id');

                        if (isset($order_id) && $order_id == $request->razorpay_payment_link_reference_id && empty($paymentId) === false && $request->razorpay_payment_link_status == 'paid') {

                            $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $price,
                                'tax'            => $taxAmount,
                                'total_amount'   => $total_amount,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                'transaction_id' => $paymentId,
                                'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            $user->delete();

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);
                        }

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                    ]);

                case PaymentMethods::TYPE_MERCADOPAGO:
                    $reference = Session::get('reference');
                    if ($request->get('reference') == $reference && $request->get('payment_id')) {
                        $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_MERCADOPAGO)->first();

                        if ($paymentMethod) {
                            $credentials = json_decode($paymentMethod->options);

                            MercadoPagoConfig::setAccessToken($credentials->access_token);

                            if ($credentials->environment == 'local') {
                                MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);
                            } else {
                                MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::SERVER);
                            }

                            $client = new PaymentClient();

                            try {

                                $payment = $client->get($request->get('payment_id'));

                                if (isset($payment->status) && $payment->status == 'approved') {

                                    $invoice = Invoices::create([
                                        'user_id'        => $user->id,
                                        'currency_id'    => $plan->currency_id,
                                        'payment_method' => $paymentMethod->id,
                                        'amount'         => $price,
                                        'tax'            => $taxAmount,
                                        'total_amount'   => $total_amount,
                                        'type'           => Invoices::TYPE_SUBSCRIPTION,
                                        'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                        'transaction_id' => $request->get('payment_id'),
                                        'status'         => Invoices::STATUS_PAID,
                                    ]);

                                    if ($invoice) {

                                        $subscription                         = new Subscription();
                                        $subscription->user_id                = $user->id;
                                        $subscription->start_at               = Carbon::now();
                                        $subscription->status                 = Subscription::STATUS_ACTIVE;
                                        $subscription->plan_id                = $plan->getBillableId();
                                        $subscription->end_period_last_days   = '10';
                                        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                        $subscription->end_at                 = null;
                                        $subscription->end_by                 = null;
                                        $subscription->payment_method_id      = $paymentMethod->id;
                                        $subscription->save();

                                        // add transaction
                                        $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                            'end_at'                 => $subscription->end_at,
                                            'current_period_ends_at' => $subscription->current_period_ends_at,
                                            'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                            'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                            'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        // add log
                                        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                            'plan'  => $subscription->plan->getBillableName(),
                                            'price' => $subscription->plan->getBillableFormattedPrice(),
                                        ]);

                                        $user->sms_unit          = $plan->getOption('sms_max');
                                        $user->email_verified_at = Carbon::now();
                                        $user->save();

                                        $this->sendWelcomeEmail($user);

                                        //Add default Sender id
                                        $this->planSenderID($plan, $user);

                                        return redirect()->route('user.home')->with([
                                            'status'  => 'success',
                                            'message' => __('locale.payment_gateways.payment_successfully_made'),
                                        ]);
                                    }

                                    $user->delete();

                                    return redirect()->route('register')->with([
                                        'status'  => 'error',
                                        'message' => __('locale.exceptions.something_went_wrong'),
                                    ]);

                                }


                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => $payment->status_detail,
                                ]);

                            } catch (MPApiException|Exception $exception) {

                                return redirect()->route('register')->with([
                                    'status'  => 'error',
                                    'message' => $exception->getMessage(),
                                ]);
                            }

                        }

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.invalid_action'),
                        ]);
                    }

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.payment_gateways.not_found'),
                    ]);

            }

            $user->delete();

            return redirect()->route('user.home')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
            ]);
        }

        public function cancelledRegisterPayment(User $user): RedirectResponse
        {
            $user->delete();

            return redirect()->route('register')->with([
                'status'  => 'info',
                'message' => __('locale.sender_id.payment_cancelled'),
            ]);
        }

        public function braintreeRegister(Request $request): RedirectResponse
        {

            $plan = Plan::where('uid', $request->plan)->first();
            $user = User::where('uid', $request->user)->first();

            if ( ! $plan) {
                return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => __('locale.payment_gateways.payment_info_not_found'),
                ]);
            }
            $paymentMethod = PaymentMethods::where('status', true)->where('type', 'braintree')->first();

            if ($paymentMethod) {

                $country      = Country::where('name', $user->customer->country)->first();
                $price        = $plan->price; // $price
                $taxAmount    = 0;
                $total_amount = $price;

                if ($country) {
                    $taxRate = AppConfig::getTaxByCountry($country);
                    if ($taxRate > 0) {
                        $taxAmount    = ($price * $taxRate) / 100;
                        $total_amount = $price + $taxAmount;
                    }
                }

                $credentials = json_decode($paymentMethod->options);

                try {
                    $gateway = new Gateway([
                        'environment' => $credentials->environment,
                        'merchantId'  => $credentials->merchant_id,
                        'publicKey'   => $credentials->public_key,
                        'privateKey'  => $credentials->private_key,
                    ]);

                    $result = $gateway->transaction()->sale([
                        'amount'             => $plan->price,
                        'paymentMethodNonce' => $request->payment_method_nonce,
                        'deviceData'         => $request->device_data,
                        'options'            => [
                            'submitForSettlement' => true,
                        ],
                    ]);

                    if ($result->success && isset($result->transaction->id)) {
                        $invoice = Invoices::create([
                            'user_id'        => $user->id,
                            'currency_id'    => $plan->currency_id,
                            'payment_method' => $paymentMethod->id,
                            'amount'         => $price,
                            'tax'            => $taxAmount,
                            'total_amount'   => $total_amount,
                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                            'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                            'transaction_id' => $result->transaction->id,
                            'status'         => Invoices::STATUS_PAID,
                        ]);

                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                'end_at'                 => $subscription->end_at,
                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                'plan'  => $subscription->plan->getBillableName(),
                                'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                'status'  => 'success',
                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }

                        $user->delete();

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $result->message,
                    ]);

                } catch (Exception $exception) {

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $user->delete();

            return redirect()->route('register')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
            ]);
        }

        public function authorizeNetRegister(User $user, Request $request): RedirectResponse
        {

            $plan = Plan::where('uid', $request->plan)->first();

            if ( ! $plan) {

                $user->delete();

                return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => __('locale.payment_gateways.payment_info_not_found'),
                ]);
            }

            $paymentMethod = PaymentMethods::where('status', true)->where('type', 'authorize_net')->first();

            if ($paymentMethod) {
                $credentials = json_decode($paymentMethod->options);

                $country      = Country::where('name', $user->customer->country)->first();
                $price        = $plan->price; // $price
                $taxAmount    = 0;
                $total_amount = $price;

                if ($country) {
                    $taxRate = AppConfig::getTaxByCountry($country);
                    if ($taxRate > 0) {
                        $taxAmount    = ($price * $taxRate) / 100;
                        $total_amount = $price + $taxAmount;
                    }
                }

                try {

                    $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
                    $merchantAuthentication->setName($credentials->login_id);
                    $merchantAuthentication->setTransactionKey($credentials->transaction_key);

                    // Set the transaction's refId
                    $refId      = 'ref' . time();
                    $cardNumber = preg_replace('/\s+/', '', $request->cardNumber);

                    // Create the payment data for a credit card
                    $creditCard = new AnetAPI\CreditCardType();
                    $creditCard->setCardNumber($cardNumber);
                    $creditCard->setExpirationDate($request->expiration_year . '-' . $request->expiration_month);
                    $creditCard->setCardCode($request->cvv);

                    // Add the payment data to a paymentType object
                    $paymentOne = new AnetAPI\PaymentType();
                    $paymentOne->setCreditCard($creditCard);

                    // Create order information
                    $order = new AnetAPI\OrderType();
                    $order->setInvoiceNumber($plan->uid);
                    $order->setDescription(__('locale.subscription.payment_for_plan') . ' ' . $plan->name);

                    // Set the customer's Bill To address
                    $customerAddress = new AnetAPI\CustomerAddressType();
                    $customerAddress->setFirstName(auth()->user()->first_name);
                    $customerAddress->setLastName(auth()->user()->last_name);

                    // Set the customer's identifying information
                    $customerData = new AnetAPI\CustomerDataType();
                    $customerData->setType('individual');
                    $customerData->setId(auth()->user()->id);
                    $customerData->setEmail(auth()->user()->email);

                    // Create a TransactionRequestType object and add the previous objects to it
                    $transactionRequestType = new AnetAPI\TransactionRequestType();
                    $transactionRequestType->setTransactionType('authCaptureTransaction');
                    $transactionRequestType->setAmount($plan->price);
                    $transactionRequestType->setOrder($order);
                    $transactionRequestType->setPayment($paymentOne);
                    $transactionRequestType->setBillTo($customerAddress);
                    $transactionRequestType->setCustomer($customerData);

                    // Assemble the complete transaction request
                    $requests = new AnetAPI\CreateTransactionRequest();
                    $requests->setMerchantAuthentication($merchantAuthentication);
                    $requests->setRefId($refId);
                    $requests->setTransactionRequest($transactionRequestType);

                    // Create the controller and get the response
                    $controller = new AnetController\CreateTransactionController($requests);
                    if ($credentials->environment == 'sandbox') {
                        $result = $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);
                    } else {
                        $result = $controller->executeWithApiResponse(ANetEnvironment::PRODUCTION);
                    }

                    if (isset($result) && $result->getMessages()->getResultCode() == 'Ok' && $result->getTransactionResponse()) {
                        $invoice = Invoices::create([
                            'user_id'        => $user->id,
                            'currency_id'    => $plan->currency_id,
                            'payment_method' => $paymentMethod->id,
                            'amount'         => $price,
                            'tax'            => $taxAmount,
                            'total_amount'   => $total_amount,
                            'type'           => Invoices::TYPE_SUBSCRIPTION,
                            'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                            'transaction_id' => $result->getRefId(),
                            'status'         => Invoices::STATUS_PAID,
                        ]);

                        if ($invoice) {

                            $subscription                         = new Subscription();
                            $subscription->user_id                = $user->id;
                            $subscription->start_at               = Carbon::now();
                            $subscription->status                 = Subscription::STATUS_ACTIVE;
                            $subscription->plan_id                = $plan->getBillableId();
                            $subscription->end_period_last_days   = '10';
                            $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                            $subscription->end_at                 = null;
                            $subscription->end_by                 = null;
                            $subscription->payment_method_id      = $paymentMethod->id;
                            $subscription->save();

                            // add transaction
                            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                'end_at'                 => $subscription->end_at,
                                'current_period_ends_at' => $subscription->current_period_ends_at,
                                'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            // add log
                            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                'plan'  => $subscription->plan->getBillableName(),
                                'price' => $subscription->plan->getBillableFormattedPrice(),
                            ]);

                            $user->sms_unit          = $plan->getOption('sms_max');
                            $user->email_verified_at = Carbon::now();
                            $user->save();

                            $this->sendWelcomeEmail($user);

                            //Add default Sender id
                            $this->planSenderID($plan, $user);

                            return redirect()->route('user.home')->with([
                                'status'  => 'success',
                                'message' => __('locale.payment_gateways.payment_successfully_made'),
                            ]);
                        }

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    }

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                    ]);

                } catch (Exception $exception) {

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $user->delete();

            return redirect()->route('register')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
            ]);
        }

        /**
         * sslcommerz subscription payment
         */
        public function sslcommerzRegister(Request $request): RedirectResponse
        {

            $plan = Plan::where('uid', $request->tran_id)->first();
            $user = User::where('uid', $request->user)->first();

            if (isset($request->status)) {
                if ($request->status == 'VALID') {
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', 'sslcommerz')->first();
                    if ($paymentMethod) {

                        if ($plan && $user) {

                            $country      = Country::where('name', $user->customer->country)->first();
                            $price        = $plan->price; // $price
                            $taxAmount    = 0;
                            $total_amount = $price;

                            if ($country) {
                                $taxRate = AppConfig::getTaxByCountry($country);
                                if ($taxRate > 0) {
                                    $taxAmount    = ($price * $taxRate) / 100;
                                    $total_amount = $price + $taxAmount;
                                }
                            }

                            $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $price,
                                'tax'            => $taxAmount,
                                'total_amount'   => $total_amount,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                'transaction_id' => $request->bank_tran_id,
                                'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);
                        }

                        return redirect()->route('user.home')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);
                    }
                }

                $user->delete();

                return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => $request->status,
                ]);

            }

            $user->delete();

            return redirect()->route('user.home')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
            ]);
        }

        /**
         * aamarpay subscription payment
         */
        public function aamarpayRegister(Request $request): RedirectResponse
        {

            $plan = Plan::where('uid', $request->mer_txnid)->first();
            $user = User::where('uid', $request->user)->first();

            if (isset($request->pay_status) && isset($request->mer_txnid)) {

                if ($request->pay_status == 'Successful') {
                    $paymentMethod = PaymentMethods::where('status', true)->where('type', 'aamarpay')->first();
                    if ($paymentMethod) {

                        if ($plan) {

                            $country      = Country::where('name', $user->customer->country)->first();
                            $price        = $plan->price; // $price
                            $taxAmount    = 0;
                            $total_amount = $price;

                            if ($country) {
                                $taxRate = AppConfig::getTaxByCountry($country);
                                if ($taxRate > 0) {
                                    $taxAmount    = ($price * $taxRate) / 100;
                                    $total_amount = $price + $taxAmount;
                                }
                            }

                            $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $price,
                                'tax'            => $taxAmount,
                                'total_amount'   => $total_amount,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                'transaction_id' => $request->pg_txnid,
                                'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }

                            return redirect()->route('register')->with([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);
                        }

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);
                    }
                }

                $user->delete();

                return redirect()->route('register')->with([
                    'status'  => 'error',
                    'message' => $request->pay_status,
                ]);

            }

            $user->delete();

            return redirect()->route('user.home')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
            ]);
        }

        /*Version 3.3*/

        public function sendWelcomeEmail($user)
        {

            Auth::loginUsingId($user->id);

            $permissions = collect(json_decode($user->customer->permissions, true));
            session(['permissions' => $permissions]);

            if (Helper::app_config('user_registration_notification_email')) {
                $user->notify(new WelcomeEmailNotification($user->first_name, $user->last_name, $user->email, route('login'), ''));
            }
        }

        /*Version 3.5*/

        /**
         * Plan sender id
         *
         *
         * @return void
         */
        public function planSenderID($plan, $user)
        {
            if (isset($plan->getOptions()['sender_id']) && $plan->getOption('sender_id') !== null) {
                $sender_id = Senderid::where('sender_id', $plan->getOption('sender_id'))->where('user_id', $user->id)->first();
                if ( ! $sender_id) {
                    $current = Carbon::now();
                    Senderid::create([
                        'sender_id'        => $plan->getOption('sender_id'),
                        'status'           => 'active',
                        'price'            => $plan->getOption('sender_id_price'),
                        'billing_cycle'    => $plan->getOption('sender_id_billing_cycle'),
                        'frequency_amount' => $plan->getOption('sender_id_frequency_amount'),
                        'frequency_unit'   => $plan->getOption('sender_id_frequency_unit'),
                        'currency_id'      => $plan->currency->id,
                        'validity_date'    => $current->add($plan->getOption('sender_id_frequency_unit'), (int) $plan->getOption('sender_id_frequency_amount')),
                        'payment_claimed'  => true,
                        'user_id'          => $user->id,
                        'business_id'      => app(\App\Library\Business\LegacyBusinessResolver::class)->resolveForCustomer((int) $user->id)?->id,
                    ]);
                }
            }
        }

        public function vodacommpesaRegister(User $user, Request $request): RedirectResponse
        {

            $plan = Plan::where('uid', $request->plan)->first();

            if ( ! $plan) {

                $user->delete();

                return redirect()->route('user.home')->with([
                    'status'  => 'error',
                    'message' => __('locale.payment_gateways.payment_info_not_found'),
                ]);
            }

            $paymentMethod = PaymentMethods::where('status', true)->where('type', PaymentMethods::TYPE_VODACOMMPESA)->first();

            if ($paymentMethod) {
                $credentials = json_decode($paymentMethod->options);

                try {

                    $credentialsParams = [
                        'url'                 => $credentials->payment_url,
                        'apiKey'              => $credentials->apiKey,             // API Key
                        'publicKey'           => $credentials->publicKey,          // Public Key
                        'serviceProviderCode' => $credentials->serviceProviderCode, // Service Provider Code
                    ];

                    $transaction_id = str_random(10);

                    $paymentData = [
                        'from'        => $request->phone,  // Customer MSISDN
                        'reference'   => $plan->uid,  // Third Party Reference
                        'transaction' => $transaction_id,  // Transaction Reference
                        'amount'      => $plan->price,
                    ];

                    $MPesa    = new MPesa($credentialsParams, $paymentData);
                    $response = $MPesa->submit();
                    $result   = json_decode($response, true);

                    if (is_array($result) && array_key_exists('output_ResponseCode', $result) && array_key_exists('output_ResponseDesc', $result)) {
                        if ($result['output_ResponseCode'] == 'INS-0') {

                            $country      = Country::where('name', $user->customer->country)->first();
                            $price        = $plan->price; // $price
                            $taxAmount    = 0;
                            $total_amount = $price;

                            if ($country) {
                                $taxRate = AppConfig::getTaxByCountry($country);
                                if ($taxRate > 0) {
                                    $taxAmount    = ($price * $taxRate) / 100;
                                    $total_amount = $price + $taxAmount;
                                }
                            }

                            $invoice = Invoices::create([
                                'user_id'        => $user->id,
                                'currency_id'    => $plan->currency_id,
                                'payment_method' => $paymentMethod->id,
                                'amount'         => $price,
                                'tax'            => $taxAmount,
                                'total_amount'   => $total_amount,
                                'type'           => Invoices::TYPE_SUBSCRIPTION,
                                'description'    => __('locale.subscription.payment_for_plan') . ' ' . $plan->name,
                                'transaction_id' => $result['output_TransactionID'],
                                'status'         => Invoices::STATUS_PAID,
                            ]);

                            if ($invoice) {

                                $subscription                         = new Subscription();
                                $subscription->user_id                = $user->id;
                                $subscription->start_at               = Carbon::now();
                                $subscription->status                 = Subscription::STATUS_ACTIVE;
                                $subscription->plan_id                = $plan->getBillableId();
                                $subscription->end_period_last_days   = '10';
                                $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
                                $subscription->end_at                 = null;
                                $subscription->end_by                 = null;
                                $subscription->payment_method_id      = $paymentMethod->id;
                                $subscription->save();

                                // add transaction
                                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                                    'end_at'                 => $subscription->end_at,
                                    'current_period_ends_at' => $subscription->current_period_ends_at,
                                    'status'                 => SubscriptionTransaction::STATUS_SUCCESS,
                                    'title'                  => trans('locale.subscription.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
                                    'amount'                 => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                // add log
                                $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_ASSIGNED, [
                                    'plan'  => $subscription->plan->getBillableName(),
                                    'price' => $subscription->plan->getBillableFormattedPrice(),
                                ]);

                                $user->sms_unit          = $plan->getOption('sms_max');
                                $user->email_verified_at = Carbon::now();
                                $user->save();

                                $this->sendWelcomeEmail($user);

                                //Add default Sender id
                                $this->planSenderID($plan, $user);

                                return redirect()->route('user.home')->with([
                                    'status'  => 'success',
                                    'message' => __('locale.payment_gateways.payment_successfully_made'),
                                ]);
                            }
                        }

                        return redirect()->route('register')->with([
                            'status'  => 'error',
                            'message' => $result['output_ResponseDesc'],
                        ]);

                    }

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => __('locale.exceptions.invalid_action'),
                    ]);

                } catch (Exception $exception) {

                    $user->delete();

                    return redirect()->route('register')->with([
                        'status'  => 'error',
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $user->delete();

            return redirect()->route('register')->with([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
            ]);
        }

        public function pricing()
        {
            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => Auth::user()->displayName()],
                ['name' => __('locale.plans.pricing')],
            ];

            $subscription = Auth::user()->customer->activeSubscription();

            if ($subscription) {
                return view('customer.Accounts.pricing', [
                    'breadcrumbs'  => $breadcrumbs,
                    'subscription' => $subscription,
                    'plan'         => $subscription->plan,
                ]);
            } else if (isset(Auth::user()->customer->subscription) && Auth::user()->customer->subscription->status == 'new') {

                $subscription = Auth::user()->customer->subscription;

                return view('customer.Accounts.index', [
                    'breadcrumbs'  => $breadcrumbs,
                    'subscription' => $subscription,
                    'plan'         => $subscription->plan,
                ]);
            }

            $plans = Plan::where('status', 1)->where('show_in_customer', 1)->cursor();

            return view('customer.Accounts.plan', compact('breadcrumbs', 'plans'));
        }

        #[NoReturn]
        public function searchPricing(Request $request)
        {
            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'name',
                4 => 'iso_code',
                5 => 'country_code',
                6 => 'actions',
            ];

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            $user       = Auth::user();
            $customerId = $user->id;

            $query = CustomerBasedPricingPlan::where('user_id', $customerId);

            if ( ! $query->exists()) {
                $byPlan = 'yes';
                $planId = $user->customer->activeSubscription()->plan_id;
                $query  = PlansCoverageCountries::where('plan_id', $planId);
            } else {
                $byPlan = 'no';
            }

            $totalFiltered = $query->count();

            if ( ! empty($request->input('search.value'))) {
                $search = $request->input('search.value');
                $query->whereLike(['uid', 'country.name', 'country.iso_code', 'country.country_code'], $search);
                $totalFiltered = $query->count();
            }

            $countries = $query->with('country')
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir)
                ->get();

            $data = [];

            foreach ($countries as $country) {
                $nestedData['responsive_id'] = '';
                $nestedData['uid']           = $country->uid;
                $nestedData['name']          = $country->country->name;
                $nestedData['country_code']  = $country->country->country_code;
                $nestedData['iso_code']      = $country->country->iso_code;
                $nestedData['by_plan']       = $byPlan;
                $data[]                      = $nestedData;
            }

            $json_data = [
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalFiltered,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            return response()->json($json_data);

        }

        public function viewPricing(Request $request)
        {
            if ($request->dataType == 'plan') {
                $pricing = PlansCoverageCountries::findByUid($request->uid);
            } else {
                $pricing = CustomerBasedPricingPlan::where('user_id', Auth::user()->id)->where('uid', $request->uid)->first();
            }

            return response()->json([
                'status' => 'success',
                'data'   => $pricing->options,
            ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Version 3.9
    |--------------------------------------------------------------------------
    |
    |Announcements
    |
    */

        public function announcement()
        {
            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => Auth::user()->displayName()],
                ['name' => __('locale.menu.Announcements')],
            ];


            return view('auth.profile._announcements', compact('breadcrumbs'));
        }

        #[NoReturn]
        public function searchAnnouncement(Request $request)
        {
            $columns = [
                0 => 'responsive_id',
                1 => 'uid',
                2 => 'uid',
                3 => 'date',
                4 => 'title',
                5 => 'actions',
            ];


            $user = Auth::user();

            $totalData = $user->announcements()->count();

            $totalFiltered = $totalData;

            $limit = $request->input('length');
            $start = $request->input('start');
            $order = $columns[$request->input('order.0.column')];
            $dir   = $request->input('order.0.dir');

            if (empty($request->input('search.value'))) {
                $announcements = $user->announcements()->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();
            } else {
                $search = $request->input('search.value');

                $announcements = $user->announcements()->whereLike(['uid', 'title'], $search)
                    ->offset($start)
                    ->limit($limit)
                    ->orderBy($order, $dir)
                    ->get();

                $totalFiltered = $user->announcements()->whereLike(['uid', 'title'], $search)->count();
            }

            $data = [];
            if ( ! empty($announcements)) {
                foreach ($announcements as $announcement) {
                    $show = route('user.account.announcement.view', $announcement->uid);

                    $isRead = Auth::user()->announcements->find($announcement->id)->pivot->read_at !== null;
                    if ($isRead) {
                        $tittle = '<a href="' . $show . '"><del>' . $announcement->title . '</del></a>';
                    } else {
                        $tittle = '<a href="' . $show . '"><b>' . $announcement->title . '</b></a>';
                    }

                    $nestedData['responsive_id'] = '';
                    $nestedData['uid']           = $announcement->uid;
                    $nestedData['created_at']    = Tool::formatHumanTime($announcement->created_at);
                    $nestedData['title']         = $tittle;
                    $nestedData['edit']          = $show;
                    $data[]                      = $nestedData;

                }
            }

            $json_data = [
                'draw'            => intval($request->input('draw')),
                'recordsTotal'    => $totalData,
                'recordsFiltered' => $totalFiltered,
                'data'            => $data,
            ];

            echo json_encode($json_data);
            exit();

        }


        public function viewAnnouncement(Announcements $announcement)
        {
            $breadcrumbs = [
                ['link' => url('dashboard'), 'name' => __('locale.menu.Dashboard')],
                ['link' => url('dashboard'), 'name' => Auth::user()->displayName()],
                ['name' => __('locale.menu.Announcements')],
            ];

            return view('auth.profile._view_announcement', compact('announcement', 'breadcrumbs'));
        }

        public function markAsRead(Request $request)
        {

            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $announcement = Announcements::findByUid($request->uid);
            // Mark the announcement as read for a specific user
            $data = $announcement->users()->updateExistingPivot(Auth::user()->id, ['read_at' => now()]);
            if ($data) {
                return response()->json([
                    'status'  => 'success',
                    'message' => 'Announcement was successfully marked as read',
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => 'Announcement was not marked as read',
            ]);
        }


        public function batchActionAnnouncement(Request $request)
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            $action = $request->get('action');
            $ids    = $request->get('ids');

            if ($action == 'mark_as_read' && count($ids) > 0) {
                Announcements::whereIn('uid', $ids)->each(function ($announcement) {
                    $announcement->users()->updateExistingPivot(Auth::user()->id, ['read_at' => now()]);
                });

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Announcement was successfully marked as read',
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.labels.at_least_one_data'),
            ]);

        }

        public function markAllAsRead()
        {
            if (config('app.stage') == 'demo') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }

            Announcements::where('user_id', Auth::user()->id)->each(function ($announcement) {
                $announcement->users()->updateExistingPivot(Auth::user()->id, ['read_at' => now()]);
            });

            return response()->json(['success' => true]);
        }


        public function getUnits(Request $request)
        {
            $amount = $request->input('amount');

            if ( ! is_numeric($amount) || $amount <= 0) {
                return response()->json(['error' => 'Invalid amount']);
            }

            $unitInfo = PlanSendingCreditPrice::where('unit_from', '<=', $amount)->where('unit_to', '>=', $amount)->where('plan_id', Auth::user()->customer->activeSubscription()->plan_id)
                ->first();

            if ( ! $unitInfo) {
                return response()->json(['error' => 'Invalid amount']);
            }

            $units = round($amount / $unitInfo->per_credit_cost);

            return response()->json(['units' => $units]);
        }

        public function dltEntityId(Request $request)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->withInput(['tab' => 'dlt_entity_id'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            Auth::user()->update([
                'dlt_entity_id' => $request->dlt_entity_id,
            ]);

            return redirect()->route('user.account')->withInput(['tab' => 'dlt_entity_id'])->with([
                'status'  => 'success',
                'message' => 'DLT Entity ID was successfully updated',
            ]);
        }


        public function dltTelemarketerId(Request $request)
        {
            if (config('app.stage') == 'demo') {
                return redirect()->route('user.account')->withInput(['tab' => 'dlt_telemarketer_id'])->with([
                    'status'  => 'error',
                    'message' => 'Sorry! This option is not available in demo mode',
                ]);
            }


            Auth::user()->update([
                'dlt_telemarketer_id' => $request->dlt_telemarketer_id,
            ]);

            return redirect()->route('user.account')->withInput(['tab' => 'dlt_telemarketer_id'])->with([
                'status'  => 'success',
                'message' => 'DLT Telemarketer ID was successfully updated',
            ]);
        }


        /*Version 3.13.0*/


        /**
         * @throws AuthorizationException
         */
        public function impersonate(User $user): mixed
        {
            return $this->account->impersonate($user);

        }

    }
