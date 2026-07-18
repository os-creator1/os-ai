@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@extends('layouts/fullLayoutMaster')

@section('title', __('locale.labels.subscribe'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/pickers/flatpickr/flatpickr.min.css')) }}">
@endsection

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/forms/pickers/form-flat-pickr.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">

    @if(config('no-captcha.registration'))
        {!! RecaptchaV3::initJs() !!}
    @endif

@endsection

@section('content')
    <div class="auth-wrapper auth-cover">
        <div class="auth-inner row m-0">
            <!-- Brand logo-->
            <a class="brand-logo" href="{{route('login')}}">
                <img src="{{asset(config('app.logo'))}}" alt="{{config('app.name')}}"/>
            </a>
            <!-- /Brand logo-->

            <!-- Left Text-->
            <div class="d-none d-lg-flex col-lg-8 align-items-center p-5">
                <div class="w-100 d-lg-flex align-items-center justify-content-center px-5">
                    @if($configData['theme'] === 'dark')
                        <img src="{{asset('images/pages/reset-password-v2-dark.svg')}}" class="img-fluid"
                             alt="{{ config('app.name') }}"/>
                    @else
                        <img src="{{asset('images/pages/reset-password-v2.svg')}}" class="img-fluid"
                             alt="{{ config('app.name') }}"/>
                    @endif
                </div>
            </div>
            <!-- /Left Text-->

            <!-- Reset password-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">
                    <h2 class="card-title fw-bold mb-1">{{ __('locale.labels.subscribe') }}</h2>
                    <p class="card-text mb-2">{{ __('locale.labels.welcome_to') }} {{ $contact->name }}</p>
                    <form method="POST" class="auth-reset-password-form mt-2"
                          action="{{ route('contacts.subscribe_url', $contact->uid) }}">
                        @csrf


                        @if(config('no-captcha.registration'))
                            @error('g-recaptcha-response')
                            <span class="text-danger">{{ __('locale.labels.g-recaptcha-response') }}</span>
                            @enderror
                        @endif


                        <div class="col-12">

                            @foreach($contact->getFields as $field)

                                @if($field->visible)
                                    @if ($field->tag != 'PHONE')
                                        @if($field->type == 'number')

                                            <div class="mb-1">
                                                <label for="{{ $field->tag }}"
                                                       class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                </label>

                                                <input type="number" id="{{ $field->tag }}"
                                                       class="form-control @error($field->tag) is-invalid @enderror"
                                                       value="{{ old($field->tag) ?? $field->default_value }}"
                                                       name="{{ $field->tag }}"
                                                        {{ $field->required ? 'required' : '' }}>
                                                @error($field->tag)
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>

                                        @elseif($field->type == 'textarea')
                                            <div class="mb-1">
                                                <label for="{{ $field->tag }}"
                                                       class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                </label>

                                                <textarea id="{{ $field->tag }}"
                                                          class="form-control @error($field->tag) is-invalid @enderror"
                                                          name="{{ $field->tag }}"
                                                                    {{ $field->required ? 'required' : '' }}>{{ old($field->tag) ?? $field->default_value }}</textarea>
                                                @error($field->tag)
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        @elseif($field->type == 'date')
                                            <div class="mb-1">
                                                <label for="{{ $field->tag }}"
                                                       class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                </label>

                                                <input type="text" id="{{ $field->tag }}"
                                                       class="form-control date @error($field->tag) is-invalid @enderror"
                                                       value="{{ old($field->tag) ?? $field->default_value }}"
                                                       name="{{ $field->tag }}"
                                                        {{ $field->required ? 'required' : '' }}>
                                                @error($field->tag)
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        @elseif($field->type == 'datetime')
                                            <div class="mb-1">
                                                <label for="{{ $field->tag }}"
                                                       class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                </label>

                                                <input type="text" id="{{ $field->tag }}"
                                                       class="form-control datetime @error($field->tag) is-invalid @enderror"
                                                       value="{{ old($field->tag) ?? $field->default_value }}"
                                                       name="{{ $field->tag }}"
                                                        {{ $field->required ? 'required' : '' }}>
                                                @error($field->tag)
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        @else
                                            <div class="mb-1">
                                                <label for="{{ $field->tag }}"
                                                       class="form-label {{ $field->required ? 'required' : '' }}">{{ $field->label }}
                                                </label>

                                                <input type="text" id="{{ $field->tag }}"
                                                       class="form-control @error($field->tag) is-invalid @enderror"
                                                       value="{{ old($field->tag) ?? $field->default_value }}"
                                                       name="{{ $field->tag }}"
                                                        {{ $field->required ? 'required' : '' }}>
                                                @error($field->tag)
                                                <div class="invalid-feedback">
                                                    {{ $message }}
                                                </div>
                                                @enderror
                                            </div>
                                        @endif
                                    @else
                                        <div class="mb-1">
                                            <label for="phone"
                                                   class="form-label required">{{ $field->label }}</label>
                                            <input type="text" id="phone"
                                                   class="form-control @error('PHONE') is-invalid @enderror"
                                                   value="{{ old($field->tag) ?? $field->default_value  }}"

                                                   name="{{ $field->tag }}" required>
                                            @error('PHONE')
                                            <div class="invalid-feedback">
                                                {{ $message }}
                                            </div>
                                            @enderror

                                            <p>
                                                <small class="text-primary @error('PHONE') hidden @enderror"> {!! __('locale.contacts.include_country_code') !!} </small>
                                            </p>

                                        </div>
                                    @endif
                                @endif
                            @endforeach

                        </div>


                        @if(config('no-captcha.registration'))
                            <fieldset class="form-label-group position-relative">
                                {!! RecaptchaV3::field('subscribe') !!}
                            </fieldset>
                        @endif

                        <button class="btn btn-primary w-100" type="submit"
                                tabindex="3">{{ __('locale.labels.subscribe') }}</button>
                    </form>
                </div>
            </div>
            <!-- /Reset password-->
        </div>
    </div>
@endsection


@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/pickers/flatpickr/flatpickr.min.js')) }}"></script>
@endsection


@section('page-script')
    <script>
        $(document).ready(function () {

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
        });
    </script>
@endsection

@push('scripts')
    <script>
        let firstInvalid = $('form').find('.is-invalid').eq(0);

        if (firstInvalid.length) {
            $('body, html').stop(true, true).animate({
                'scrollTop': firstInvalid.offset().top - 200 + 'px'
            }, 200);
        }


        $(".datetime").flatpickr({
            enableTime: true,
            dateFormat: "Y-m-d H:i"
        });

        $(".date").flatpickr({
            enableTime: false,
            dateFormat: "Y-m-d"
        });


    </script>
@endpush
