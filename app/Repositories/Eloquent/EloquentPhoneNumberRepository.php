<?php

    namespace App\Repositories\Eloquent;

    use App\Exceptions\GeneralException;
    use App\Helpers\Helper;
    use App\Library\aamarPay;
    use App\Library\CoinPayments;
    use App\Library\Flutterwave;
    use App\Library\MPGS;
    use App\Library\NowPaymentsAPI;
    use App\Library\OrangeMoney;
    use App\Library\PayHereLK;
    use App\Library\PayU;
    use App\Library\PayUMoney;
    use App\Library\TwoCheckout;
    use App\Models\AppConfig;
    use App\Models\Country;
    use App\Models\PaymentMethods;
    use App\Models\PhoneNumbers;
    use App\Repositories\Contracts\PhoneNumberRepository;
    use Braintree\Gateway;
    use Carbon\Carbon;
    use Exception;
    use GuzzleHttp\Exception\GuzzleException;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Support\Arr;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Session;
    use Illuminate\Support\Str;
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
    use PaypalServerSdkLib\Exceptions\ApiException;
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
    use Stripe\Stripe;
    use Stripe\TaxRate;
    use Throwable;

    class EloquentPhoneNumberRepository extends EloquentBaseRepository implements PhoneNumberRepository
    {
        /**
         * EloquentPhoneNumberRepository constructor.
         */
        public function __construct(PhoneNumbers $number)
        {
            parent::__construct($number);
        }

        /**
         * @throws GeneralException
         */
        public function store(array $input, array $billingCycle): PhoneNumbers
        {
            /** @var PhoneNumbers $number */
            $number = $this->make(Arr::only($input, [
                'user_id',
                'status',
                'price',
                'billing_cycle',
                'frequency_amount',
                'frequency_unit',
                'currency_id',
            ]));

            $number->number       = str_replace(['(', ')', '+', '-', ' '], '', $input['number']);
            $number->capabilities = json_encode($input['capabilities']);

            if (isset($input['billing_cycle']) && $input['billing_cycle'] != 'custom') {
                $limits                   = $billingCycle[$input['billing_cycle']];
                $number->frequency_amount = $limits['frequency_amount'];
                $number->frequency_unit   = $limits['frequency_unit'];
            }

            if ( ! $this->save($number)) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return $number;

        }

        private function save(PhoneNumbers $number): bool
        {
            if ( ! $number->save()) {
                return false;
            }

            return true;
        }

        /**
         * @throws GeneralException
         */
        public function update(PhoneNumbers $number, array $input, array $billingCycle): PhoneNumbers
        {

            if (isset($input['status']) && $input['status'] == 'available') {
                $input['user_id'] = 1;
            }


            $input['number']       = str_replace(['(', ')', '+', '-', ' '], '', $input['number']);
            $input['capabilities'] = json_encode($input['capabilities']);
            if (isset($input['billing_cycle']) && $input['billing_cycle'] != 'custom') {
                $limits                    = $billingCycle[$input['billing_cycle']];
                $input['frequency_amount'] = $limits['frequency_amount'];
                $input['frequency_unit']   = $limits['frequency_unit'];
            }

            if ( ! $number->update($input)) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return $number;
        }

        /**
         * @throws GeneralException
         */
        public function destroy(PhoneNumbers $number): bool
        {
            if ( ! $number->delete()) {
                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            }

            return true;
        }

        /**
         * @return mixed
         *
         * @throws Exception|Throwable
         */
        public function batchDestroy(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                // This won't call eloquent events, change to destroy if needed
                if ($this->query()->whereIn('uid', $ids)->delete()) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            });

            return true;
        }

        /**
         * @return mixed
         *
         * @throws Exception|Throwable
         */
        public function batchAvailable(array $ids): bool
        {
            DB::transaction(function () use ($ids) {
                if ($this->query()->whereIn('uid', $ids)
                    ->update(['status' => 'available'])
                ) {
                    return true;
                }

                throw new GeneralException(__('locale.exceptions.something_went_wrong'));
            });

            return true;
        }

        /**
         * release number
         *
         *
         * @throws GeneralException
         */
        public function release(PhoneNumbers $number, string $id): bool
        {
            $available = $number->where('user_id', Auth::user()->id)->where('uid', $id)->first();

            if ($available) {
                $available->user_id       = 1;
                $available->status        = 'available';
                $available->validity_date = null;
                if ( ! $available->save()) {
                    throw new GeneralException(__('locale.exceptions.something_went_wrong'));
                }

                return true;
            }

            throw new GeneralException(__('locale.exceptions.something_went_wrong'));
        }

        /**
         * pay the payment
         *
         *
         * @throws Exception
         * @throws InvalidArgumentException
         */
        public function payPayment(PhoneNumbers $number, array $input): JsonResponse
        {

            $paymentMethod = PaymentMethods::where('status', true)->where('type', $input['payment_methods'])->first();

            if ($paymentMethod) {
                $credentials = json_decode($paymentMethod->options);

                $item_name     = __('locale.phone_numbers.payment_for_number') . ' ' . $number->number;
                $price         = $number->price;
                $currency_code = $number->currency->code;
                $taxAmount     = 0;
                $totalAmount   = $price;


                $country = Country::where('name', $input['country'])->first();

                if ($country) {
                    $taxRate = AppConfig::getTaxByCountry($country);
                    if ($taxRate > 0) {
                        $taxAmount   = ($price * $taxRate) / 100;
                        $totalAmount = $price + $taxAmount;
                    }
                }


                switch ($paymentMethod->type) {

                    case PaymentMethods::TYPE_PAYPAL:

// Create the order request
                        $orderRequest = OrderRequestBuilder::init(
                            CheckoutPaymentIntent::CAPTURE,
                            [
                                PurchaseUnitRequestBuilder::init(
                                    AmountWithBreakdownBuilder::init(
                                        $number->currency->code,
                                        $totalAmount
                                    )->breakdown(
                                        AmountBreakdownBuilder::init()
                                            ->itemTotal(
                                                MoneyBuilder::init($number->currency->code, $price)->build() // ✅ Corrected here
                                            )
                                            ->taxTotal(
                                                MoneyBuilder::init($number->currency->code, $taxAmount)->build() // ✅ And here
                                            )
                                            ->build()
                                    )->build()
                                )->referenceId($number->user->id . '_' . $number->uid)
                                    ->description($item_name)
                                    ->items([
                                        ItemBuilder::init(
                                            $item_name,
                                            MoneyBuilder::init($number->currency->code, $price)->build(),
                                            1
                                        )
                                            ->description($item_name)
                                            ->sku($number->user->id . '_' . $number->uid)
                                            ->tax(
                                                MoneyBuilder::init($number->currency->code, $taxAmount)->build() // ✅ Tax amount fixed here
                                            )
                                            ->category('DIGITAL_GOODS')
                                            ->build(),
                                    ])
                                    ->build(),
                            ]
                        )->applicationContext(
                            OrderApplicationContextBuilder::init()
                                ->cancelUrl(route('customer.numbers.payment_cancel', $number->uid))
                                ->returnUrl(route('customer.numbers.payment_success', $number->uid))
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

                            $redirect_url = null;

                            if ( ! empty($response->getResult()->getId()) && ! empty($response->getResult()->getLinks())) {
                                foreach ($response->getResult()->getLinks() as $link) {
                                    if ($link->getRel() == 'approve') {
                                        $redirect_url = $link->getHref();
                                        break;
                                    }
                                }
                            }

                            if (isset($redirect_url)) {
                                if ( ! empty($response->getResult()->getId())) {
                                    Session::put('payment_method', $paymentMethod->type);
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
                        } catch (ApiException $exception) {

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
                        $taxRateData     = null;

                        Stripe::setApiKey($secret_key);

                        try {
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
                                'line_items'           => [[
                                    'price_data' => [
                                        'currency'     => $number->currency->code,
                                        'unit_amount'  => Helper::getStripeAmount($price, $number->currency->code),
                                        'product_data' => [
                                            'name' => $item_name,
                                        ],
                                    ],
                                    'quantity'   => 1,
                                    'tax_rates'  => $taxRateData ? [$taxRateData->id] : [],
                                ]],
                                'mode'                 => 'payment',
                                'success_url'          => route('customer.numbers.payment_success', $number->uid),
                                'cancel_url'           => route('customer.numbers.payment_cancel', $number->uid),
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

                        Session::put('payment_method', $paymentMethod->type);

                        $checkout = new TwoCheckout();

                        $checkout->param('sid', $credentials->merchant_code);
                        if ($credentials->environment == 'sandbox') {
                            $checkout->param('demo', 'Y');
                        }
                        $checkout->param('return_url', route('customer.numbers.payment_success', $number->uid));
                        $checkout->param('li_0_name', $item_name);
                        $checkout->param('li_0_price', $totalAmount);
                        $checkout->param('li_0_quantity', 1);
                        $checkout->param('card_holder_name', $input['first_name'] . ' ' . $input['last_name']);
                        $checkout->param('city', $input['city']);
                        $checkout->param('country', $input['country']);
                        $checkout->param('email', $input['email']);
                        $checkout->param('phone', $input['phone']);
                        $checkout->param('currency_code', $number->currency->code);
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
                                    'number_id'    => $number->uid,
                                    'request_type' => 'number_payment',
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

                        Session::put('payment_method', $paymentMethod->type);

                        $signature = "$credentials->client_secret~$credentials->client_id~number$number->uid~$totalAmount~$number->currency->code";
                        $signature = md5($signature);

                        $payu = new PayU();

                        $payu->param('merchantId', $credentials->client_id);
                        $payu->param('ApiKey', $credentials->client_secret);
                        $payu->param('referenceCode', 'number' . $number->uid);
                        $payu->param('description', $item_name);
                        $payu->param('amount', $totalAmount);
                        $payu->param('currency', $number->currency->code);
                        $payu->param('buyerEmail', $input['email']);
                        $payu->param('signature', $signature);
                        $payu->param('confirmationUrl', route('customer.numbers.payment_success', $number->uid));
                        $payu->param('responseUrl', route('customer.numbers.payment_cancel', $number->uid));
                        $payu->gw_submit();

                        exit();

                    case PaymentMethods::TYPE_PAYNOW:

                        $paynow = new Paynow(
                            $credentials->integration_id,
                            $credentials->integration_key,
                            route('customer.callback.paynow'),
                            route('customer.numbers.payment_success', $number->uid)
                        );

                        $payment = $paynow->createPayment($number->uid, $input['email']);
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

                        Session::put('payment_method', $paymentMethod->type);

                        $coinPayment = new CoinPayments();

                        $order = [
                            'merchant'    => $credentials->merchant_id,
                            'item_name'   => $item_name,
                            'amountf'     => $totalAmount,
                            'currency'    => $number->currency->code,
                            'custom'      => 'number',
                            'success_url' => route('customer.numbers.payment_success', $number->uid),
                            'cancel_url'  => route('customer.numbers.payment_cancel', $number->uid),
                        ];

                        foreach ($order as $item => $value) {
                            $coinPayment->param($item, $value);
                        }

                        $coinPayment->gw_submit();

                        exit();

                    case PaymentMethods::TYPE_INSTAMOJO:

                        $name = $input['first_name'];
                        if (isset($input['last_name'])) {
                            $name .= ' ' . $input['last_name'];
                        }

                        $payload = [
                            'purpose'                 => $item_name,
                            'amount'                  => $totalAmount,
                            'phone'                   => $input['phone'],
                            'buyer_name'              => $name,
                            'redirect_url'            => route('customer.numbers.payment_success', $number->uid),
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
                            if ($response->success) {

                                Session::put('payment_method', $paymentMethod->type);
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

                        Session::put('payment_method', $paymentMethod->type);

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
                        $payumoney->param('surl', route('customer.numbers.payment_success', $number->uid));
                        $payumoney->param('furl', route('customer.numbers.payment_cancel', $number->uid));

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
                                    'name'    => $input['first_name'],
                                    'email'   => $input['email'],
                                    'contact' => $input['phone'],
                                ],
                                'notify'          => ['sms' => true, 'email' => true],
                                'reminder_enable' => true,
                                'notes'           => [
                                    'number' => $number->uid,
                                ],
                                'callback_url'    => route('customer.numbers.payment_success', $number->uid),
                                'callback_method' => 'get',
                            ]);


                            if (isset($link->id) && isset($link->short_url)) {

                                Session::put('razorpay_payment_link_reference_id', $link->reference_id);

                                $number->update([
                                    'transaction_id' => $link->reference_id,
                                ]);

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
                        $post_data['currency']     = $number->currency->code;
                        $post_data['tran_id']      = $number->uid;
                        $post_data['success_url']  = route('customer.callback.sslcommerz.numbers', $number->uid);
                        $post_data['fail_url']     = route('customer.callback.sslcommerz.numbers', $number->uid);
                        $post_data['cancel_url']   = route('customer.callback.sslcommerz.numbers', $number->uid);

                        $post_data['product_category'] = 'numbers';
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
                            ['product' => $item_name, 'amount' => $totalAmount],
                        ]);
                        $post_data['product_name']    = $item_name;
                        $post_data['product_profile'] = 'non-physical-goods';
                        $post_data['product_amount']  = $totalAmount;

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

                        Session::put('payment_method', $paymentMethod->type);

                        $checkout = new aamarPay($credentials->environment);

                        $checkout->param('store_id', $credentials->store_id);
                        $checkout->param('signature_key', $credentials->signature_key);
                        $checkout->param('desc', $item_name);
                        $checkout->param('amount', $totalAmount);
                        $checkout->param('currency', $number->currency->code);
                        $checkout->param('tran_id', $number->uid);
                        $checkout->param('success_url', route('customer.callback.aamarpay.numbers', $number->uid));
                        $checkout->param('fail_url', route('customer.callback.aamarpay.numbers', $number->uid));
                        $checkout->param('cancel_url', route('customer.callback.aamarpay.numbers', $number->uid));

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
                        $checkout->param('currency', $number->currency->code);
                        $checkout->param('tx_ref', $number->uid);
                        $checkout->param('redirect_url', route('customer.callback.flutterwave.numbers'));
                        $checkout->param('customizations[title]', $item_name);
                        $checkout->param('customizations[description]', $item_name);
                        $checkout->param('customer[name]', $input['first_name'] . ' ' . $input['last_name']);
                        $checkout->param('customer[email]', $input['email']);
                        $checkout->param('customer[phone_number]', $input['phone']);
                        $checkout->param('meta[user_id]', auth()->user()->id);
                        $checkout->gw_submit();
                        exit();

                    case PaymentMethods::TYPE_DIRECTPAYONLINE:

                        if ($credentials->environment == 'production') {
                            $payment_url = 'https://secure.3gdirectpay.com';
                        } else {
                            $payment_url = 'https://secure1.sandbox.directpay.online';
                        }

                        $companyToken    = $credentials->company_token;
                        $accountType     = $credentials->account_type;
                        $paymentAmount   = $totalAmount;
                        $paymentCurrency = $number->currency->code;
                        $reference       = uniqid();
                        $odate           = date('Y/m/d H:i');
                        $redirectURL     = route('customer.numbers.payment_success', $number->uid);
                        $backURL         = route('customer.numbers.payment_cancel', $number->uid);

                        $customer_email      = auth()->user()->email;
                        $customer_first_name = auth()->user()->first_name;
                        $customer_last_name  = auth()->user()->last_name;

                        $postXml = <<<POSTXML
<?xml version="1.0" encoding="utf-8"?>
        <API3G>
        <CompanyToken>$companyToken</CompanyToken>
        <Request>createToken</Request>
        <Transaction>
        <PaymentAmount>$paymentAmount</PaymentAmount>
        <PaymentCurrency>$paymentCurrency</PaymentCurrency>
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

                                    Session::put('payment_method', $paymentMethod->type);

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

                    case PaymentMethods::TYPE_PAYGATEGLOBAL:

                        $order_id = str_random(10);

                        $parameters = [
                            'token'    => $credentials->api_key,
                            'amount'   => $totalAmount,
                            'identify' => $order_id,
                            'url'      => route('customer.numbers.payment_success', $number->uid),
                        ];
                        $parameters = http_build_query($parameters);

                        return response()->json([
                            'status'       => 'success',
                            'redirect_url' => 'https://paygateglobal.com/v1/page?' . $parameters,
                        ]);

                    case PaymentMethods::TYPE_ORANGEMONEY:

                        $payment = new OrangeMoney($credentials->auth_header, $credentials->merchant_key);

                        $data = [
                            'merchant_key' => $credentials->merchant_key,
                            'currency'     => $number->currency->code,
                            'order_id'     => str_random(10),
                            'amount'       => $totalAmount,
                            'payment_url'  => $credentials->payment_url,
                            'return_url'   => route('customer.numbers.payment_cancel', $number->uid),
                            'cancel_url'   => route('customer.numbers.payment_cancel', $number->uid),
                            'notif_url'    => route('customer.numbers.payment_success', $number->uid),
                            'lang'         => config('app.locale'),
                            'reference'    => $number->uid,
                        ];

                        $callback_data = $payment->getPaymentUrl($data);

                        if (array_key_exists('payment_url', $callback_data)) {

                            Session::put('payment_method', $paymentMethod->type);
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
                            'currency'              => $number->currency->code,
                            'description'           => $item_name,
                            'customer_name'         => $input['first_name'] . ' ' . $input['last_name'],
                            'customer_email'        => $input['email'],
                            'customer_phone_number' => $input['phone'],
                            'customer_address'      => $input['address'],
                            'customer_city'         => $input['city'],
                            'customer_country'      => Country::getIsoCode($input['country']),
                            'notify_url'            => route('customer.numbers.payment_cancel', $number->uid),
                            'return_url'            => route('customer.numbers.payment_success', $number->uid),
                            'channels'              => 'ALL',
                            'lang'                  => config('app.locale'),
                            'metadata'              => 'number_id_' . $number->uid,
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

                                    Session::put('payment_method', $paymentMethod->type);
                                    Session::put('cinetPay_transaction_id', $transaction_id);
                                    Session::put('cinetPay_payment_token', $result['data']['payment_token']);

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
                                $number->currency->code .
                                strtoupper(md5($credentials->merchant_secret))
                            )
                        );


                        $checkout = new PayHereLK($credentials->environment);

                        $checkout->param('merchant_id', $credentials->merchant_id);
                        $checkout->param('items', $item_name);
                        $checkout->param('amount', $totalAmount);
                        $checkout->param('currency', $number->currency->code);
                        $checkout->param('order_id', $order_id);
                        $checkout->param('return_url', route('customer.numbers.payment_success', [
                            'number'         => $number->uid,
                            'payment_method' => $paymentMethod->type,
                        ]));
                        $checkout->param('cancel_url', route('customer.numbers.payment_cancel', $number->uid));
                        $checkout->param('notify_url', route('customer.numbers.payment_cancel', $number->uid));
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
                                'currency' => $number->currency->code,
                                'value'    => number_format((float) $totalAmount, 2, '.', ''),
                            ],
                            'description' => $item_name,
                            'redirectUrl' => route('customer.numbers.payment_success', $number->uid),
                            'metadata'    => [
                                'user'           => \auth()->user()->id,
                                'number'         => $number->uid,
                                'payment_method' => $paymentMethod->uid,
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

                        $body = [
                            'type'    => ['single'],
                            'payment' => [
                                'methods'         => ['cc', 'mb', 'mbw', 'dd', 'vi', 'uf', 'sc'],
                                'type'            => 'sale',
                                'capture'         => [
                                    'descriptive' => $item_name,
                                ],
                                'currency'        => $number->currency->code,
                                'expiration_time' => null,
                            ],
                            'order'   => [
                                'key'   => $number->uid,
                                'value' => floatval($totalAmount),
                                'items' => [
                                    [
                                        'key'         => $number->uid,
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
                            'status' => 'success',
                            'data'   => $response,
                        ]);

                    case PaymentMethods::TYPE_FEDAPAY:
                        return response()->json([
                            'status'     => 'success',
                            'public_key' => $credentials->public_key,
                            'number'     => $number,
                        ]);

                    /*Version 3.8*/

                    case PaymentMethods::TYPE_SELCOMMOBILE:

                        Session::put('payment_method', $paymentMethod->type);

                        $orderMinArray = [
                            'vendor'                    => $credentials->vendor,
                            'order_id'                  => $number->uid,
                            'buyer_email'               => $input['email'],
                            'buyer_name'                => $input['first_name'] . ' ' . $input['last_name'],
                            'buyer_phone'               => $input['phone'],
                            'amount'                    => $totalAmount,
                            'currency'                  => $number->currency->code,
                            'redirect_url'              => base64_encode(route('customer.numbers.payment_success', $number->uid)),
                            'cancel_url'                => base64_encode(route('customer.numbers.payment_cancel', $number->uid)),
                            'webhook'                   => base64_encode(route('customer.numbers.payment_cancel', $number->uid)),
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
                            'currency'    => $number->currency->code,
                            'first_name'  => $input['first_name'],
                            'last_name'   => $input['last_name'],
                            'phone'       => $input['phone'],
                            'email'       => $input['email'],
                            'address'     => $input['address'],
                            'city'        => $input['city'],
                            'country'     => $input['country'],
                            'post_code'   => $input['postcode'],
                            'description' => $item_name,
                            'cancel_url'  => route('customer.numbers.payment_cancel', $number->uid),
                            'return_url'  => route('customer.numbers.payment_success', [
                                'number'         => $number->uid,
                                'payment_method' => PaymentMethods::TYPE_MPGS,
                                'order_id'       => $order_id,
                            ]),
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

                        Session::put('payment_method', $paymentMethod->type);
                        Session::put('order_id', $billing_id);

                        $payload = [
                            'MerchantId' => $credentials->merchant_id,
                            'ClientId'   => auth()->user()->id,
                            'AmountUsd'  => $totalAmount,
                            'Currency'   => $number->currency->code,
                            'BillingId'  => $billing_id,
                            'Email'      => $input['email'],
                            'FirstName'  => $input['first_name'],
                            'SuccessUrl' => route('customer.numbers.payment_success', [
                                'number'         => $number->uid,
                                'payment_method' => PaymentMethods::TYPE_0XPROCESSING,
                                'order_id'       => $billing_id,
                            ]),
                            'CancelUrl'  => route('customer.numbers.payment_cancel', $number->uid),
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
                            'DisplayCurrencyIso' => $number->currency->code,
                            'MobileCountryCode'  => $phone[0],
                            'CustomerMobile'     => $phone[1],
                            'CustomerEmail'      => $input['email'],
                            'CallBackUrl'        => route('customer.numbers.payment_success', $number->uid),
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
                                "success" => route('customer.numbers.payment_success', [
                                    'number'         => $number->uid,
                                    'payment_method' => PaymentMethods::TYPE_MAYA,
                                    'reference'      => $reference,
                                ]),
                                "failure" => route('customer.numbers.payment_cancel', $number->uid),
                                "cancel"  => route('customer.numbers.payment_cancel', $number->uid),
                            ],
                            "metadata"               => ["subMerchantRequestReferenceNumber" => $reference],
                            "items"                  => [
                                [
                                    "amount"      => ["value" => $totalAmount],
                                    "totalAmount" => ["value" => $totalAmount],
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

                        $nowPayments = new NowPaymentsAPI($credentials->api_key);

                        $params = [
                            'price_amount'        => $totalAmount,
                            'price_currency'      => $currency_code,
                            'pay_currency'        => 'btc',
                            'pay_amount'          => $totalAmount,
                            'ipn_callback_url'    => route('customer.callback.nowpayments.ipn'),
                            'order_id'            => $number->uid,
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
                            'success' => route('customer.numbers.payment_success', [
                                'number'         => $number->uid,
                                'payment_method' => PaymentMethods::TYPE_MERCADOPAGO,
                                'reference'      => $reference,
                            ]),
                            'failure' => route('customer.numbers.payment_cancel', $number->uid),
                        ];


                        // Fill the data about the product(s) being purchased
                        $items = [
                            [
                                "id"          => uniqid(),
                                "title"       => $item_name,
                                "description" => $item_name,
                                "currency_id" => $currency_code,
                                "quantity"    => 1,
                                "unit_price"  => (int) $totalAmount,
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
                            'status' => 'success',
                            'data'   => $credentials,
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

    }
