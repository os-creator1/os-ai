@extends('layouts/contentLayoutMaster')

@section('title', __('locale.labels.pay_payment'))

@section('content')
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
@endsection
