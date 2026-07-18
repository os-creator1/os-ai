<?php

    namespace App\Repositories\Eloquent;

    use App\Exceptions\GeneralException;
    use App\Helpers\Helper;
    use App\Library\aamarPay;
    use App\Library\Business\OnboardingManager;
    use App\Library\CoinPayments;
    use App\Library\Flutterwave;
    use App\Library\LiqPay;
    use App\Library\MPGS;
    use App\Library\NowPaymentsAPI;
    use App\Library\OrangeMoney;
    use App\Library\PayHereLK;
    use App\Library\PayTech;
    use App\Library\PayU;
    use App\Library\PayUMoney;
    use App\Library\TwoCheckout;
    use App\Models\AppConfig;
    use App\Models\Country;
    use App\Models\Customer;
    use App\Models\Language;
    use App\Models\Notifications;
    use App\Models\PaymentMethods;
    use App\Models\User;
    use App\Notifications\TwoFactorCode;
    use App\Repositories\Contracts\AccountRepository;
    use App\Repositories\Contracts\UserRepository;
    use Braintree\Gateway;
    use Carbon\Carbon;
    use Exception;
    use GuzzleHttp\Exception\GuzzleException;
    use Illuminate\Contracts\Auth\Authenticatable;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Support\Arr;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Session;
    use Log;
    use MercadoPago\Client\Preference\PreferenceClient;
    use MercadoPago\Exceptions\MPApiException;
    use MercadoPago\MercadoPagoConfig;
    use Mollie\Api\MollieApiClient;
    use MyFatoorah\Library\API\Payment\MyFatoorahPayment;
    use MyFatoorah\Library\MyFatoorah;
    use Paynow\Http\ConnectionException;
    use Paynow\Payments\HashMismatchException;
    use Paynow\Payments\InvalidIntegrationException;
    use Paynow\Payments\Paynow;
    use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
    use PaypalServerSdkLib\Environment;
    use PaypalServerSdkLib\Logging\LoggingConfigurationBuilder;
    use PaypalServerSdkLib\Logging\RequestLoggingConfigurationBuilder;
    use PaypalServerSdkLib\Logging\ResponseLoggingConfigurationBuilder;
    use PaypalServerSdkLib\Models\Builders\AmountBreakdownBuilder;
    use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
    use PaypalServerSdkLib\Models\Builders\ItemBuilder;
    use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
    use PaypalServerSdkLib\Models\Builders\OrderApplicationContextBuilder;
    use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
    use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
    use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
    use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
    use Psr\Log\LogLevel;
    use Psr\SimpleCache\InvalidArgumentException;
    use Razorpay\Api\Api;
    use Razorpay\Api\Errors\BadRequestError;
    use Selcom\ApigwClient\Client;
    use SimpleXMLElement;
    use Str;
    use Stripe\Stripe;
    use Stripe\TaxRate;
    use Throwable;

    /**
     * Class EloquentAccountRepository.
     */
    class EloquentAccountRepository extends EloquentBaseRepository implements AccountRepository
    {
        protected UserRepository $users;

        /**
         * EloquentUserRepository constructor.
         *
         *
         * @internal param \Illuminate\Contracts\Config\Repository $config
         */
        public function __construct(User $user, UserRepository $users)
        {
            parent::__construct($user);
            $this->users = $users;
        }

        /**
         * @throws Exception
         * @throws Throwable
         */
        public function register(array $input): User
        {
            // Registration is not enabled
            if ( ! config('account.can_register')) {
                throw new GeneralException(__('locale.exceptions.registration_disabled'));
            }

            $user = $this->users->store([
                'first_name'  => $input['first_name'],
                'last_name'   => $input['last_name'],
                'email'       => $input['email'],
                'password'    => $input['password'],
                'status'      => true,
                'phone'       => null,
                'is_customer' => true,
            ], true);

            $customer = Customer::where('user_id', $user->id)->first();
            if ($customer) {
                $customer->phone    = $input['phone'];
                $customer->address  = $input['address'];
                $customer->company  = $input['company'];
                $customer->city     = $input['city'];
                $customer->postcode = $input['postcode'];
                $customer->country  = $input['country'];
                $customer->save();

                if (config('business.onboarding.enabled') && config('business.onboarding.require_for_new_customers')) {
                    app(OnboardingManager::class)->start($customer, required: true);
                }
            }

            Notifications::create([
                'user_id'           => 1,
                'notification_for'  => 'admin',
                'notification_type' => 'user',
                'message'           => $user->displayName() . ' Registered',
            ]);

            Auth::login($user, true);

            return $user;
        }

        /**
         * get user data
         *
         *
         * @throws GeneralException
         */
        public function findOrCreateSocial($provider, $data): User
        {
            // Email can be not provided, so set default provider email.
            $user_email = $data->getEmail() ?: $data->getId() . '@' . $provider . '.com';

            // Get user with this email or create new one.
            /** @var User $user */
            $user = $this->users->query()->whereEmail($user_email)->first();

            if ( ! $user) {
                // Registration is not enabled
                if ( ! config('account.can_register')) {
                    throw new GeneralException(__('locale.exceptions.registration_disabled'));
                }

                $last_name = null;

                if ($data->getName()) {
                    $first_name = $data->getName();
                    $last_name  = $data->getNickname();
                } else {
                    $first_name = $data->getNickname();
                }

                $user = $this->users->store([
                    'first_name'    => $first_name,
                    'last_name'     => $last_name,
                    'email'         => $user_email,
                    'status'        => true,
                    'phone'         => null,
                    'is_customer'   => true,
                    'active_portal' => 'customer',
                ], true);

            }
            if ($user) {
                $user->provider    = $provider;
                $user->provider_id = $data->getId();
                $user->image       = $data->getAvatar();
                $user->save();
            }

            Notifications::create([
                'user_id'           => 1,
                'notification_for'  => 'admin',
                'notification_type' => 'user',
                'message'           => $user->displayName() . ' Registered',
            ]);

            Auth::login($user, true);

            return $user;

        }

        public function hasPermission(Authenticatable $user, $name): bool
        {

            /** @var User $user */
            // First user is always super admin and cannot be deleted
            if ($user->id === 1) {
                return true;
            }

            $permissions = Session::get('permissions');

            if ($permissions == null && $user->is_customer) {
                $permissions = collect(json_decode($user->customer->permissions, true));
            }

            if ($permissions == null && $user->is_admin) {
                $permissions = $user->getPermissions();
            }

            if ($permissions->isEmpty()) {
                return false;
            }

            return $permissions->contains($name);
        }

        /**
         * @throws GeneralException
         */
        public function delete(): bool
        {
            /** @var User $user */
            $user = auth()->user();

            if ($user->is_super_admin) {
                throw new GeneralException(__('locale.exceptions.first_user_cannot_be_destroyed'));
            }

            if ( ! $user->update(['status' => false])) {
                throw new GeneralException(__('locale.exceptions.delete'));
            }

            return true;
        }

        public function update(array $input): JsonResponse
        {

            $availLocale = Session::get('availableLocale');

            if ( ! isset($availLocale)) {
                $availLocale = Language::where('status', 1)->select('code')->cursor()->map(function ($name) {
                    return $name->code;
                })->toArray();

                Session::put('availableLocale', $availLocale);
            }

            // check for existing language
            if (in_array($input['locale'], $availLocale)) {
                Session::put('locale', $input['locale']);
            }

            /** @var User $user */
            $user = auth()->user();
            $user->fill(Arr::only($input, ['first_name', 'last_name', 'email', 'locale', 'timezone', 'password']));
            $user->save();

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.customer.profile_was_successfully_updated'),
            ]);
        }


        /**
         * Redirects the user after successful login, handling two-factor authentication and portal assignment.
         *
         * @param Authenticatable $user The authenticated user instance.
         * @return Authenticatable
         * @throws GeneralException If there's an issue saving the user or an unexpected error occurs.
         */
        public function redirectAfterLogin(Authenticatable $user): Authenticatable
        {
            // Type hinting for better IDE support, assuming Authenticatable is your User model
            if ( ! $user instanceof User) {
                throw new \InvalidArgumentException('The provided user is not an instance of ' . User::class);
            }

            // Check conditions for immediate login completion
            $skipTwoFactor = config('app.two_factor') === false || $user->two_factor === 0 || Session::get('two-factor-login-success') === 'success' || config('app.stage') === 'demo';

            if ($skipTwoFactor) {
                $user->last_access_at = Carbon::now();

                if ($user->is_admin) { // Boolean comparison is often implicit
                    $user->active_portal = 'admin';
                    session(['permissions' => $user->getPermissions()]);
                } else {
                    $user->active_portal = 'customer';
                    // Using a null-safe operator or explicit check for customer relation to prevent errors
                    $permissions = collect($user->customer?->permissions ? json_decode($user->customer->permissions, true) : []);
                    session(['permissions' => $permissions]);
                }

                if ( ! $user->save()) {
                    throw new GeneralException('Something went wrong. Please try again.');
                }

                return $user;
            }

            // If two-factor is enabled and conditions are met, generate and send the code
            // The previous 'if' condition already handles the 'demo' stage, so no need to re-check config('app.stage') != 'demo'
            if (config('app.two_factor') && $user->two_factor) {
                try {
                    $user->generateTwoFactorCode();
                    $user->notify(new TwoFactorCode());
                } catch (Throwable $e) {
                    // Log the error and potentially re-throw a more specific exception or GeneralException
                    Log::error('Failed to generate or send two-factor code for user ' . $user->id . ': ' . $e->getMessage());
                    throw new GeneralException('Could not send two-factor authentication code. Please try again later.');
                }
            }

            return $user;
        }

        /**
         * @throws Exception
         * @throws InvalidArgumentException
         * @throws GuzzleException
         */
        public function payPayment(array $input): JsonResponse
        {
            $paymentMethod = PaymentMethods::where('status', true)->where('type', $input['payment_methods'])->first();

            if ($paymentMethod) {
                $credentials = json_decode($paymentMethod->options);

                $item_name     = __('locale.auth.top_up_sms_unit');
                $price         = $input['balance'];
                $sms_unit      = $input['sms_unit'];
                $currency_code = auth()->user()->customer->subscription->plan->currency->code;

                $country   = Country::where('name', $input['country'])->first();
                $taxAmount = 0;
                $taxRate   = 0;

                $name = $input['first_name'];
                if (isset($input['last_name'])) {
                    $name .= ' ' . $input['last_name'];
                }

                if ($country) {
                    $taxRate = AppConfig::getTaxByCountry($country);
                    if ($taxRate > 0) {
                        $taxAmount = ($price * $taxRate) / 100;
                    }
                }

                $totalAmount = $price + $taxAmount;

                Session::put('payment_method', $paymentMethod->type);
                Session::put('price', $price);
                Session::put('total_amount', $totalAmount);
                Session::put('sms_unit', $sms_unit);
                Session::put('tax_amount', $taxAmount);


                switch ($paymentMethod->type) {

                    case PaymentMethods::TYPE_PAYPAL:

// Create the order request
                        $orderRequest = OrderRequestBuilder::init(
                            CheckoutPaymentIntent::CAPTURE,
                            [
                                PurchaseUnitRequestBuilder::init(
                                    AmountWithBreakdownBuilder::init(
                                        $currency_code,
                                        $totalAmount
                                    )->breakdown(
                                        AmountBreakdownBuilder::init()
                                            ->itemTotal(
                                                MoneyBuilder::init($currency_code, $price)->build() // ✅ Corrected here
                                            )
                                            ->taxTotal(
                                                MoneyBuilder::init($currency_code, $taxAmount)->build() // ✅ And here
                                            )
                                            ->build()
                                    )->build()
                                )->referenceId(auth()->user()->id . '_' . $sms_unit)
                                    ->description($item_name)
                                    ->items([
                                        ItemBuilder::init(
                                            $item_name,
                                            MoneyBuilder::init($currency_code, $price)->build(),
                                            1
                                        )
                                            ->description($item_name)
                                            ->sku(auth()->user()->id . '_' . $sms_unit)
                                            ->tax(
                                                MoneyBuilder::init($currency_code, $taxAmount)->build() // ✅ Tax amount fixed here
                                            )
                                            ->category('DIGITAL_GOODS')
                                            ->build(),
                                    ])
                                    ->build(),
                            ]
                        )->applicationContext(
                            OrderApplicationContextBuilder::init()
                                ->cancelUrl(route('customer.top_up.payment_cancel'))
                                ->returnUrl(route('customer.top_up.payment_success', [
                                    'user_id'        => auth()->user()->id,
                                    'sms_unit'       => $sms_unit,
                                    'price'          => $price,
                                    'total_amount'   => $totalAmount,
                                    'tax_amount'     => $taxAmount,
                                    'payment_method' => $paymentMethod->type,
                                ]))
                                ->brandName(config('app.name'))
                                ->locale(config('app.locale'))
                                ->shippingPreference("NO_SHIPPING")
                                ->userAction("PAY_NOW")
                                ->build()
                        )->build();
// Build the PayPal client

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

// ✅ Call getOrdersController() on the PaypalServerSdkClient instance

                        try {

                            $response = $client->getOrdersController()->ordersCreate([
                                'body'   => $orderRequest,
                                'prefer' => 'return=minimal',
                            ]);


                            if ($response->isError()) {

                                $description = data_get($response->getResult(), 'details.0.description', 'No description available');

                                return response()->json([
                                    'status'  => 'error',
                                    'message' => $description,
                                ]);
                            }

                            $redirect_url = null;

                            if ($response->isSuccess() && ! empty($response->getResult()->getId()) && ! empty($response->getResult()->getLinks())) {
                                foreach ($response->getResult()->getLinks() as $link) {
                                    if ($link->getRel() == 'approve') {
                                        $redirect_url = $link->getHref();
                                        break;
                                    }
                                }
                            }

                            if (isset($redirect_url)) {
                                if ( ! empty($response->getResult()->getId())) {
                                    Session::put('paypal_payment_id', $response->getResult()->getId());
                                }

                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $redirect_url,
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);
                        } catch (Exception $exception) {

                            return response()->json([
                                'status'  => 'error',
                                'message' => $exception->getMessage(),
                            ]);
                        }

                    case PaymentMethods::TYPE_BRAINTREE:

                        try {
                            $gateway = new Gateway([
                                'environment' => $credentials->environment,
                                'merchantId'  => $credentials->merchant_id,
                                'publicKey'   => $credentials->public_key,
                                'privateKey'  => $credentials->private_key,
                            ]);

                            $clientToken = $gateway->clientToken()->generate();

                            return response()->json([
                                'status' => 'success',
                                'token'  => $clientToken,
                            ]);
                        } catch (Exception $exception) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $exception->getMessage(),
                            ]);
                        }

                    case PaymentMethods::TYPE_STRIPE:
                        $publishable_key = $credentials->publishable_key;
                        $secret_key      = $credentials->secret_key;

                        Stripe::setApiKey($secret_key);

                        try {
                            $taxRateData = null;
                            if ( ! empty($taxRate)) {
                                $taxRateData = TaxRate::create([
                                    'display_name' => 'VAT',
                                    'description'  => 'VAT',
                                    'percentage'   => $taxRate,
                                    'inclusive'    => false,
                                    'country'      => $country->iso_code,
                                    'jurisdiction' => $country->iso_code,
                                ]);
                            }

                            $checkout_session = \Stripe\Checkout\Session::create([
                                'payment_method_types' => ['card'],
                                'customer_email'       => $input['email'],
                                'line_items'           => [
                                    [
                                        'price_data' => [
                                            'currency'     => $currency_code,
                                            'unit_amount'  => Helper::getStripeAmount($price, $currency_code),
                                            'product_data' => [
                                                'name' => $item_name,
                                            ],
                                        ],
                                        'quantity'   => 1,
                                        'tax_rates'  => $taxRateData ? [$taxRateData->id] : [],
                                    ],
                                ],
                                'mode'                 => 'payment',
                                'success_url'          => route('customer.top_up.payment_success', [
                                    'user_id'        => auth()->user()->id,
                                    'sms_unit'       => $sms_unit,
                                    'price'          => $price,
                                    'tax_amount'     => $taxAmount,
                                    'payment_method' => $paymentMethod->type,
                                ]),
                                'cancel_url'           => route('customer.top_up.payment_cancel'),
                            ]);

                            if ( ! empty($checkout_session->id)) {
                                Session::put('payment_method', $paymentMethod->type);
                                Session::put('session_id', $checkout_session->id);
                            }

                            return response()->json([
                                'status'          => 'success',
                                'session_id'      => $checkout_session->id,
                                'publishable_key' => $publishable_key,
                            ]);

                        } catch (Exception $exception) {

                            return response()->json([
                                'status'  => 'error',
                                'message' => $exception->getMessage(),
                            ]);

                        }

                    case PaymentMethods::TYPE_AUTHORIZE_NET:
                    case PaymentMethods::TYPE_VODACOMMPESA:
                        return response()->json([
                            'status'      => 'success',
                            'credentials' => $credentials,
                        ]);

                    case PaymentMethods::TYPE_2CHECKOUT:

                        $checkout = new TwoCheckout();

                        $checkout->param('sid', $credentials->merchant_code);
                        if ($credentials->environment == 'sandbox') {
                            $checkout->param('demo', 'Y');
                        }
                        $checkout->param('return_url', route('customer.top_up.payment_success', [
                            'user_id'        => auth()->user()->id,
                            'sms_unit'       => $sms_unit,
                            'price'          => $price,
                            'tax_amount'     => $taxAmount,
                            'payment_method' => $paymentMethod->type,
                        ]));
                        $checkout->param('li_0_name', $item_name);
                        $checkout->param('li_0_price', $totalAmount);
                        $checkout->param('li_0_quantity', 1);
                        $checkout->param('card_holder_name', $input['first_name'] . ' ' . $input['last_name']);
                        $checkout->param('city', $input['city']);
                        $checkout->param('country', $input['country']);
                        $checkout->param('email', $input['email']);
                        $checkout->param('phone', $input['phone']);
                        $checkout->param('currency_code', $currency_code);
                        $checkout->gw_submit();
                        exit();

                    case PaymentMethods::TYPE_PAYSTACK:

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => 'https://api.paystack.co/transaction/initialize',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => json_encode([
                                'amount'   => round($totalAmount) * 100,
                                'email'    => $input['email'],
                                'metadata' => [
                                    'sms_unit'     => $sms_unit,
                                    'price'        => $price,
                                    'tax_amount'   => $taxAmount,
                                    'user_id'      => auth()->user()->id,
                                    'request_type' => 'top_up_payment',
                                ],
                            ]),
                            CURLOPT_HTTPHEADER     => [
                                'authorization: Bearer ' . $credentials->secret_key,
                                'content-type: application/json',
                                'cache-control: no-cache',
                            ],
                        ]);

                        $response = curl_exec($curl);
                        $err      = curl_error($curl);

                        curl_close($curl);

                        if ($response === false) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => 'Php curl show false value. Please contact with your provider',
                            ]);
                        }

                        if ($err) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $err,
                            ]);
                        }

                        $result = json_decode($response);

                        if ($result->status != 1) {

                            return response()->json([
                                'status'  => 'error',
                                'message' => $result->message,
                            ]);
                        }

                        return response()->json([
                            'status'       => 'success',
                            'redirect_url' => $result->data->authorization_url,
                        ]);

                    case PaymentMethods::TYPE_PAYU:

                        $signature = "$credentials->client_secret~$credentials->client_id~smsunit$sms_unit~$price~$currency_code";
                        $signature = md5($signature);

                        $payu = new PayU();

                        $payu->param('merchantId', $credentials->client_id);
                        $payu->param('ApiKey', $credentials->client_secret);
                        $payu->param('referenceCode', 'smsunit' . $sms_unit);
                        $payu->param('description', $item_name);
                        $payu->param('amount', $totalAmount);
                        $payu->param('currency', $currency_code);
                        $payu->param('buyerEmail', $input['email']);
                        $payu->param('signature', $signature);
                        $payu->param('confirmationUrl', route('customer.top_up.payment_success', [
                            'user_id'        => auth()->user()->id,
                            'sms_unit'       => $sms_unit,
                            'price'          => $price,
                            'tax_amount'     => $taxAmount,
                            'payment_method' => $paymentMethod->type,
                        ]));
                        $payu->param('responseUrl', route('customer.top_up.payment_cancel'));
                        $payu->gw_submit();

                        exit();

                    case PaymentMethods::TYPE_PAYNOW:

                        $paynow = new Paynow(
                            $credentials->integration_id,
                            $credentials->integration_key,
                            route('customer.callback.paynow'),
                            route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ])
                        );

                        $payment = $paynow->createPayment($sms_unit, $input['email']);
                        $payment->add($item_name, $totalAmount);

                        try {
                            $response = $paynow->send($payment);

                            if ($response->success()) {

                                Session::put('payment_method', $paymentMethod->type);
                                Session::put('paynow_poll_url', $response->pollUrl());

                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $response->redirectUrl(),
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        } catch (ConnectionException|HashMismatchException|InvalidIntegrationException|Exception $e) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $e->getMessage(),
                            ]);
                        }

                    case PaymentMethods::TYPE_COINPAYMENTS:

                        $coinPayment = new CoinPayments();

                        $order = [
                            'merchant'    => $credentials->merchant_id,
                            'item_name'   => $item_name,
                            'amountf'     => $totalAmount,
                            'currency'    => $currency_code,
                            'custom'      => 'smsunit',
                            'success_url' => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ]),
                            'cancel_url'  => route('customer.top_up.payment_cancel'),
                        ];

                        foreach ($order as $item => $value) {
                            $coinPayment->param($item, $value);
                        }

                        $coinPayment->gw_submit();

                        exit();

                    case PaymentMethods::TYPE_INSTAMOJO:

                        $payload = [
                            'purpose'                 => $item_name,
                            'amount'                  => $totalAmount,
                            'phone'                   => $input['phone'],
                            'buyer_name'              => $name,
                            'redirect_url'            => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ]),
                            'send_email'              => true,
                            'email'                   => $input['email'],
                            'allow_repeated_payments' => false,
                        ];

                        $headers = [
                            'X-Api-Key:' . $credentials->api_key,
                            'X-Auth-Token:' . $credentials->auth_token,
                        ];

                        $ch = curl_init();

                        curl_setopt($ch, CURLOPT_URL, 'https://www.instamojo.com/api/1.1/payment-requests/');
                        curl_setopt($ch, CURLOPT_HEADER, false);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                        $response = curl_exec($ch);
                        curl_close($ch);

                        if (isset($response->success)) {
                            if ($response->success === true) {

                                Session::put('payment_request_id', $response->payment_request->id);

                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $response->payment_request->longurl,
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => $response->message,
                            ]);

                        }

                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    case PaymentMethods::TYPE_PAYUMONEY:

                        $environment = $credentials->environment;
                        $txnid       = substr(hash('sha256', mt_rand() . microtime()), 0, 20);
                        $pinfo       = $item_name;
                        $hash        = strtolower(hash('sha512', $credentials->merchant_key . '|' . $txnid . '|' . $totalAmount . '|' . $pinfo . '|' . $input['first_name'] . '|' . $input['email'] . '||||||||||||' . $credentials->merchant_salt));

                        $payumoney = new PayUMoney($environment);

                        $payumoney->param('key', $credentials->merchant_key);
                        $payumoney->param('amount', $totalAmount);
                        $payumoney->param('hash', $hash);
                        $payumoney->param('txnid', $txnid);
                        $payumoney->param('firstname', $input['first_name']);
                        $payumoney->param('email', $input['email']);
                        $payumoney->param('phone', $input['phone']);
                        $payumoney->param('productinfo', $pinfo);
                        $payumoney->param('surl', route('customer.top_up.payment_success', [
                            'user_id'        => auth()->user()->id,
                            'sms_unit'       => $sms_unit,
                            'price'          => $price,
                            'tax_amount'     => $taxAmount,
                            'payment_method' => $paymentMethod->type,
                        ]));
                        $payumoney->param('furl', route('customer.top_up.payment_cancel'));

                        if (isset($input['last_name'])) {
                            $payumoney->param('lastname', $input['last_name']);
                        }

                        if (isset($input['address'])) {
                            $payumoney->param('address1', $input['address']);
                        }

                        if (isset($input['city'])) {
                            $payumoney->param('city', $input['city']);
                        }
                        if (isset($input['country'])) {
                            $payumoney->param('country', $input['country']);
                        }

                        $payumoney->gw_submit();

                        exit();

                    case PaymentMethods::TYPE_RAZORPAY:

                        try {
                            $api = new Api($credentials->key_id, $credentials->key_secret);


                            $link = $api->paymentLink->create([
                                'amount'          => $totalAmount * 100,
                                'currency'        => $currency_code,
                                'expire_by'       => time() + (60 * 60 * 24),
                                'reference_id'    => uniqid(),
                                'description'     => $item_name,
                                'customer'        => [
                                    'name'    => $name,
                                    'email'   => $input['email'],
                                    'contact' => $input['phone'],
                                ],
                                'notify'          => ['sms' => true, 'email' => true],
                                'reminder_enable' => true,
                                'notes'           => [
                                    'sms_unit'     => $sms_unit,
                                    'price'        => $price,
                                    'tax_amount'   => $taxAmount,
                                    'user_id'      => auth()->user()->id,
                                    'request_type' => 'top_up_payment',
                                ],
                                'callback_url'    => route('customer.top_up.payment_success', [
                                    'user_id'        => auth()->user()->id,
                                    'sms_unit'       => $sms_unit,
                                    'price'          => $price,
                                    'tax_amount'     => $taxAmount,
                                    'payment_method' => $paymentMethod->type,
                                ]),
                                'callback_method' => 'get',
                            ]);

                            if (isset($link->id) && isset($link->short_url)) {

                                Session::put('razorpay_payment_link_reference_id', $link->reference_id);
                                Session::put('user_id', auth()->user()->id);

                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $link->short_url,
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        } catch (BadRequestError $exception) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $exception->getMessage(),
                            ]);
                        }

                    case PaymentMethods::TYPE_SSLCOMMERZ:

                        $post_data                 = [];
                        $post_data['store_id']     = $credentials->store_id;
                        $post_data['store_passwd'] = $credentials->store_passwd;
                        $post_data['total_amount'] = $totalAmount;
                        $post_data['currency']     = $currency_code;
                        $post_data['tran_id']      = $sms_unit;
                        $post_data['success_url']  = route('customer.callback.sslcommerz.top_up', [
                            'user_id'    => auth()->user()->id,
                            'sms_unit'   => $sms_unit,
                            'price'      => $price,
                            'tax_amount' => $taxAmount,
                        ]);
                        $post_data['fail_url']     = route('customer.callback.sslcommerz.top_up');
                        $post_data['cancel_url']   = route('customer.callback.sslcommerz.top_up');

                        $post_data['product_category'] = 'subscriptions';
                        $post_data['emi_option']       = '0';

                        $post_data['cus_name']    = $input['first_name'];
                        $post_data['cus_email']   = $input['email'];
                        $post_data['cus_add1']    = $input['address'];
                        $post_data['cus_city']    = $input['city'];
                        $post_data['cus_country'] = $input['country'];
                        $post_data['cus_phone']   = $input['phone'];

                        if (isset($input['postcode'])) {
                            $post_data['cus_postcode'] = $input['postcode'];
                        }

                        $post_data['shipping_method'] = 'No';
                        $post_data['num_of_item']     = '1';

                        $post_data['cart']            = json_encode([
                            ['product' => $item_name, 'amount' => $price],
                        ]);
                        $post_data['product_name']    = $item_name;
                        $post_data['product_profile'] = 'non-physical-goods';
                        $post_data['product_amount']  = $price;

                        if ($credentials->environment == 'sandbox') {
                            $direct_api_url = 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php';
                        } else {
                            $direct_api_url = 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';
                        }

                        $handle = curl_init();
                        curl_setopt($handle, CURLOPT_URL, $direct_api_url);
                        curl_setopt($handle, CURLOPT_TIMEOUT, 30);
                        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
                        curl_setopt($handle, CURLOPT_POST, 1);
                        curl_setopt($handle, CURLOPT_POSTFIELDS, $post_data);
                        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false); // KEEP IT FALSE IF YOU RUN FROM LOCAL PC

                        $content = curl_exec($handle);
                        $code    = curl_getinfo($handle, CURLINFO_HTTP_CODE);

                        if ($code == 200 && ! (curl_errno($handle))) {
                            curl_close($handle);
                            $response = json_decode($content, true);

                            if (isset($response['GatewayPageURL']) && $response['GatewayPageURL'] != '') {

                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $response['GatewayPageURL'],
                                ]);

                            } else {
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => $response['failedreason'],
                                ]);
                            }
                        } else {
                            curl_close($handle);

                            return response()->json([
                                'status'  => 'error',
                                'message' => 'FAILED TO CONNECT WITH SSLCOMMERZ API',
                            ]);
                        }

                    case PaymentMethods::TYPE_AAMARPAY:

                        $checkout = new aamarPay($credentials->environment);

                        $checkout->param('store_id', $credentials->store_id);
                        $checkout->param('signature_key', $credentials->signature_key);
                        $checkout->param('desc', $item_name);
                        $checkout->param('amount', $totalAmount);
                        $checkout->param('currency', $currency_code);
                        $checkout->param('tran_id', $sms_unit);
                        $checkout->param('success_url', route('customer.callback.aamarpay.top_up', [
                            'user_id'    => auth()->user()->id,
                            'sms_unit'   => $sms_unit,
                            'price'      => $price,
                            'tax_amount' => $taxAmount,
                        ]));
                        $checkout->param('fail_url', route('customer.callback.aamarpay.top_up'));
                        $checkout->param('cancel_url', route('customer.callback.aamarpay.top_up'));

                        $checkout->param('cus_name', $input['first_name']);
                        $checkout->param('cus_email', $input['email']);
                        $checkout->param('cus_add1', $input['address']);
                        $checkout->param('cus_add2', $input['address']);
                        $checkout->param('cus_city', $input['city']);
                        $checkout->param('cus_country', $input['country']);
                        $checkout->param('cus_phone', $input['phone']);
                        if (isset($input['postcode'])) {
                            $checkout->param('cus_postcode', $input['postcode']);
                        }

                        $checkout->gw_submit();
                        exit();

                    case PaymentMethods::TYPE_FLUTTERWAVE:

                        $checkout = new Flutterwave();

                        $checkout->param('public_key', $credentials->public_key);
                        $checkout->param('amount', $totalAmount);
                        $checkout->param('currency', $currency_code);
                        $checkout->param('tx_ref', str_random(10));
                        $checkout->param('redirect_url', route('customer.callback.flutterwave.top_up', [
                            'user_id'    => auth()->user()->id,
                            'sms_unit'   => $sms_unit,
                            'price'      => $price,
                            'tax_amount' => $taxAmount,
                        ]));
                        $checkout->param('customizations[title]', $item_name);
                        $checkout->param('customizations[description]', $item_name);
                        $checkout->param('customer[name]', $input['first_name'] . ' ' . $input['last_name']);
                        $checkout->param('customer[email]', $input['email']);
                        $checkout->param('customer[phone_number]', $input['phone']);
                        $checkout->param('meta[user_id]', auth()->user()->id);
                        $checkout->param('meta[sms_unit]', $sms_unit);
                        $checkout->param('meta[price]', $price);
                        $checkout->gw_submit();
                        exit();

                    case PaymentMethods::TYPE_DIRECTPAYONLINE:

                        if ($credentials->environment == 'production') {
                            $payment_url = 'https://secure.3gdirectpay.com';
                        } else {
                            $payment_url = 'https://secure1.sandbox.directpay.online';
                        }

                        $companyToken        = $credentials->company_token;
                        $accountType         = $credentials->account_type;
                        $reference           = uniqid();
                        $odate               = date('Y/m/d H:i');
                        $redirectURL         = route('customer.top_up.payment_success', [
                            'user_id'        => auth()->user()->id,
                            'sms_unit'       => $sms_unit,
                            'price'          => $price,
                            'tax_amount'     => $taxAmount,
                            'payment_method' => $paymentMethod->type,
                        ]);
                        $backURL             = route('user.home');
                        $customer_email      = auth()->user()->email;
                        $customer_first_name = auth()->user()->first_name;
                        $customer_last_name  = auth()->user()->last_name;

                        $postXml = <<<POSTXML
<?xml version="1.0" encoding="utf-8"?>
        <API3G>
        <CompanyToken>$companyToken</CompanyToken>
        <Request>createToken</Request>
        <Transaction>
        <PaymentAmount>$totalAmount</PaymentAmount>
        <PaymentCurrency>$currency_code</PaymentCurrency>
        <CompanyRef>$reference</CompanyRef>
        <customerEmail>$customer_email</customerEmail>
        <customerFirstName>$customer_first_name</customerFirstName>
        <customerLastName>$customer_last_name</customerLastName>
        <RedirectURL>$redirectURL</RedirectURL>
        <BackURL>$backURL</BackURL>
        <TransactionSource>whmcs</TransactionSource>
        </Transaction>
        <Services>
        <Service>
        <ServiceType>$accountType</ServiceType>
        <ServiceDescription>$item_name</ServiceDescription>
        <ServiceDate>$odate</ServiceDate>
        </Service>
        </Services>
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
                        $error    = curl_error($curl);

                        curl_close($curl);

                        if ($response != '') {
                            $xml = new SimpleXMLElement($response);

                            if ($xml->xpath('Result')[0] != '000') {
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => ! empty($error) ? $error : 'Unknown error occurred in token creation',
                                ]);
                            }

                            $transToken = $xml->xpath('TransToken')[0]->__toString();

                            try {
                                $curl = curl_init();
                                curl_setopt_array($curl, [
                                    CURLOPT_URL            => $payment_url . '/API/v6/',
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_ENCODING       => '',
                                    CURLOPT_MAXREDIRS      => 10,
                                    CURLOPT_TIMEOUT        => 30,
                                    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                                    CURLOPT_CUSTOMREQUEST  => 'POST',
                                    CURLOPT_POSTFIELDS     => "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n<API3G>\r\n  <CompanyToken>" . $companyToken . "</CompanyToken>\r\n  <Request>verifyToken</Request>\r\n  <TransactionToken>" . $transToken . "</TransactionToken>\r\n</API3G>",
                                    CURLOPT_HTTPHEADER     => [
                                        'cache-control: no-cache',
                                    ],
                                ]);

                                $response = curl_exec($curl);
                                $err      = curl_error($curl);

                                curl_close($curl);

                                if (strlen($err) > 0) {

                                    return response()->json([
                                        'status'  => 'error',
                                        'message' => $err,
                                    ]);
                                }

                                $verify = new SimpleXMLElement($response);
                                if ($verify->Result->__toString() === '900') {

                                    return response()->json([
                                        'status'       => 'success',
                                        'redirect_url' => $payment_url . '/payv2.php?ID=' . $transToken,
                                    ]);
                                }
                            } catch (Exception $e) {

                                return response()->json([
                                    'status'  => 'error',
                                    'message' => $e->getMessage(),
                                ]);
                            }
                        }

                        return response()->json([
                            'status'  => 'error',
                            'message' => ! empty($error) ? $error : 'Unknown error occurred in token creation',
                        ]);

                    case PaymentMethods::TYPE_SMANAGER:

                        if ($credentials->environment == 'production') {
                            $payment_url = 'https://api.sheba.xyz/v1/ecom-payment/initiate';
                        } else {
                            $payment_url = 'https://api.dev-sheba.xyz/v1/ecom-payment/initiate';
                        }

                        $client_id = $credentials->client_id;
                        $secret    = $credentials->secret;

                        $parameters = [
                            'amount'          => $totalAmount,
                            'transaction_id'  => uniqid(),
                            'success_url'     => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ]),
                            'fail_url'        => route('customer.top_up.payment_cancel'),
                            'customer_name'   => $input['first_name'] . ' ' . $input['last_name'],
                            'customer_mobile' => $input['phone'],
                            'purpose'         => $item_name,
                            'payment_details' => '',
                        ];

                        $curl = curl_init();

                        curl_setopt_array($curl, [
                            CURLOPT_URL            => $payment_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING       => '',
                            CURLOPT_MAXREDIRS      => 10,
                            CURLOPT_TIMEOUT        => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST  => 'POST',
                            CURLOPT_POSTFIELDS     => $parameters,
                            CURLOPT_HTTPHEADER     => [
                                'client-id: ' . $client_id,
                                'client-secret: ' . $secret,
                                'Accept: application/json',
                            ],
                        ]);

                        $response = curl_exec($curl);

                        curl_close($curl);

                        $get_result = json_decode($response, true);

                        if (is_array($get_result) && array_key_exists('code', $get_result)) {
                            if ($get_result['code'] == 200) {

                                Session::put('payment_method', $paymentMethod->type);

                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $get_result['data']['link'],
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => $get_result['message'],
                            ]);
                        }

                        return response()->json([
                            'status'  => 'error',
                            'message' => (string) $response,
                        ]);

                    case PaymentMethods::TYPE_PAYGATEGLOBAL:

                        $order_id = str_random(10);

                        $parameters = [
                            'token'    => $credentials->api_key,
                            'amount'   => $totalAmount,
                            'identify' => $order_id,
                            'url'      => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ]),
                        ];
                        $parameters = http_build_query($parameters);

                        Session::put('paygateglobal_order_id', $order_id);
                        Session::put('user_id', auth()->user()->id);

                        return response()->json([
                            'status'       => 'success',
                            'redirect_url' => 'https://paygateglobal.com/v1/page?' . $parameters,
                        ]);

                    case PaymentMethods::TYPE_ORANGEMONEY:

                        $payment = new OrangeMoney($credentials->auth_header, $credentials->merchant_key);

                        $data = [
                            'merchant_key' => $credentials->merchant_key,
                            'currency'     => $currency_code,
                            'order_id'     => str_random(10),
                            'amount'       => $totalAmount,
                            'payment_url'  => $credentials->payment_url,
                            'return_url'   => route('customer.top_up.payment_cancel'),
                            'cancel_url'   => route('customer.top_up.payment_cancel'),
                            'notif_url'    => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ]),
                            'lang'         => config('app.locale'),
                            'reference'    => uniqid(),
                        ];

                        $callback_data = $payment->getPaymentUrl($data);

                        if (array_key_exists('payment_url', $callback_data)) {

                            Session::put('payment_request_id', $callback_data['notif_token']);

                            return response()->json([
                                'status'       => 'success',
                                'redirect_url' => $callback_data['payment_url'],
                            ]);
                        } else if (array_key_exists('error', $callback_data)) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $callback_data['error'],
                            ]);
                        } else {
                            return response()->json([
                                'status'  => 'error',
                                'message' => 'FAILED TO CONNECT WITH OrangeMoney API',
                            ]);
                        }

                    case PaymentMethods::TYPE_CINETPAY:

                        $transaction_id = str_random(10);

                        $payment_data = [
                            'apikey'                => $credentials->api_key,
                            'site_id'               => $credentials->site_id,
                            'transaction_id'        => $transaction_id,
                            'amount'                => $totalAmount,
                            'currency'              => $currency_code,
                            'description'           => $item_name,
                            'customer_name'         => $input['first_name'] . ' ' . $input['last_name'],
                            'customer_email'        => $input['email'],
                            'customer_phone_number' => $input['phone'],
                            'customer_address'      => $input['address'],
                            'customer_city'         => $input['city'],
                            'customer_country'      => Country::getIsoCode($input['country']),
                            'return_url'            => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ]),
                            'notify_url'            => route('customer.top_up.payment_cancel'),
                            'channels'              => 'ALL',
                            'lang'                  => config('app.locale'),
                            'metadata'              => auth()->user()->id . '-' . $sms_unit . '-' . $price,
                        ];

                        if (isset($input['postcode'])) {
                            $payment_data['customer_zip_code'] = $input['postcode'];
                        }

                        try {

                            $curl = curl_init();

                            curl_setopt_array($curl, [
                                CURLOPT_URL            => $credentials->payment_url,
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
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => 'Php curl show false value. Please contact with your provider',
                                ]);
                            }

                            if ($err) {
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => $err,
                                ]);
                            }

                            $result = json_decode($response, true);

                            if (is_array($result) && array_key_exists('code', $result)) {
                                if ($result['code'] == '201') {

                                    Session::put('cinetPay_transaction_id', $transaction_id);
                                    Session::put('cinetPay_payment_token', $result['data']['payment_token']);
                                    Session::put('user_id', auth()->user()->id);

                                    return response()->json([
                                        'status'       => 'success',
                                        'redirect_url' => $result['data']['payment_url'],
                                    ]);
                                }

                                return response()->json([
                                    'status'  => 'error',
                                    'message' => $result['message'],
                                ]);
                            }

                            return response()->json([
                                'status'       => 'error',
                                'redirect_url' => __('locale.exceptions.something_went_wrong'),
                            ]);
                        } catch (Exception $ex) {

                            return response()->json([
                                'status'       => 'error',
                                'redirect_url' => $ex->getMessage(),
                            ]);
                        }

                    case PaymentMethods::TYPE_PAYHERELK:

                        $order_id = str_random(10);

                        $hash = strtoupper(
                            md5(
                                $credentials->merchant_id .
                                $order_id .
                                number_format($totalAmount, 2, '.', '') .
                                $currency_code .
                                strtoupper(md5($credentials->merchant_secret))
                            )
                        );

                        $checkout = new PayHereLK($credentials->environment);

                        $checkout->param('merchant_id', $credentials->merchant_id);
                        $checkout->param('items', $item_name);
                        $checkout->param('amount', $totalAmount);
                        $checkout->param('currency', $currency_code);
                        $checkout->param('order_id', $order_id);

                        $checkout->param('return_url', route('customer.top_up.payment_success', [
                            'user_id'        => auth()->user()->id,
                            'sms_unit'       => $sms_unit,
                            'price'          => $price,
                            'tax_amount'     => $taxAmount,
                            'payment_method' => $paymentMethod->type,
                        ]));
                        $checkout->param('cancel_url', route('customer.top_up.payment_cancel'));
                        $checkout->param('notify_url', route('customer.top_up.payment_cancel'));
                        $checkout->param('first_name', $input['first_name']);

                        if (isset($input['last_name'])) {
                            $checkout->param('last_name', $input['last_name']);
                        } else {
                            $checkout->param('last_name', $input['first_name']);
                        }

                        $checkout->param('email', $input['email']);
                        $checkout->param('address', $input['address']);
                        $checkout->param('city', $input['city']);
                        $checkout->param('country', $input['country']);
                        $checkout->param('phone', $input['phone']);
                        $checkout->param('hash', $hash);

                        $checkout->gw_submit();
                        exit();

                    case PaymentMethods::TYPE_MOLLIE:

                        $mollie = new MollieApiClient();
                        $mollie->setApiKey($credentials->api_key);
                        $payment = $mollie->payments->create([
                            'amount'      => [
                                'currency' => $currency_code,
                                'value'    => number_format((float) $totalAmount, 2, '.', ''),
                            ],
                            'description' => $item_name,
                            'redirectUrl' => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ]),
                            'metadata'    => [
                                'user_id'  => auth()->user()->id,
                                'sms_unit' => $sms_unit,
                                'price'    => $price,
                                'tax'      => $taxAmount,
                            ],
                        ]);

                        Session::put('payment_method', $paymentMethod->type);
                        Session::put('payment_id', $payment->id);

                        return response()->json([
                            'status'       => 'success',
                            'redirect_url' => $payment->getCheckoutUrl(),
                        ]);

                    /*Version 3.6*/
                    case PaymentMethods::TYPE_EASYPAY:

                        $unique_id = uniqid();

                        $body = [
                            'type'    => ['single'],
                            'payment' => [
                                'methods'         => ['cc', 'mb', 'mbw', 'dd', 'vi', 'uf', 'sc'],
                                'type'            => 'sale',
                                'capture'         => [
                                    'descriptive' => $item_name,
                                ],
                                'currency'        => $currency_code,
                                'expiration_time' => null,
                            ],
                            'order'   => [
                                'key'   => $unique_id,
                                'value' => floatval($totalAmount),
                                'items' => [
                                    [
                                        'key'         => $unique_id,
                                        'description' => $item_name,
                                        'value'       => floatval($totalAmount),
                                        'quantity'    => 1,
                                    ],
                                ],
                            ],
                        ];

                        $headers = [
                            'AccountId: ' . $credentials->account_id,
                            'ApiKey: ' . $credentials->api_key,
                            'Content-Type: application/json',
                        ];

                        $curlOpts = [
                            CURLOPT_URL            => $credentials->payment_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => 1,
                            CURLOPT_TIMEOUT        => 60,
                            CURLOPT_POSTFIELDS     => json_encode($body),
                            CURLOPT_HTTPHEADER     => $headers,
                        ];

                        $curl = curl_init();
                        curl_setopt_array($curl, $curlOpts);
                        $response = curl_exec($curl);
                        curl_close($curl);

                        return response()->json([
                            'status'   => 'success',
                            'data'     => $response,
                            'sms_unit' => $sms_unit,
                            'price'    => $price,
                        ]);

                    case PaymentMethods::TYPE_FEDAPAY:
                        return response()->json([
                            'status'     => 'success',
                            'public_key' => $credentials->public_key,
                            'price'      => $totalAmount,
                            'sms_unit'   => $sms_unit,
                            'user_id'    => auth()->user()->id,
                        ]);

                    /*Version 3.8*/
                    case PaymentMethods::TYPE_SELCOMMOBILE:
                        $unique_id = uniqid();

                        Session::put('order_id', $unique_id);

                        $orderMinArray = [
                            'vendor'                    => $credentials->vendor,
                            'order_id'                  => $unique_id,
                            'buyer_email'               => $input['email'],
                            'buyer_name'                => $input['first_name'] . ' ' . $input['last_name'],
                            'buyer_phone'               => $input['phone'],
                            'amount'                    => $totalAmount,
                            'currency'                  => $currency_code,
                            'redirect_url'              => base64_encode(route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ])),
                            'cancel_url'                => base64_encode(route('customer.top_up.payment_cancel')),
                            'webhook'                   => base64_encode(route('customer.top_up.payment_cancel')),
                            'billing.firstname'         => $input['first_name'],
                            'billing.lastname'          => $input['last_name'],
                            'billing.address_1'         => $input['address'],
                            'billing.city'              => $input['city'],
                            'billing.state_or_region'   => $input['city'],
                            'billing.postcode_or_pobox' => $input['postcode'],
                            'billing.country'           => $input['country'],
                            'billing.phone'             => $input['phone'],
                            'buyer_remarks'             => $item_name,
                            'merchant_remarks'          => $item_name,
                            'payment_methods'           => 'ALL',
                            'no_of_items'               => 1,
                        ];

                        $client = new Client($credentials->payment_url, $credentials->api_key, $credentials->api_secret);

                        // path relative to base url
                        $orderMinPath = '/checkout/create-order';

                        // create order minimal
                        try {
                            $response = $client->postFunc($orderMinPath, $orderMinArray);

                            if (isset($response) && is_array($response) && array_key_exists('data', $response) && array_key_exists('result', $response)) {
                                if ($response['result'] == 'SUCCESS') {
                                    return response()->json([
                                        'status'       => 'success',
                                        'message'      => $response['message'],
                                        'redirect_url' => base64_decode($response['data'][0]['payment_gateway_url']),
                                    ]);
                                } else {
                                    return response()->json([
                                        'status'  => 'error',
                                        'message' => $response['message'],
                                    ]);
                                }
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => $response,
                            ]);

                        } catch (Exception $exception) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $exception->getMessage(),
                            ]);
                        }

                    case PaymentMethods::TYPE_LIQPAY:
                        $order_id = uniqid();

                        $liqPay = new LiqPay();

                        $liqPay->param('public_key', $credentials->public_key);
                        $liqPay->param('amount', $totalAmount);
                        $liqPay->param('currency', $currency_code);
                        $liqPay->param('description', $item_name);
                        $liqPay->param('order_id', $order_id);
                        $liqPay->param('language', 'en');
                        $liqPay->param('sender_first_name', $input['first_name']);

                        if (isset($input['last_name'])) {
                            $liqPay->param('sender_last_name', $input['last_name']);
                        }
                        if (isset($input['postcode'])) {
                            $liqPay->param('sender_postal_code', $input['postcode']);
                        }
                        $liqPay->param('sender_address', $input['address']);
                        $liqPay->param('sender_city', $input['city']);

                        $liqPay->param('result_url', route('customer.top_up.payment_success', [
                            'user_id'        => auth()->user()->id,
                            'sms_unit'       => $sms_unit,
                            'price'          => $price,
                            'tax_amount'     => $taxAmount,
                            'payment_method' => $paymentMethod->type,
                        ]));

                        $liqPay->gw_submit();

                        exit();

                    case PaymentMethods::TYPE_PAYTECH:

                        $testMode = false;
                        if ($credentials->environment == 'sandbox') {
                            $testMode = true;
                        }

                        $response = (new PayTech($credentials->api_key, $credentials->api_secret))->setQuery([
                            'item_name'    => $item_name,
                            'item_price'   => $totalAmount,
                            'command_name' => $item_name,
                        ])->setCustomField([
                            'item_id'      => uniqid(),
                            'time_command' => time(),
                            'ip_user'      => $_SERVER['REMOTE_ADDR'],
                            'lang'         => $_SERVER['HTTP_ACCEPT_LANGUAGE'],
                        ])->setTestMode($testMode)->setCurrency($currency_code)->setRefCommand(uniqid())->setNotificationUrl([
                            'success_url' => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => $paymentMethod->type,
                            ]),
                            'cancel_url'  => route('customer.top_up.payment_cancel'),
                        ])->send();

                        if (array_key_exists('success', $response)) {

                            if ($response['success'] == '1') {

                                Session::put('user_id', auth()->user()->id);
                                Session::put('token', $response['token']);

                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $response['redirect_url'],
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => $response['errors'][0],
                            ]);
                        }

                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    /*Version 3.9*/
                    case PaymentMethods::TYPE_MPGS:
                        $config = [
                            'payment_url'             => $credentials->payment_url,
                            'api_version'             => $credentials->api_version,
                            'merchant_id'             => $credentials->merchant_id,
                            'authentication_password' => $credentials->authentication_password,
                        ];

                        if (isset($credentials->merchant_name)) {
                            $config['merchant_name'] = $credentials->merchant_name;
                        }

                        if (isset($credentials->merchant_address)) {
                            $config['merchant_address'] = $credentials->merchant_address;
                        }

                        $order_id = uniqid();

                        $paymentData = [
                            'user_id'     => Auth::user()->id,
                            'order_id'    => $order_id,
                            'amount'      => $totalAmount,
                            'currency'    => $currency_code,
                            'sms_unit'    => $sms_unit,
                            'first_name'  => $input['first_name'],
                            'last_name'   => $input['last_name'],
                            'phone'       => $input['phone'],
                            'email'       => $input['email'],
                            'address'     => $input['address'],
                            'city'        => $input['city'],
                            'country'     => $input['country'],
                            'post_code'   => $input['postcode'],
                            'description' => 'Purchase SMS Unit',
                            'return_url'  => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'order_id'       => $order_id,
                                'payment_method' => PaymentMethods::TYPE_MPGS,
                            ]),
                            'cancel_url'  => route('customer.top_up.payment_cancel'),
                        ];

                        $mpgs      = new MPGS($config, $paymentData);
                        $getResult = $mpgs->submit();

                        if (isset($getResult->getData()->status) && $getResult->getData()->status == 'error') {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $getResult->getData()->message,
                            ]);
                        }

                        exit();

                    case PaymentMethods::TYPE_0XPROCESSING:

                        $billing_id = uniqid();
                        Session::put('order_id', $billing_id);

                        $payload = [
                            'MerchantId' => $credentials->merchant_id,
                            'ClientId'   => auth()->user()->id,
                            'AmountUsd'  => $totalAmount,
                            'Currency'   => $currency_code,
                            'BillingId'  => $billing_id,
                            'Email'      => $input['email'],
                            'FirstName'  => $input['first_name'],
                            'SuccessUrl' => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'order_id'       => $billing_id,
                                'payment_method' => PaymentMethods::TYPE_0XPROCESSING,
                            ]),
                            'CancelUrl'  => route('customer.top_up.payment_cancel'),
                            'AutoReturn' => true,
                            'ReturnUrl'  => true,
                        ];

                        if ($credentials->environment == 'sandbox') {
                            $payload['Test'] = true;
                        } else {
                            $payload['Test'] = false;
                        }

                        if (isset($input['last_name'])) {
                            $payload['LastName'] = $input['last_name'];
                        }

                        $sending_url = 'https://app.0xprocessing.com/payment/create/?' . http_build_query($payload);

                        return response()->json([
                            'status'       => 'success',
                            'redirect_url' => $sending_url,
                        ]);

                    /*Version 3.10.0*/

                    case PaymentMethods::TYPE_MYFATOORAH:

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


                        if (empty($input['phone'])) {
                            return response()->json([
                                'status'  => 'info',
                                'message' => 'Phone number is required',
                            ]);
                        }

                        //Fill invoice item array
                        $invoiceItems[] = [
                            'ItemName'  => $item_name,
                            'Quantity'  => '1', //Item's quantity
                            'UnitPrice' => $totalAmount, //Price per item
                        ];

                        $phone = MyFatoorah::getPhone($input['phone']);

                        $postFields = [
                            //Fill required data
                            'InvoiceValue'       => $totalAmount,
                            'CustomerName'       => $input['first_name'] . ' ' . $input['last_name'],
                            'NotificationOption' => 'ALL', //'SMS', 'EML', or 'ALL'
                            //Fill optional data
                            'DisplayCurrencyIso' => $currency_code,
                            'MobileCountryCode'  => $phone[0],
                            'CustomerMobile'     => $phone[1],
                            'CustomerEmail'      => $input['email'],
                            'CallBackUrl'        => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => PaymentMethods::TYPE_MYFATOORAH,
                            ]),
                            'Language'           => config('app.locale'),
                            'CustomerReference'  => uniqid(),
                            'InvoiceItems'       => $invoiceItems,
                        ];

                        try {

                            $mfPayment = new MyFatoorahPayment($config);
                            $data      = $mfPayment->sendPayment($postFields);

                            if (isset($data->ErrorMessage)) {
                                return response()->json([
                                    'status'  => 'error',
                                    'message' => $data->ErrorMessage,
                                ]);
                            }

                            if (isset($data->InvoiceURL)) {
                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $data->InvoiceURL,
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => __('locale.exceptions.something_went_wrong'),
                            ]);

                        } catch (Exception $e) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $e->getMessage(),
                            ]);
                        }

                    case PaymentMethods::TYPE_MAYA:

                        if ($credentials->environment == 'sandbox') {
                            $payment_url = 'https://pg-sandbox.paymaya.com/checkout/v1/checkouts';
                        } else {
                            $payment_url = 'https://pg.paymaya.com/checkout/v1/checkouts';
                        }

                        $reference = Str::uuid()->toString();
                        Session::put('reference', $reference);

                        $parameters = [
                            "totalAmount"            => [
                                "value"    => $totalAmount,
                                "currency" => $currency_code,
                            ],
                            "buyer"                  => [
                                "contact"        => [
                                    "phone" => $input['phone'],
                                    "email" => $input['email'],
                                ],
                                "billingAddress" => [
                                    "line1"   => $input['address'],
                                    "city"    => $input['city'],
                                    "zipCode" => $input['postcode'],
                                ],
                                "firstName"      => $input['first_name'],
                            ],
                            "redirectUrl"            => [
                                "success" => route('customer.top_up.payment_success', [
                                    'user_id'        => auth()->user()->id,
                                    'sms_unit'       => $sms_unit,
                                    'price'          => $price,
                                    'tax_amount'     => $taxAmount,
                                    'payment_method' => PaymentMethods::TYPE_MAYA,
                                    'reference'      => $reference,
                                ]),
                                "failure" => route('customer.top_up.payment_cancel'),
                                "cancel"  => route('customer.top_up.payment_cancel'),
                            ],
                            "metadata"               => ["subMerchantRequestReferenceNumber" => $sms_unit],
                            "items"                  => [
                                [
                                    "amount"      => ["value" => $price],
                                    "totalAmount" => ["value" => $price],
                                    "name"        => $item_name,
                                    "description" => $item_name,
                                ],
                            ],
                            "requestReferenceNumber" => $reference,
                        ];

                        if (isset($input['last_name'])) {
                            $parameters['buyer']['lastName'] = $input['last_name'];
                        }


                        try {
                            $client = new \GuzzleHttp\Client();

                            $response = $client->request('POST', $payment_url, [
                                'body'    => json_encode($parameters),
                                'headers' => [
                                    'accept'        => 'application/json',
                                    'authorization' => 'Basic ' . base64_encode($credentials->public_key),
                                    'content-type'  => 'application/json',
                                ],
                            ]);


                            $data = json_decode($response->getBody()->getContents(), true);

                            if (isset($data['redirectUrl'])) {
                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $data['redirectUrl'],
                                ]);
                            }

                            return response()->json([
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

                                return response()->json([
                                    'status'  => 'error',
                                    'message' => $message,
                                ]);
                            } else {

                                return response()->json([
                                    'status'  => 'error',
                                    'message' => 'No JSON found in the error message.',
                                ]);
                            }
                        }

                    /*Version 3.13.0*/

                    case PaymentMethods::TYPE_NOWPAYMENTS:

                        $order_id = Str::uuid()->toString();

                        $nowPayments = new NowPaymentsAPI($credentials->api_key);

                        $params = [
                            'price_amount'        => $totalAmount,
                            'price_currency'      => $currency_code,
                            'pay_currency'        => 'btc',
                            'pay_amount'          => $totalAmount,
                            'ipn_callback_url'    => route('customer.callback.nowpayments.ipn'),
                            'order_id'            => $order_id,
                            'order_description'   => $item_name,
                            'is_fixed_rate'       => true,
                            'is_fee_paid_by_user' => false,
                        ];


                        $get_result = $nowPayments->createPayment($params);
                        $response   = json_decode($get_result, true);


                        if (is_array($response) && array_key_exists('status', $response) && ! $response['status']) {

                            return response()->json([
                                'status'  => 'error',
                                'message' => $response['message'],
                            ]);
                        }


                        if (is_array($response) && array_key_exists('pay_address', $response)) {
                            return response()->json([
                                'status'      => 'success',
                                'pay_address' => $response['pay_address'],
                                'valid_unit'  => Carbon::parse($response['valid_until'])->toDateTimeString(),
                                'post_data'   => $totalAmount,
                                'tax_amount'  => $taxAmount,
                                'sms_unit'    => $sms_unit,
                                'payment_id'  => $response['payment_id'],
                                'data'        => $response,
                            ]);
                        }

                        return response()->json([
                            'status'  => 'error',
                            'message' => $get_result,
                        ]);

                    case PaymentMethods::TYPE_MERCADOPAGO:

                        $reference = Str::uuid()->toString();
                        Session::put('reference', $reference);

                        MercadoPagoConfig::setAccessToken($credentials->access_token);

                        if ($credentials->environment == 'local') {
                            MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);
                        } else {
                            MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::SERVER);
                        }


                        $paymentMethods = [
                            "excluded_payment_methods" => [],
                            "installments"             => 1,
                            "default_installments"     => 1,
                        ];

                        $backUrls = [
                            'success' => route('customer.top_up.payment_success', [
                                'user_id'        => auth()->user()->id,
                                'sms_unit'       => $sms_unit,
                                'price'          => $price,
                                'tax_amount'     => $taxAmount,
                                'payment_method' => PaymentMethods::TYPE_MERCADOPAGO,
                                'reference'      => $reference,
                            ]),
                            'failure' => route('customer.top_up.payment_cancel'),
                        ];


                        // Fill the data about the product(s) being purchased
                        $items = [
                            [
                                "id"          => uniqid(),
                                "title"       => $item_name,
                                "description" => $item_name,
                                "currency_id" => $currency_code,
                                "quantity"    => 1,
                                "unit_price"  => $totalAmount,
                            ],
                        ];


                        $payer = [
                            "name"  => $input['first_name'] . ' ' . $input['last_name'],
                            "email" => $input['email'],
                        ];

                        $request = [
                            "items"                => $items,
                            "payer"                => $payer,
                            "payment_methods"      => $paymentMethods,
                            "back_urls"            => $backUrls,
                            "statement_descriptor" => $item_name,
                            "external_reference"   => $reference,
                            "expires"              => false,
                            "auto_return"          => 'approved',
                        ];

                        // Instantiate a new Preference Client
                        $client = new PreferenceClient();


                        try {

                            // Send the request that will create the new preference for user's checkout flow
                            $preference = $client->create($request);

                            if ($preference && isset($preference->id) && isset($preference->init_point)) {

                                return response()->json([
                                    'status'       => 'success',
                                    'redirect_url' => $preference->init_point,
                                ]);
                            }

                            // Step 7: Handle exceptions
                        } catch (MPApiException $e) {

                            if (is_array($e->getApiResponse()->getContent()) && array_key_exists('message', $e->getApiResponse()->getContent())) {

                                return response()->json([
                                    'status'  => 'error',
                                    'message' => $e->getApiResponse()->getContent()['message'],
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'message' => $e->getMessage(),
                            ]);

                        } catch (Exception $e) {
                            return response()->json([
                                'status'  => 'error',
                                'message' => $e->getMessage(),
                            ]);
                        }

                        return response()->json([
                            'status'  => 'error',
                            'message' => __('locale.exceptions.something_went_wrong'),
                        ]);

                    case PaymentMethods::TYPE_CASH:

                        return response()->json([
                            'status'     => 'success',
                            'data'       => $credentials,
                            'post_data'  => $price,
                            'tax_amount' => $taxAmount,
                            'sms_unit'   => $sms_unit,
                        ]);
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.payment_gateways.not_found'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.payment_gateways.not_found'),
            ]);
        }


        /*Version 3.13.0*/

        /**
         * @throws GeneralException
         */
        public function impersonate(User $customer)
        {
            if ($customer->is_admin) {
                throw new GeneralException(__('locale.customer.admin_cannot_be_impersonated'));
            }

            $authenticatedUser = auth()->user();

            // Ensure only parent or owner can impersonate
            if ($authenticatedUser->parent_id !== $customer->id) {
                throw new GeneralException(__('locale.customer.impersonation_not_allowed'));
            }

            // Set impersonation session only once
            if ( ! session()->has('parent_user_id')) {
                session([
                    'parent_user_id'   => $authenticatedUser->id,
                    'parent_user_name' => $authenticatedUser->displayName(),
                    'temp_user_id'     => $customer->id,
                    'permissions'      => collect(
                        json_decode(
                            $authenticatedUser->parent_id === $customer->id
                                ? $authenticatedUser->customer->permissions
                                : $customer->customer->permissions,
                            true
                        )
                    ),
                ]);

                $customer->update(['active_portal' => 'customer']);
            }

            // Login as impersonated customer
            auth()->loginUsingId($customer->id);

            return redirect(Helper::home_route());
        }


    }
