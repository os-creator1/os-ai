@extends('layouts/contentLayoutMaster')

@section('title', __('locale.labels.pay_payment'))

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-content">
                        <div class="card-body">
                            <form class="" role="form" method="post" action="{{ $post_url }}">
                                @csrf

                                <div class="mb-1">
                                    <label for="phone" class="required form-label">{{ __('locale.labels.phone') }}</label>
                                    <div class="input-group input-group-merge">
                                        <span class="input-group-text">+258</span>
                                        <input type="text" maxlength="11" id="phone" class="form-control prefix-mask @error('phone') is-invalid @enderror" value="{{ old('phone') }}" name="phone" required placeholder="{{__('locale.labels.required')}}" autofocus>
                                    </div>

                                    @error('phone')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                    @enderror
                                </div>


                                <div class="mb-1">
                                    <button type="submit" class="btn btn-primary">{{ __('locale.labels.pay_payment') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

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
