@extends('layouts/fullLayoutMaster')

@section('title', __('locale.labels.pay_payment'))

@section('content')

    <div class="auth-wrapper auth-cover">
        <div class="auth-inner row m-0">
            <!-- Brand logo-->
            <a class="brand-logo" href="{{route('login')}}">
                <img src="{{asset(config('app.logo'))}}" alt="{{config('app.name')}}"/>
            </a>
            <!-- /Brand logo-->

            <!-- Left Text-->
            <div class="col-lg-3 d-none d-lg-flex align-items-center p-0">
                <div class="w-100 d-lg-flex align-items-center justify-content-center">
                    <img class="img-fluid w-100" src="{{asset('images/pages/create-account.svg')}}" alt="{{config('app.name')}}"/>
                </div>
            </div>
            <!-- /Left Text-->

            <!-- Register-->
            <div class="col-lg-9 d-flex align-items-center auth-bg px-2 px-sm-3 px-lg-5 pt-3">
                <div class="width-800 mx-auto card px-2 py-2">
                    <div class="card">
                        <div class="card-header">{{ __('locale.labels.pay_payment') }}</div>
                        <div class="card-content text-center">
                            <div class="card-body">
                                <form action="{{ route('customer.callback.fedapay') }}" method="POST">
                                    @csrf
                                    @foreach($postData as $key => $value)
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endforeach
                                    <script
                                            src="https://cdn.fedapay.com/checkout.js?v=1.1.7"
                                            data-public-key="{{ $public_key }}"
                                            data-button-text="{{ __('locale.labels.pay').' '.$amount }} FCFA"
                                            data-button-class="btn btn-success"
                                            data-transaction-amount="{{ $amount }}"
                                            data-customer-email="{{ $email }}"
                                            data-customer-firstname="{{ $first_name }}"
                                            data-customer-lastname="{{ $last_name }}"
                                            data-transaction-description="{{$item_name}}"
                                            data-currency-iso="XOF">
                                    </script>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection
