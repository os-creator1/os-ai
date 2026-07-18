@extends('layouts/contentLayoutMaster')

@section('title', __('locale.labels.pay_payment'))

@section('page-style')
    <style>
        .card-body p {
            line-height: 0.8;
        }
    </style>
@endsection

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-content">
                        <div class="card-body">
                            {!! $data->payment_details !!}
                            <br>
                            <h6 class="text-uppercase">For {{ __('locale.labels.payment_confirmation') }}:</h6>
                            {!! $data->payment_confirmation !!}

                            <form action="{{ route('customer.payment.offline', $type) }}" method="post" class="mt-2">
                                <input type="hidden" name="post_data" value="{{ $post_data }}">
                                @if(isset($sms_unit))
                                    <input type="hidden" name="sms_unit" value="{{ $sms_unit }}">
                                @endif
                                <button type="submit" class="btn btn-success me-1 btn-submit">{{ __('locale.labels.claim_payment') }}</button>
                                <a href="{{ route('user.home') }}"
                                   class="btn btn-primary">{{ __('locale.buttons.back') }}</a>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection
