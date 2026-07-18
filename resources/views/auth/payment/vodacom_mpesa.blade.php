@extends('layouts/fullLayoutMaster')

@section('title', __('locale.auth.register'))

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
                    <form class="" role="form" method="post" action="{{ $post_url }}">
                        @csrf

                        <div class="row">
                            <div class="col-12">
                                <div class="mb-1">
                                    <label for="phone" class="required form-label">{{ __('locale.labels.phone') }}</label>
                                    <div class="input-group input-group-merge">
                                        <span class="input-group-text">+258</span>
                                        <input maxlength="11" type="text" id="phone" class="form-control prefix-mask @error('phone') is-invalid @enderror" value="{{ old('phone') }}" name="phone" required placeholder="{{__('locale.labels.required')}}" autofocus>
                                    </div>

                                    @error('phone')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <div class="mb-1">
                            <button type="submit" class="btn btn-primary themeButton" id="confirm-purchase">{{ __('locale.labels.pay_payment') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection


@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/cleave/cleave.min.js'))}}"></script>
    <script src="{{ asset(mix('vendors/js/forms/cleave/addons/cleave-phone.mz.js'))}}"></script>
@endsection

@section('page-script')
    <!-- Page js files -->
    <script>

        $(document).ready(function () {
            "use strict"

            let firstInvalid = $('form').find('.is-invalid').eq(0),
                prefixMask = $('.prefix-mask');


            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }

            if (prefixMask.length) {
                new Cleave(prefixMask, {
                    phone: true,
                    phoneRegionCode: 'MZ'
                });
            }

        });
    </script>

@endsection
