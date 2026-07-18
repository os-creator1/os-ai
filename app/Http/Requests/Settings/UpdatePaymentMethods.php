<?php

    namespace App\Http\Requests\Settings;

    use App\Models\PaymentMethods;
    use Illuminate\Foundation\Http\FormRequest;

    class UpdatePaymentMethods extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return $this->user()->can('update payment_gateways');
        }

        /**
         * Get the validation rules that apply to the request.
         */
        public function rules(): array
        {

            $type = $this->input('type');

            $rules = [
                'name'       => 'required',
                'is_default' => 'nullable|boolean',
            ];

            switch ($type) {
                case PaymentMethods::TYPE_PAYPAL:
                case PaymentMethods::TYPE_SMANAGER:
                    $rules['client_id']   = 'required';
                    $rules['secret']      = 'required';
                    $rules['environment'] = 'required';
                    break;

                case PaymentMethods::TYPE_BRAINTREE:
                    $rules['merchant_id'] = 'required';
                    $rules['public_key']  = 'required';
                    $rules['private_key'] = 'required';
                    $rules['environment'] = 'required';
                    break;

                case PaymentMethods::TYPE_STRIPE:
                    $rules['publishable_key'] = 'required';
                    $rules['secret_key']      = 'required';
                    $rules['environment']     = 'required';
                    break;

                case PaymentMethods::TYPE_AUTHORIZE_NET:
                    $rules['login_id']        = 'required';
                    $rules['transaction_key'] = 'required';
                    $rules['environment']     = 'required';
                    break;

                case PaymentMethods::TYPE_2CHECKOUT:
                    $rules['merchant_code'] = 'required';
                    $rules['private_key']   = 'required';
                    $rules['environment']   = 'required';
                    break;

                case PaymentMethods::TYPE_PAYSTACK:
                    $rules['public_key']     = 'required';
                    $rules['secret_key']     = 'required';
                    $rules['merchant_email'] = 'required|email';
                    break;

                case PaymentMethods::TYPE_PAYU:
                    $rules['client_id']     = 'required';
                    $rules['client_secret'] = 'required';
                    break;

                case PaymentMethods::TYPE_SLYDEPAY:
                    $rules['merchant_email']  = 'required|email';
                    $rules['merchant_secret'] = 'required';
                    break;

                case PaymentMethods::TYPE_PAYNOW:
                    $rules['integration_id']  = 'required';
                    $rules['integration_key'] = 'required';
                    break;

                case PaymentMethods::TYPE_COINPAYMENTS:
                    $rules['merchant_id'] = 'required';
                    break;

                case PaymentMethods::TYPE_INSTAMOJO:
                    $rules['api_key']    = 'required';
                    $rules['auth_token'] = 'required';
                    break;

                case PaymentMethods::TYPE_PAYGATEGLOBAL:
                    $rules['api_key'] = 'required';
                    break;

                case PaymentMethods::TYPE_PAYUMONEY:
                    $rules['merchant_key']  = 'required';
                    $rules['merchant_salt'] = 'required';
                    $rules['environment']   = 'required';
                    break;

                case PaymentMethods::TYPE_RAZORPAY:
                    $rules['key_id']      = 'required';
                    $rules['key_secret']  = 'required';
                    $rules['environment'] = 'required';
                    break;

                case PaymentMethods::TYPE_SSLCOMMERZ:
                    $rules['store_id']     = 'required';
                    $rules['store_passwd'] = 'required';
                    $rules['environment']  = 'required';
                    break;

                case PaymentMethods::TYPE_AAMARPAY:
                    $rules['store_id']      = 'required';
                    $rules['signature_key'] = 'required';
                    $rules['environment']   = 'required';
                    break;

                case PaymentMethods::TYPE_DIRECTPAYONLINE:
                    $rules['company_token'] = 'required';
                    $rules['account_type']  = 'required';
                    $rules['environment']   = 'required';
                    break;

                case PaymentMethods::TYPE_ORANGEMONEY:
                    $rules['merchant_key'] = 'required';
                    $rules['auth_header']  = 'required';
                    $rules['payment_url']  = 'required';
                    break;

                case PaymentMethods::TYPE_CINETPAY:
                    $rules['api_key']     = 'required';
                    $rules['site_id']     = 'required';
                    $rules['secret_key']  = 'required';
                    $rules['payment_url'] = 'required';
                    break;

                case PaymentMethods::TYPE_AZAMPAY:
                    $rules['app_name']       = 'required';
                    $rules['account_number'] = 'required';
                    $rules['client_id']      = 'required';
                    $rules['client_secret']  = 'required';
                    $rules['provider']       = 'required';
                    $rules['environment']    = 'required';
                    break;

                case PaymentMethods::TYPE_VODACOMMPESA:
                    $rules['apiKey']              = 'required';
                    $rules['publicKey']           = 'required';
                    $rules['serviceProviderCode'] = 'required';
                    $rules['payment_url']         = 'required';
                    break;

                case PaymentMethods::TYPE_PAYHERELK:
                    $rules['merchant_id']     = 'required';
                    $rules['merchant_secret'] = 'required';
                    $rules['app_id']          = 'required';
                    $rules['app_secret']      = 'required';
                    $rules['environment']     = 'required';
                    break;

                case PaymentMethods::TYPE_MOLLIE:
                    $rules['api_key']     = 'required';
                    $rules['environment'] = 'required';
                    break;

                case PaymentMethods::TYPE_EASYPAY:
                    $rules['account_id']   = 'required';
                    $rules['api_key']      = 'required';
                    $rules['merchant_key'] = 'required';
                    $rules['environment']  = 'required';
                    $rules['payment_url']  = 'required';
                    break;

                case PaymentMethods::TYPE_FLUTTERWAVE:
                case PaymentMethods::TYPE_MAYA:
                case PaymentMethods::TYPE_FEDAPAY:
                    $rules['public_key']  = 'required';
                    $rules['secret_key']  = 'required';
                    $rules['environment'] = 'required';
                    break;

                case PaymentMethods::TYPE_SELCOMMOBILE:
                    $rules['api_key']     = 'required';
                    $rules['api_secret']  = 'required';
                    $rules['vendor']      = 'required';
                    $rules['payment_url'] = 'required';
                    break;

                case PaymentMethods::TYPE_LIQPAY:
                    $rules['public_key']  = 'required';
                    $rules['private_key'] = 'required';
                    $rules['payment_url'] = 'required';
                    break;

                case PaymentMethods::TYPE_PAYTECH:
                    $rules['api_key']     = 'required';
                    $rules['api_secret']  = 'required';
                    $rules['environment'] = 'required';
                    break;

                case PaymentMethods::TYPE_MPGS:
                    $rules['payment_url']             = 'required';
                    $rules['api_version']             = 'required';
                    $rules['merchant_id']             = 'required';
                    $rules['authentication_password'] = 'required';
                    break;

                case PaymentMethods::TYPE_0XPROCESSING:
                    $rules['api_key']     = 'required';
                    $rules['merchant_id'] = 'required';
                    $rules['environment'] = 'required';
                    break;

                case PaymentMethods::TYPE_MYFATOORAH:
                    $rules['api_token']        = 'required';
                    $rules['country_iso_code'] = 'required|in:KWT,SAU,ARE,QAT,BHR,OMN,JOD,EGY';
                    $rules['environment']      = 'required';
                    break;

                case PaymentMethods::TYPE_NOWPAYMENTS:
                    $rules['api_key']        = 'required|string';
                    $rules['public_key']     = 'required|string';
                    $rules['ipn_secret_key'] = 'required|string';
                    $rules['payment_url']    = 'required|url';
                    break;

                case PaymentMethods::TYPE_CASH:
                    $rules['payment_details']      = 'required';
                    $rules['payment_confirmation'] = 'required';
                    break;

            }

            return $rules;
        }

    }
