@php use App\Models\PaymentMethods; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', $gateway->name)

@section('content')

    {{-- Vertical Tabs start --}}
    <section id="vertical-tabs">

        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card overflow-hidden">
                    <div class="card-header">
                        <h4 class="card-title">{{ $gateway->name }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">

                            @switch($gateway->type)
                                @case(PaymentMethods::TYPE_PAYPAL)
                                    <p>{!!  __('locale.description.paypal') !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_BRAINTREE)
                                    <p>{!!  __('locale.description.braintree') !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_STRIPE)
                                    <p>{!!  __('locale.description.stripe') !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_AUTHORIZE_NET)
                                    <p>{!!  __('locale.description.authorize_net') !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_2CHECKOUT)
                                    <p>{!!  __('locale.description.2checkout') !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_PAYSTACK)
                                    <p>{!!  __('locale.description.paystack', ['callback_url' => route('customer.callback.paystack')]) !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_PAYNOW)
                                    <p>{!!  __('locale.description.paynow') !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_RAZORPAY)
                                    <p>{!!  __('locale.description.razorpay',[
                                       'callback_url_senderid' => route('customer.callback.razorpay.senderid'),
                                       'callback_url_keywords' => route('customer.callback.razorpay.keywords'),
                                       'callback_url_subscriptions' => route('customer.callback.razorpay.subscriptions'),
                                       'callback_url_numbers' => route('customer.callback.razorpay.numbers'),
                                       'callback_url_topup' => route('customer.callback.razorpay.top_up'),
                                        ])!!}</p>
                                    @break


                                @case(PaymentMethods::TYPE_PAYHERELK)
                                    <p>{!!  __('locale.description.payherelk', ['app_url' => config('app.url')]) !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_EASYPAY)
                                    <p>{!!  __('locale.description.easypay', ['app_name' => config('app.name')]) !!}</p>
                                    @break

                                @case(PaymentMethods::TYPE_COINPAYMENTS)
                                    <p>Please add this URL on your Coinpayments <code>IPN_URL</code> settings
                                        <code>{{ route('customer.callback.coinpayments.ipn') }}</code> to receive
                                        payments notifications. </p>
                                    @break

                                @case(PaymentMethods::TYPE_NOWPAYMENTS)
                                    <p>
                                        To start using the <code>NOWPayments API</code>, follow these steps:
                                        Sign up at
                                        <a href="https://nowpayments.io/" target="_blank">https://nowpayments.io/</a>,
                                        set your payout wallet, and generate both your <code>API Key</code> and <code>IPN
                                            Secret Key</code>.
                                        <strong>Important:</strong> The <code>IPN Secret Key</code> is only visible at
                                        the time of creation.
                                        Be sure to save it securely after it's generated.Finally, insert this callback
                                        url <code>{{ route('customer.callback.nowpayments.ipn') }}</code> on your <code>Webhook
                                            URL</code> field.
                                    </p>
                                    @break

                            @endswitch

                            <form class="form form-vertical"
                                  action="{{ route('admin.payment-gateways.update', $gateway->uid) }}" method="post">
                                @method('PUT')
                                @csrf
                                <div class="form-body">
                                    <div class="row">

                                        <div class="col-12">
                                            <div class="mb-1">
                                                <label for="name"
                                                       class="form-label required">{{ __('locale.labels.name') }}</label>
                                                <input type="text" id="secret" name="name" class="form-control"
                                                       value="{{ $gateway->name }}" required>
                                                <p>
                                                    <small class="text-primary">{{__('locale.payment_gateways.rename_name')}}</small>
                                                </p>
                                                @error('name')
                                                <div class="text-danger">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        </div>

                                        {{-- is_default checkbox --}}
                                        <div class="col-12">
                                            <div class="mb-1">
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" id="is_default"
                                                           name="is_default" value="1"
                                                           @if($gateway->is_default) checked @endif>
                                                    <label class="form-check-label required"
                                                           for="is_default">{{ __('locale.labels.default') }}</label>
                                                </div>
                                                <p>
                                                    <small class="text-primary">{{__('locale.payment_gateways.set_as_default_description')}}</small>
                                                </p>
                                            </div>
                                        </div>

                                        @if($gateway->type == PaymentMethods::TYPE_PAYPAL || $gateway->type == PaymentMethods::TYPE_SMANAGER)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="client_id"
                                                           class="form-label required">{{ __('locale.labels.client_id') }}</label>
                                                    <input type="text" id="client_id" name="client_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('client_id') }}" required>
                                                    @error('client_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="secret"
                                                           class="form-label required">{{ __('locale.labels.client_secret') }}</label>
                                                    <input type="text" id="secret" name="secret" class="form-control"
                                                           value="{{ $gateway->getOption('secret') }}" required>
                                                    @error('secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_BRAINTREE)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_id"
                                                           class="form-label required">{{ __('locale.labels.merchant_id') }}</label>
                                                    <input type="text" id="merchant_id" name="merchant_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_id') }}" required>
                                                    @error('merchant_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="public_key"
                                                           class="form-label required">{{ __('locale.labels.public_key') }}</label>
                                                    <input type="text" id="public_key" name="public_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('public_key') }}" required>
                                                    @error('public_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="private_key"
                                                           class="form-label required">{{ __('locale.labels.private_key') }}</label>
                                                    <input type="text" id="private_key" name="private_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('private_key') }}" required>
                                                    @error('private_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_STRIPE)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="publishable_key"
                                                           class="form-label required">{{ __('locale.labels.publishable_key') }}</label>
                                                    <input type="text" id="publishable_key" name="publishable_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('publishable_key') }}"
                                                           required>
                                                    @error('publishable_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="secret_key"
                                                           class="form-label required">{{ __('locale.labels.secret_key') }}</label>
                                                    <input type="text" id="secret_key" name="secret_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('secret_key') }}" required>
                                                    @error('secret_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_AUTHORIZE_NET)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="login_id"
                                                           class="form-label required">{{ __('locale.labels.login_id') }}</label>
                                                    <input type="text" id="login_id" name="login_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('login_id') }}" required>
                                                    @error('login_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="transaction_key"
                                                           class="form-label required">{{ __('locale.labels.transaction_key') }}</label>
                                                    <input type="text" id="transaction_key" name="transaction_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('transaction_key') }}"
                                                           required>
                                                    @error('transaction_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_2CHECKOUT)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_code"
                                                           class="form-label required">{{ __('locale.labels.merchant_code') }}</label>
                                                    <input type="text" id="merchant_code" name="merchant_code"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_code') }}" required>
                                                    @error('merchant_code')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="private_key"
                                                           class="form-label required">{{ __('locale.labels.private_key') }}</label>
                                                    <input type="text" id="private_key" name="private_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('private_key') }}" required>
                                                    @error('private_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_PAYSTACK)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="public_key"
                                                           class="form-label required">{{ __('locale.labels.public_key') }}</label>
                                                    <input type="text" id="public_key" name="public_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('public_key') }}" required>
                                                    @error('public_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="secret_key"
                                                           class="form-label required">{{ __('locale.labels.secret_key') }}</label>
                                                    <input type="text" id="secret_key" name="secret_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('secret_key') }}" required>
                                                    @error('secret_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_email"
                                                           class="form-label required">{{ __('locale.labels.merchant_email') }}</label>
                                                    <input type="email" id="merchant_email" name="merchant_email"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_email') }}" required>
                                                    @error('merchant_email')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_PAYU)
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="client_id"
                                                           class="form-label required">{{ __('locale.labels.client_id') }}</label>
                                                    <input type="text" id="client_id" name="client_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('client_id') }}" required>
                                                    @error('client_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="client_secret"
                                                           class="form-label required">{{ __('locale.labels.client_secret') }}</label>
                                                    <input type="text" id="secret" name="client_secret"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('client_secret') }}" required>
                                                    @error('client_secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_SLYDEPAY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_email"
                                                           class="form-label required">{{ __('locale.labels.merchant_email') }}</label>
                                                    <input type="email" id="merchant_email" name="merchant_email"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_email') }}" required>
                                                    @error('merchant_email')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_secret"
                                                           class="form-label required">{{ __('locale.labels.merchant_secret') }}</label>
                                                    <input type="text" id="secret" name="merchant_secret"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_secret') }}"
                                                           required>
                                                    @error('merchant_secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_PAYNOW)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="integration_id"
                                                           class="form-label required">{{ __('locale.labels.integration_id') }}</label>
                                                    <input type="text" id="integration_id" name="integration_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('integration_id') }}" required>
                                                    @error('integration_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="integration_key"
                                                           class="form-label required">{{ __('locale.labels.integration_key') }}</label>
                                                    <input type="text" id="secret" name="integration_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('integration_key') }}"
                                                           required>
                                                    @error('integration_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_COINPAYMENTS)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_id"
                                                           class="form-label required">{{ __('locale.labels.merchant_id') }}</label>
                                                    <input type="text" id="secret" name="merchant_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_id') }}" required>
                                                    @error('merchant_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_INSTAMOJO)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key"
                                                           class="form-label required">{{ __('locale.labels.api_key') }}</label>
                                                    <input type="text" id="secret" name="api_key" class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="auth_token"
                                                           class="form-label required">{{ __('locale.labels.auth_token') }}</label>
                                                    <input type="text" id="secret" name="auth_token"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('auth_token') }}" required>
                                                    @error('auth_token')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_PAYGATEGLOBAL)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key"
                                                           class="form-label required">{{ __('locale.labels.api_key') }}</label>
                                                    <input type="text" id="secret" name="api_key" class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_PAYUMONEY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_key"
                                                           class="form-label required">{{ __('locale.labels.merchant_key') }}</label>
                                                    <input type="text" id="secret" name="merchant_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_key') }}" required>
                                                    @error('merchant_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_salt"
                                                           class="form-label required">{{ __('locale.labels.merchant_salt') }}</label>
                                                    <input type="text" id="secret" name="merchant_salt"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_salt') }}" required>
                                                    @error('merchant_salt')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_RAZORPAY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="key_id"
                                                           class="form-label required">{{ __('locale.labels.key_id') }}</label>
                                                    <input type="text" id="secret" name="key_id" class="form-control"
                                                           value="{{ $gateway->getOption('key_id') }}" required>
                                                    @error('key_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="key_secret"
                                                           class="form-label required">{{ __('locale.labels.key_secret') }}</label>
                                                    <input type="text" id="secret" name="key_secret"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('key_secret') }}" required>
                                                    @error('key_secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_SSLCOMMERZ)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="store_id"
                                                           class="form-label required">{{ __('locale.labels.store_id') }}</label>
                                                    <input type="text" id="secret" name="store_id" class="form-control"
                                                           value="{{ $gateway->getOption('store_id') }}" required>
                                                    @error('store_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="store_passwd"
                                                           class="form-label required">{{ __('locale.labels.store_password') }}</label>
                                                    <input type="text" id="secret" name="store_passwd"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('store_passwd') }}" required>
                                                    @error('store_passwd')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_AAMARPAY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="store_id"
                                                           class="form-label required">{{ __('locale.labels.store_id') }}</label>
                                                    <input type="text" id="secret" name="store_id" class="form-control"
                                                           value="{{ $gateway->getOption('store_id') }}" required>
                                                    @error('store_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="signature_key"
                                                           class="form-label required">{{ __('locale.labels.signature_key') }}</label>
                                                    <input type="text" id="signature_key" name="signature_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('signature_key') }}" required>
                                                    @error('signature_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>
                                        @elseif($gateway->type == PaymentMethods::TYPE_FLUTTERWAVE || $gateway->type == PaymentMethods::TYPE_MAYA)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="public_key"
                                                           class="form-label required">{{ __('locale.labels.public_key') }}</label>
                                                    <input type="text" id="secret" name="public_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('public_key') }}" required>
                                                    @error('public_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="secret_key"
                                                           class="form-label required">{{ __('locale.labels.secret_key') }}</label>
                                                    <input type="text" id="secret_key" name="secret_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('secret_key') }}" required>
                                                    @error('secret_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_DIRECTPAYONLINE)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="company_token" class="form-label required">Company
                                                        Token</label>
                                                    <input type="text" id="company_token" name="company_token"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('company_token') }}" required>
                                                    @error('company_token')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="account_type" class="form-label required">Account
                                                        Type</label>
                                                    <input type="text" id="account_type" name="account_type"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('account_type') }}" required>
                                                    @error('account_type')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_ORANGEMONEY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_url" class="form-label required">Payment
                                                        URL</label>
                                                    <input type="url" id="payment_url" name="payment_url"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('payment_url') }}" required>
                                                    @error('payment_url')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_key" class="form-label required">Merchant
                                                        Key</label>
                                                    <input type="text" id="merchant_key" name="merchant_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_key') }}" required>
                                                    @error('merchant_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="auth_header" class="form-label required">Authorization
                                                        Header</label>
                                                    <input type="text" id="auth_header" name="auth_header"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('auth_header') }}" required>
                                                    @error('auth_header')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_CINETPAY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_url" class="form-label required">Payment
                                                        URL</label>
                                                    <input type="url" id="payment_url" name="payment_url"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('payment_url') }}" required>
                                                    @error('payment_url')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key" class="form-label required">API Key</label>
                                                    <input type="text" id="api_key" name="api_key" class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="site_id" class="form-label required">Site ID</label>
                                                    <input type="text" id="site_id" name="site_id" class="form-control"
                                                           value="{{ $gateway->getOption('site_id') }}" required>
                                                    @error('site_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="secret_key" class="form-label required">Secret
                                                        Key</label>
                                                    <input type="text" id="secret_key" name="secret_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('secret_key') }}" required>
                                                    @error('secret_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_AZAMPAY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="app_name" class="form-label required">App Name</label>
                                                    <input type="text" id="app_name" name="app_name"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('app_name') }}" required>
                                                    @error('app_name')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="account_number" class="form-label required">Account
                                                        Number</label>
                                                    <input type="text" id="account_number" name="account_number"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('account_number') }}" required>
                                                    @error('account_number')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="client_id"
                                                           class="form-label required">{{ __('locale.labels.client_id') }}</label>
                                                    <input type="text" id="client_id" name="client_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('client_id') }}" required>
                                                    @error('client_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="client_secret"
                                                           class="form-label required">{{ __('locale.labels.client_secret') }}</label>
                                                    <input type="text" id="client_secret" name="client_secret"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('client_secret') }}" required>
                                                    @error('client_secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="provider" class="form-label required">Provider</label>
                                                    <select class="form-select" name="provider" id="provider">
                                                        <option value="Airtel"
                                                                @if($gateway->getOption('provider') == 'Airtel' ) selected @endif>
                                                            Airtel
                                                        </option>
                                                        <option value="Tigo"
                                                                @if($gateway->getOption('provider') == 'Tigo' ) selected @endif>
                                                            Tigo
                                                        </option>
                                                        <option value="Halopesa"
                                                                @if($gateway->getOption('provider') == 'Halopesa' ) selected @endif>
                                                            Halopesa
                                                        </option>
                                                        <option value="Azampesa"
                                                                @if($gateway->getOption('provider') == 'Azampesa' ) selected @endif>
                                                            Azampesa
                                                        </option>
                                                    </select>
                                                    @error('provider')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_VODACOMMPESA)
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_url" class="form-label required">Payment
                                                        URL</label>
                                                    <input type="url" id="payment_url" name="payment_url"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('payment_url') }}" required>
                                                    @error('payment_url')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="apiKey"
                                                           class="form-label required">{{ __('locale.labels.api_key') }}</label>
                                                    <input type="text" id="apiKey" name="apiKey" class="form-control"
                                                           value="{{ $gateway->getOption('apiKey') }}" required>
                                                    @error('apiKey')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="publicKey"
                                                           class="form-label required">{{ __('locale.labels.public_key') }}</label>
                                                    <input type="text" id="publicKey" name="publicKey"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('publicKey') }}" required>
                                                    @error('publicKey')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="serviceProviderCode" class="form-label required">Service
                                                        Provider Code</label>
                                                    <input type="text" id="serviceProviderCode"
                                                           name="serviceProviderCode" class="form-control"
                                                           value="{{ $gateway->getOption('serviceProviderCode') }}"
                                                           required>
                                                    @error('serviceProviderCode')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_PAYHERELK)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_id"
                                                           class="form-label required">{{ __('locale.labels.merchant_id') }}</label>
                                                    <input type="text" id="secret" name="merchant_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_id') }}" required>
                                                    @error('merchant_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_secret"
                                                           class="form-label required">{{ __('locale.labels.merchant_secret') }}</label>
                                                    <input type="text" id="secret" name="merchant_secret"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_secret') }}"
                                                           required>
                                                    @error('merchant_secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="app_id" class="form-label required">APP ID</label>
                                                    <input type="text" id="secret" name="app_id" class="form-control"
                                                           value="{{ $gateway->getOption('app_id') }}" required>
                                                    @error('app_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="app_secret" class="form-label required">APP
                                                        Secret</label>
                                                    <input type="text" id="secret" name="app_secret"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('app_secret') }}" required>
                                                    @error('app_secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_MOLLIE)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key"
                                                           class="form-label required">{{ __('locale.labels.api_key') }}</label>
                                                    <input type="text" id="secret" name="api_key" class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_EASYPAY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_url" class="form-label required">Payment
                                                        URL</label>
                                                    <input type="url" id="payment_url" name="payment_url"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('payment_url') }}" required>
                                                    @error('payment_url')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="account_id" class="form-label required">Account
                                                        ID</label>
                                                    <input type="text" id="account_id" name="account_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('account_id') }}" required>
                                                    @error('account_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key"
                                                           class="form-label required">{{ __('locale.labels.api_key') }}</label>
                                                    <input type="text" id="api_key" name="api_key" class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_key" class="form-label required">Merchant
                                                        identification key</label>
                                                    <input type="text" id="merchant_key" name="merchant_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_key') }}" required>
                                                    @error('merchant_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_FEDAPAY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="public_key"
                                                           class="form-label required">{{ __('locale.labels.public_key') }}</label>
                                                    <input type="text" id="public_key" name="public_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('public_key') }}" required>
                                                    @error('public_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="secret_key"
                                                           class="form-label required">{{ __('locale.labels.secret_key') }}</label>
                                                    <input type="text" id="secret_key" name="secret_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('secret_key') }}" required>
                                                    @error('secret_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_SELCOMMOBILE)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_url" class="form-label required">Payment
                                                        URL</label>
                                                    <input type="url" id="payment_url" name="payment_url"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('payment_url') }}" required>
                                                    @error('payment_url')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="vendor" class="form-label required">Vendor</label>
                                                    <input type="text" id="vendor" name="vendor" class="form-control"
                                                           value="{{ $gateway->getOption('vendor') }}" required>
                                                    @error('vendor')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key"
                                                           class="form-label required">{{ __('locale.labels.api_key') }}</label>
                                                    <input type="text" id="api_key" name="api_key" class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_secret" class="form-label required">API
                                                        Secret</label>
                                                    <input type="text" id="api_secret" name="api_secret"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('api_secret') }}" required>
                                                    @error('api_secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_LIQPAY)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="public_key"
                                                           class="form-label required">{{ __('locale.labels.public_key') }}</label>
                                                    <input type="text" id="public_key" name="public_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('public_key') }}" required>
                                                    @error('public_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="private_key"
                                                           class="form-label required">{{ __('locale.labels.private_key') }}</label>
                                                    <input type="text" id="private_key" name="private_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('private_key') }}" required>
                                                    @error('private_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_PAYTECH)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key"
                                                           class="form-label required">{{ __('locale.labels.api_key') }}</label>
                                                    <input type="text" id="api_key" name="api_key" class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_secret" class="form-label required">API
                                                        Secret</label>
                                                    <input type="text" id="api_secret" name="api_secret"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('api_secret') }}" required>
                                                    @error('api_secret')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_MPGS)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_url" class="form-label required">Payment
                                                        URL</label>
                                                    <input type="url" id="payment_url" name="payment_url"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('payment_url') }}" required>
                                                    @error('payment_url')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_version" class="form-label required">API
                                                        Version</label>
                                                    <input type="number" id="api_version" name="api_version"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('api_version') }}" required>
                                                    @error('api_version')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_id" class="form-label required">Merchant
                                                        ID</label>
                                                    <input type="text" id="merchant_id" name="merchant_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_id') }}" required>
                                                    @error('merchant_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="authentication_password" class="form-label required">Authentication
                                                        Password</label>
                                                    <input type="text" id="authentication_password"
                                                           name="authentication_password" class="form-control"
                                                           value="{{ $gateway->getOption('authentication_password') }}"
                                                           required>
                                                    @error('authentication_password')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_name" class="form-label">Merchant Name</label>
                                                    <input type="text" id="merchant_name"
                                                           name="merchant_name" class="form-control"
                                                           value="{{ $gateway->getOption('merchant_name') }}">
                                                    @error('merchant_name')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_address" class="form-label">Merchant
                                                        Address</label>
                                                    <input type="text" id="merchant_address"
                                                           name="merchant_address" class="form-control"
                                                           value="{{ $gateway->getOption('merchant_address') }}">
                                                    @error('merchant_address')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_0XPROCESSING)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="merchant_id" class="form-label required">Merchant
                                                        ID</label>
                                                    <input type="text" id="merchant_id" name="merchant_id"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('merchant_id') }}" required>
                                                    @error('merchant_id')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key" class="form-label required">API Key</label>
                                                    <input type="text" id="api_key" name="api_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_MYFATOORAH)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="sandbox"
                                                                @if($gateway->getOption('environment') == 'sandbox' ) selected @endif>{{ __('locale.labels.sandbox') }}</option>
                                                        <option value="production"
                                                                @if($gateway->getOption('environment') == 'production' ) selected @endif>{{ __('locale.labels.production')}} </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_token" class="form-label required">API Token</label>
                                                    <input type="text" id="api_token" name="api_token"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('api_token') }}" required>
                                                    @error('api_token')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="country_iso_code" class="form-label required">Country
                                                        ISO Code</label>
                                                    <input type="text" id="country_iso_code" name="country_iso_code"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('country_iso_code') }}"
                                                           required>
                                                    @error('country_iso_code')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                    <p><small class="text-primary">Accepted value: KWT, SAU, ARE, QAT,
                                                            BHR, OMN, JOD, or EGY.</small></p>
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_NOWPAYMENTS)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_url" class="form-label required">Payment
                                                        URL</label>
                                                    <input type="url" id="payment_url" name="payment_url"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('payment_url') }}" required>
                                                    @error('payment_url')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="api_key" class="form-label required">API Key</label>
                                                    <input type="text" id="api_key" name="api_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('api_key') }}" required>
                                                    @error('api_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="public_key" class="form-label required">Public
                                                        Key</label>
                                                    <input type="text" id="public_key" name="public_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('public_key') }}" required>
                                                    @error('public_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="ipn_secret_key" class="form-label required">IPN Secret
                                                        Key</label>
                                                    <input type="text" id="ipn_secret_key" name="ipn_secret_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('ipn_secret_key') }}" required>
                                                    @error('ipn_secret_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_MERCADOPAGO)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="environment"
                                                           class="form-label required">{{ __('locale.labels.environment') }}</label>
                                                    <select class="form-select" name="environment" id="environment">
                                                        <option value="local"
                                                                @if($gateway->getOption('environment') == 'local' ) selected @endif>
                                                            Local
                                                        </option>
                                                        <option value="server"
                                                                @if($gateway->getOption('environment') == 'server' ) selected @endif>
                                                            Server
                                                        </option>
                                                    </select>
                                                    @error('environment')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="access_token"
                                                           class="form-label required">Access Token</label>
                                                    <input type="text" id="access_token" name="access_token"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('access_token') }}" required>
                                                    @error('access_token')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="public_key"
                                                           class="form-label required">{{ __('locale.labels.public_key') }}</label>
                                                    <input type="text" id="secret" name="public_key"
                                                           class="form-control"
                                                           value="{{ $gateway->getOption('public_key') }}" required>
                                                    @error('public_key')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>

                                        @elseif($gateway->type == PaymentMethods::TYPE_CASH)

                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_details"
                                                           class="form-label">{{ __('locale.labels.payment_details') }}</label>
                                                    <textarea rows="7" class="form-control" id="payment_details"
                                                              name="payment_details"
                                                              required>{!! $gateway->getOption('payment_details') !!}</textarea>
                                                    @error('payment_details')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>


                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="payment_confirmation"
                                                           class="form-label required">{{ __('locale.labels.payment_confirmation') }}</label>
                                                    <textarea rows="7" class="form-control" name="payment_confirmation"
                                                              required>{!! $gateway->getOption('payment_confirmation') !!}</textarea>
                                                    @error('payment_confirmation')
                                                    <div class="text-danger">
                                                        {{ $message }}
                                                    </div>
                                                    @enderror
                                                </div>
                                            </div>
                                        @else
                                            <div class="col-12">
                                                <p class="text-danger text-bold-600">{{ __('locale.payment_gateways.not_found') }}</p>
                                            </div>
                                        @endif

                                        <div class="col-12">
                                            <input type="hidden" value="{{$gateway->type}}" name="type">
                                            <button type="submit" class="btn btn-primary mb-1"><i
                                                        data-feather="save"></i> {{ __('locale.buttons.save') }}
                                            </button>
                                        </div>

                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    {{-- Vertical Tabs end --}}
@endsection

