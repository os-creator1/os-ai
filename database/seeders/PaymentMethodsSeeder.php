<?php

    namespace Database\Seeders;

    use App\Models\PaymentMethods;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;

    class PaymentMethodsSeeder extends Seeder
    {
        /**
         * Run the database seeders.
         */
        public function run(): void
        {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('payment_methods')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $payment_gateways = [
                [
                    'name'    => 'PayPal',
                    'type'    => 'paypal',
                    'options' => json_encode([
                        'client_id'   => 'AfXkbWwSG5T3dTN2n4HWEJi7AQ54X3CcVHyo0sMPcWHvKBL9ujQvmMOiivrd65PFz1a2pBFlS6xF1QE2',
                        'secret'      => 'ENLQxtqjzZOVGQFUosjjWUTje7JXqgU6CODBIheqEv7Mj8p7BuSTyBOlf8PW4sWaL5lZ-mitEahCqxAC',
                        'environment' => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Braintree',
                    'type'    => 'braintree',
                    'options' => json_encode([
                        'merchant_id' => 's999zxpkvhh6dpm2',
                        'public_key'  => 'hw4v45rty67jdxc9',
                        'private_key' => 'cac14c4d9d950e32c947b400b10a7596',
                        'environment' => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Stripe',
                    'type'    => 'stripe',
                    'options' => json_encode([
                        'publishable_key' => 'pk_test_AnS4Ov8GS92XmHeVCDRPIZF4',
                        'secret_key'      => 'sk_test_iS0xwfgzBF6cmPBBkgO13sjd',
                        'environment'     => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Authorize.net',
                    'type'    => 'authorize_net',
                    'options' => json_encode([
                        'login_id'        => 'login_id',
                        'transaction_key' => 'transaction_key',
                        'environment'     => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => '2checkout',
                    'type'    => '2checkout',
                    'options' => json_encode([
                        'merchant_code' => 'merchant_code',
                        'private_key'   => 'private_key',
                        'environment'   => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Paystack',
                    'type'    => 'paystack',
                    'options' => json_encode([
                        'public_key'     => 'public_key',
                        'secret_key'     => 'secret_key',
                        'merchant_email' => 'merchant_email',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'PayU',
                    'type'    => 'payu',
                    'options' => json_encode([
                        'client_id'     => 'client_id',
                        'client_secret' => 'client_secret',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Paynow',
                    'type'    => 'paynow',
                    'options' => json_encode([
                        'integration_id'  => 'integration_id',
                        'integration_key' => 'integration_key',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'CoinPayments',
                    'type'    => 'coinpayments',
                    'options' => json_encode([
                        'merchant_id' => 'merchant_id',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Instamojo',
                    'type'    => 'instamojo',
                    'options' => json_encode([
                        'api_key'    => 'api_key',
                        'auth_token' => 'auth_token',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'PayUmoney',
                    'type'    => 'payumoney',
                    'options' => json_encode([
                        'merchant_key'  => 'merchant_key',
                        'merchant_salt' => 'merchant_salt',
                        'environment'   => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Razorpay',
                    'type'    => 'razorpay',
                    'options' => json_encode([
                        'key_id'      => 'rzp_test_ua7FZgbOf7TjBY',
                        'key_secret'  => '2db2qvRECfols03sc3aquZnU',
                        'environment' => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'SSLcommerz',
                    'type'    => 'sslcommerz',
                    'options' => json_encode([
                        'store_id'     => 'store_id',
                        'store_passwd' => 'store_id@ssl',
                        'environment'  => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'aamarPay',
                    'type'    => 'aamarpay',
                    'options' => json_encode([
                        'store_id'      => 'store_id',
                        'signature_key' => 'signature_key',
                        'environment'   => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Flutterwave',
                    'type'    => 'flutterwave',
                    'options' => json_encode([
                        'public_key'  => 'public_key',
                        'secret_key'  => 'secret_key',
                        'environment' => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'DirectPayOnline',
                    'type'    => 'directpayonline',
                    'options' => json_encode([
                        'company_token' => 'company_token',
                        'account_type'  => 'account_type',
                        'environment'   => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'PaygateGlobal',
                    'type'    => 'paygateglobal',
                    'options' => json_encode([
                        'api_key' => 'api_key',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'OrangeMoney',
                    'type'    => 'orangemoney',
                    'options' => json_encode([
                        'payment_url'  => 'https://api.orange.com/orange-money-webpay/dev/v1/webpayment',
                        'merchant_key' => 'Merchant Key',
                        'auth_header'  => 'Authorization Header',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'CinetPay',
                    'type'    => 'cinetpay',
                    'options' => json_encode([
                        'payment_url' => 'https://api-checkout.cinetpay.com/v2/payment',
                        'api_key'     => 'API KEY',
                        'site_id'     => 'Site ID',
                        'secret_key'  => 'Secret Key',
                    ]),
                    'status'  => false,
                ],

                [
                    'name'    => 'AzamPay',
                    'type'    => 'azampay',
                    'options' => json_encode([
                        'app_name'       => 'App Name',
                        'account_number' => 'Account Number/MSISDN',
                        'client_id'      => 'Client ID',
                        'client_secret'  => 'Client Secret',
                        'provider'       => 'Provider',
                        'environment'    => 'sandbox',
                    ]),
                    'status'  => false,
                ],

                [
                    'name'    => 'VodacomMPesa',
                    'type'    => 'vodacommpesa',
                    'options' => json_encode([
                        'payment_url'         => 'https://api.vm.co.mz:18352/ipg/v1x/c2bPayment/singleStage/',
                        'apiKey'              => 'API KEY',
                        'publicKey'           => 'Public Key',
                        'serviceProviderCode' => 'Service Provider Code',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'Mollie',
                    'type'    => 'mollie',
                    'options' => json_encode([
                        'api_key'     => 'api_key',
                        'environment' => 'sandbox',
                    ]),
                    'status'  => false,
                ],
                [
                    'name'    => 'PayHereLK',
                    'type'    => 'payherelk',
                    'options' => json_encode([
                        'merchant_id'     => '1220932',
                        'merchant_secret' => 'MzQ0NDIxNjg2OTI3NTk0MzgzMDgyMzMwMDU3MDU3NTA5MjI4NTQ=',
                        'app_id'          => '4OVx3Qn9Mjg4JBvpz2umYt3Xl',
                        'app_secret'      => '8m6ymwXfeKs8QnZm59OAAX4JH7QqeoBNq4TwT1KxfZwY',
                        'environment'     => 'sandbox',
                    ]),
                    'status'  => false,
                ],

                [
                    'name'    => 'EasyPay',
                    'type'    => 'easypay',
                    'options' => json_encode([
                        'payment_url'  => 'https://api.test.easypay.pt/2.0/checkout',
                        'account_id'   => 'a266e1f7-5bed-45a4-b260-0a5a2f03b986',
                        'api_key'      => '86492c9d-3af4-45cf-ba90-ba79a1f74a9e',
                        'merchant_key' => uniqid(),
                        'environment'  => 'sandbox',
                    ]),
                    'status'  => false,
                ],

                [
                    'name'    => 'FedaPay',
                    'type'    => 'fedapay',
                    'options' => json_encode([
                        'public_key'  => 'public key',
                        'secret_key'  => 'secret key',
                        'environment' => 'sandbox',
                    ]),
                    'status'  => false,
                ],

                [
                    'name'    => 'SelcomMobile',
                    'type'    => 'selcommobile',
                    'options' => json_encode([
                        'payment_url' => 'https://apigw.selcommobile.com/v1',
                        'vendor'      => 'VENDORTILL',
                        'api_key'     => '202cb962ac59075b964b07152d234b70',
                        'api_secret'  => '81dc9bdb52d04dc20036dbd8313ed055',
                    ]),
                    'status'  => false,
                ],

//                [
//                    'name'    => 'LIQPAY',
//                    'type'    => 'liqpay',
//                    'options' => json_encode([
//                        'payment_url' => 'https://www.liqpay.ua/api/3/checkout',
//                        'public_key'  => 'sandbox_i18876804567',
//                        'private_key' => 'sandbox_6CEn9dq2KPREzI5msrFIv09ATDen1TAd3L',
//                    ]),
//                    'status'  => false,
//                ],

                [
                    'name'    => 'PayTech',
                    'type'    => PaymentMethods::TYPE_PAYTECH,
                    'options' => json_encode([
                        'api_key'     => 'api_key',
                        'api_secret'  => 'api_secret',
                        'environment' => 'sandbox',
                    ]),
                    'status'  => false,
                ],

                [
                    'name'    => 'MasterCard Payment Gateway Services (MPGS)',
                    'type'    => PaymentMethods::TYPE_MPGS,
                    'options' => json_encode([
                        'payment_url'             => 'https://ap-gateway.mastercard.com/',
                        'api_version'             => '66',
                        'merchant_id'             => 'merchant_id',
                        'authentication_password' => 'authentication_password',
                        'merchant_name'           => 'Merchant Name',
                        'merchant_address'        => 'Merchant Address',
                    ]),
                    'status'  => false,
                ],

//                [
//                    'name'    => PaymentMethods::TYPE_0XPROCESSING,
//                    'type'    => PaymentMethods::TYPE_0XPROCESSING,
//                    'options' => json_encode([
//                        'merchant_id' => 'merchant id',
//                        'api_key'     => 'api key',
//                        'environment' => 'sandbox',
//                    ]),
//                    'status'  => false,
//                ],

//                [
//                    'name'    => PaymentMethods::TYPE_MYFATOORAH,
//                    'type'    => PaymentMethods::TYPE_MYFATOORAH,
//                    'options' => json_encode([
//                        'api_token'        => 'rLtt6JWvbUHDDhsZnfpAhpYk4dxYDQkbcPTyGaKp2TYqQgG7FGZ5Th_WD53Oq8Ebz6A53njUoo1w3pjU1D4vs_ZMqFiz_j0urb_BH9Oq9VZoKFoJEDAbRZepGcQanImyYrry7Kt6MnMdgfG5jn4HngWoRdKduNNyP4kzcp3mRv7x00ahkm9LAK7ZRieg7k1PDAnBIOG3EyVSJ5kK4WLMvYr7sCwHbHcu4A5WwelxYK0GMJy37bNAarSJDFQsJ2ZvJjvMDmfWwDVFEVe_5tOomfVNt6bOg9mexbGjMrnHBnKnZR1vQbBtQieDlQepzTZMuQrSuKn-t5XZM7V6fCW7oP-uXGX-sMOajeX65JOf6XVpk29DP6ro8WTAflCDANC193yof8-f5_EYY-3hXhJj7RBXmizDpneEQDSaSz5sFk0sV5qPcARJ9zGG73vuGFyenjPPmtDtXtpx35A-BVcOSBYVIWe9kndG3nclfefjKEuZ3m4jL9Gg1h2JBvmXSMYiZtp9MR5I6pvbvylU_PP5xJFSjVTIz7IQSjcVGO41npnwIxRXNRxFOdIUHn0tjQ-7LwvEcTXyPsHXcMD8WtgBh-wxR8aKX7WPSsT1O8d8reb2aR7K3rkV3K82K_0OgawImEpwSvp9MNKynEAJQS6ZHe_J_l77652xwPNxMRTMASk1ZsJL',
//                        'country_iso_code' => 'KWT',
//                        'environment'      => 'sandbox',
//                    ]),
//                    'status'  => false,
//                ],

//                [
//                    'name'    => PaymentMethods::TYPE_MAYA,
//                    'type'    => PaymentMethods::TYPE_MAYA,
//                    'options' => json_encode([
//                        'public_key'  => 'Public Key',
//                        'secret_key'  => 'Secret Key',
//                        'environment' => 'sandbox',
//                    ]),
//                    'status'  => false,
//                ],


//                [
//                    'name'    => PaymentMethods::TYPE_NOWPAYMENTS,
//                    'type'    => PaymentMethods::TYPE_NOWPAYMENTS,
//                    'options' => json_encode([
//                        'payment_url'    => 'https://api.nowpayments.io/v1/',
//                        'api_key'        => 'F6ZXWAY-2K7MQ6K-G0GWJH8-AK3RT1K',
//                        'public_key'     => '908f2f2b-eda0-4210-a1b3-9d8c1b0bcfc7',
//                        'ipn_secret_key' => 'lnbcIgz7iA/cOjh/K6mqn5lz+JfO0Kjj',
//                    ]),
//                    'status'  => false,
//                ],
//
//                [
//                    'name'    => 'MercadoPago',
//                    'type'    => PaymentMethods::TYPE_MERCADOPAGO,
//                    'options' => json_encode([
//                        'access_token' => 'access_token',
//                        'public_key'   => 'public_key',
//                        'environment'  => 'server',
//                    ]),
//                    'status'  => false,
//                ],

                [
                    'name'    => 'Offline Payment',
                    'type'    => 'offline_payment',
                    'options' => json_encode([
                        'payment_details'      => '<p>Please make a deposit to our bank account at:</p>
<h6>US BANK USA</h6>
<p>Routing (ABA): 045134400</p>
<p>Account number: 6216587467378</p>
<p>Beneficiary name: Ultimate sms</p>',
                        'payment_confirmation' => 'After payment please contact with following email address codeglen@gmail.com with your transaction id. Normally it may take 1 - 2 business days to process. Should you have any question, feel free contact with us.',
                    ]),
                    'status'  => true,
                ],
            ];

            foreach ($payment_gateways as $gateway) {
                PaymentMethods::create($gateway);
            }
        }

    }
