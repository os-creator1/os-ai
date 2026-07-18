@extends('layouts/contentLayoutMaster')

@section('title', __('locale.phone_numbers.buy_number'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title"> {{__('locale.phone_numbers.buy_number')}} </h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body">
                            <form class="form form-vertical" action="{{ route('customer.numbers.buy.api', $sending_server->uid) }}" method="post">
                                @csrf
                                <div class="row">

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="country" class="required form-label">{{__('locale.labels.country')}}</label>
                                            <select class="select2 w-100" id="country" name="country">
                                                @foreach($countries as $country)
                                                    <option value="{{ $country->isoCountry }}" {{old('country') == $country->isoCountry ? 'selected': null }}> {{ $country->country }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        @error('country')
                                        <div class="text-danger">
                                            {{ $message }}
                                        </div>
                                        @enderror
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="area_code" class="form-label">Area Code</label>
                                            <input type="area_code" id="area_code" class="form-control @error('area_code') is-invalid @enderror" value="{{ old('area_code') }}" name="area_code">
                                            @error('area_code')
                                            <div class="invalid-feedback">
                                                {{ $message }}
                                            </div>
                                            @enderror
                                            <p>
                                                <small class="text-primary">
                                                    The area code of the phone numbers to read. Applies to only phone numbers in the US and Canada.
                                                </small>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="mb-1">
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" id="smsEnabled" name="smsEnabled" value="true" checked/>
                                                <label class="form-check-label" for="smsEnabled">SMS Enabled</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" id="voiceEnabled" name="voiceEnabled" value="true"/>
                                                <label class="form-check-label" for="voiceEnabled">MMS Enabled</label>
                                            </div>

                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="checkbox" id="mmsEnabled" name="mmsEnabled" value="true"/>
                                                <label class="form-check-label" for="mmsEnabled">MMS Enabled</label>
                                            </div>

                                        </div>
                                    </div>

                                    <div class="col-12 mt-2">
                                        <button type="submit" class="btn btn-primary mr-1 mb-1">
                                            <i data-feather="save"></i> {{__('locale.buttons.search')}}
                                        </button>
                                    </div>


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
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection


@section('page-script')

    <script>
        let firstInvalid = $('form').find('.is-invalid').eq(0);

        if (firstInvalid.length) {
            $('body, html').stop(true, true).animate({
                'scrollTop': firstInvalid.offset().top - 200 + 'px'
            }, 200);
        }

        // Basic Select2 select
        $(".select2").each(function () {
            let $this = $(this);
            $this.wrap('<div class="position-relative"></div>');
            $this.select2({
                // the following code is used to disable x-scrollbar when click in select input and
                // take 100% width in responsive also
                dropdownAutoWidth: true,
                width: '100%',
                dropdownParent: $this.parent()
            });
        });
    </script>
@endsection

