@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php $authBrand = $authBranding->for(request()); @endphp

@extends('layouts/fullLayoutMaster')

@section('title', __('locale.auth.login'))

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">
    @if(config('no-captcha.login'))
        {!! RecaptchaV3::initJs() !!}
    @endif

    <style>
        .auth-bg {
            position: relative;
            min-height: 100vh;
        }
    </style>

@endsection

@section('content')

    {{--
        Customer Experience Slice 2 (contract §9.1, brief §3-§5): the
        identity beside the form is the branding-illustration seam — an
        authorized Agency brand, the owner's configured illustration, or
        the neutral AI Business OS typographic panel. No inherited
        login-v2*.svg fallback exists any more; the form never depends on
        a decorative image. One <h1>, visible labels, an accessible
        password toggle, error text tied to its field.
    --}}
    <div class="auth-wrapper auth-cover">
        <div class="auth-inner row m-0">
            <!-- Brand logo-->
            <a class="brand-logo" href="{{route('login')}}" aria-label="{{ $authBrand->displayName }}">
                <x-branding-illustration surface="auth-mark" />
            </a>
            <!-- /Brand logo-->

            <!-- Brand panel-->
            <div class="d-none d-lg-flex col-lg-8 align-items-center p-5">
                <div class="w-100 d-lg-flex align-items-center justify-content-center px-5">
                    <x-branding-illustration surface="auth" :dark="$configData['theme'] === 'dark'" />
                </div>
            </div>
            <!-- /Brand panel-->

            <!-- Login-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">
                    <h1 class="card-title fw-bold mb-1 h2">{{ __('locale.labels.welcome_to') }} {{ $authBrand->displayName }}</h1>
                    <p class="card-text mb-2">{{__('locale.auth.welcome_message')}}</p>


                    @if(config('app.stage') == 'demo')
                        <div class="d-flex justify-content-between" style="cursor: pointer;">
                            <span class="text-primary font-medium-1 admin-login text-uppercase">Admin View</span>
                            <span class="text-success font-medium-1 pull-right customer-login text-uppercase">Campaign View</span>
                            <span class="text-danger font-medium-1 dlt-login text-uppercase">DLT View</span>
                        </div>
                    @endif

                    @php
                        $authFailed = in_array(session('status'), ['error', 'warning'], true) && is_string(session('message')) && trim(session('message')) !== '';
                        $emailDescribedBy = implode(' ', array_filter([$errors->has('email') ? 'email-error' : null, $authFailed ? 'auth-flash' : null]));
                    @endphp

                    @include('auth.partials._flash-summary')

                    <form class="auth-login-form mt-2" method="POST" action="{{ route('login') }}" novalidate>
                        @csrf
                        <div class="mb-1">
                            <label class="form-label" for="email">{{ __('locale.labels.email') }}</label>
                            <input id="email" type="email" class="form-control @error('email') is-invalid @enderror"
                                   name="email" value="{{ old('email') }}"
                                   required autocomplete="email" autofocus
                                   @if($emailDescribedBy !== '') aria-describedby="{{ $emailDescribedBy }}" @endif
                                   @if($errors->has('email') || $authFailed) aria-invalid="true" @endif>

                            @error('email')
                            <x-alert variant="danger" class="mt-1 alert-validation-msg" id="email-error" role="alert">
                                <div class="alert-body d-flex align-items-center">
                                    <x-ds-icon name="info" class="me-50" aria-hidden="true" />
                                    <span>{{ $message }}</span>
                                </div>
                            </x-alert>
                            @enderror

                            @error('g-recaptcha-response')
                            <x-alert variant="danger" class="mt-1 alert-validation-msg" role="alert">
                                <div class="alert-body d-flex align-items-center">
                                    <x-ds-icon name="info" class="me-50" aria-hidden="true" />
                                    <span>{{ __('locale.labels.g-recaptcha-response') }}</span>
                                </div>
                            </x-alert>
                            @enderror
                        </div>


                        <div class="mb-1">
                            <div class="d-flex justify-content-between">
                                <label class="form-label" for="password">{{__('locale.labels.password')}}</label>

                                @if (Route::has('password.request'))
                                    <a href="{{ route('password.request') }}">
                                        <small>{{ __('locale.auth.forgot_password') }}?</small>
                                    </a>
                                @endif
                            </div>

                            <div class="input-group input-group-merge form-password-toggle">
                                <input id="password" type="password" class="form-control" name="password"
                                       required autocomplete="current-password"
                                       @if(config('app.stage') == 'demo') value="12345678" @endif>
                                <button type="button" class="input-group-text cursor-pointer" data-role="password-toggle"
                                        aria-controls="password" aria-pressed="false"
                                        aria-label="{{ __('locale.auth.show_password') }}"
                                        data-label-show="{{ __('locale.auth.show_password') }}"
                                        data-label-hide="{{ __('locale.auth.hide_password') }}"><x-ds-icon name="eye" aria-hidden="true" /></button>
                            </div>
                        </div>

                        @if(config('no-captcha.login'))
                            <div class="mb-1">
                                {!! RecaptchaV3::field('login') !!}
                            </div>
                        @endif


                        <div class="mb-1">
                            <div class="form-check">
                                <input class="form-check-input" {{ old('remember') ? 'checked' : '' }} name="remember"
                                       id="remember-me" type="checkbox" />
                                <label class="form-check-label"
                                       for="remember-me"> {{__('locale.auth.remember_me')}}</label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">{{__('locale.auth.login')}}</button>
                    </form>

                    @if(config('account.can_register'))
                        <p class="text-center mt-2">
                            <span>{{__('locale.auth.new_on_our_platform')}}?</span>
                            <a href="{{route('register')}}"><span>&nbsp;{{__('locale.auth.register')}}</span></a>
                        </p>
                    @endif

                    @if(config('services.facebook.active') || config('services.twitter.active') || config('services.google.active') || config('services.github.active'))
                        <div class="divider my-2">
                            <div class="divider-text">{{__('locale.auth.or')}}</div>
                        </div>

                        <div class="auth-footer-btn d-flex justify-content-center">

                            @if(config('services.facebook.active'))
                                <a class="btn btn-facebook" href="{{route('social.login', 'facebook')}}"
                                   aria-label="{{ __('locale.auth.continue_with', ['provider' => 'Facebook']) }}"
                                   data-bs-toggle="tooltip" data-bs-placement="top" title="Facebook">
                                    <x-ds-icon name="facebook" aria-hidden="true" />
                                </a>
                            @endif

                            @if(config('services.twitter.active'))
                                <a class="btn btn-twitter" href="{{route('social.login', 'twitter')}}"
                                   aria-label="{{ __('locale.auth.continue_with', ['provider' => 'Twitter']) }}"
                                   data-bs-toggle="tooltip" data-bs-placement="top" title="Twitter">
                                    <x-ds-icon name="twitter" aria-hidden="true" />
                                </a>
                            @endif

                            @if(config('services.google.active'))
                                <a class="btn btn-google" href="{{route('social.login', 'google')}}"
                                   aria-label="{{ __('locale.auth.continue_with', ['provider' => 'Google']) }}"
                                   data-bs-toggle="tooltip" data-bs-placement="top" title="Google">
                                    <x-ds-icon name="mail" aria-hidden="true" />
                                </a>
                            @endif

                            @if(config('services.github.active'))
                                <a class="btn btn-github" href="{{route('social.login', 'github')}}"
                                   aria-label="{{ __('locale.auth.continue_with', ['provider' => 'GitHub']) }}"
                                   data-bs-toggle="tooltip" data-bs-placement="top" title="Github">
                                    <x-ds-icon name="github" aria-hidden="true" />
                                </a>
                            @endif

                        </div>
                    @endif


                    @php
                        $hasPrivacyPolicy = !empty(config('app.privacy_policy'));
                        $hasTermsOfUse = !empty(config('app.terms_of_use'));
                        $hasCustomScript = Helper::app_config('custom_script') ? Helper::app_config('custom_script') : null;
                    @endphp

                    @if($hasPrivacyPolicy || $hasTermsOfUse)
                        <p class="text-center text-uppercase position-absolute bottom-0 start-50 translate-middle-x mb-2">
                            @if($hasTermsOfUse)
                                <a class="ms-25 text-success" target="_blank"
                                   href="{{ route('terms-of-use') }}">{{ __('locale.labels.terms_of_use') }}</a>
                            @endif

                            @if($hasPrivacyPolicy)
                                <a class="ms-25 text-info" target="_blank"
                                   href="{{ route('privacy-policy') }}">{{ __('locale.labels.privacy_policy') }}</a>
                            @endif
                        </p>
                    @endif

                    @if($hasCustomScript !== null)
                        {!! $hasCustomScript !!}
                    @endif
                </div>
            </div>
            <!-- /Login-->
        </div>
    </div>
@endsection


@push('scripts')
    <script>
      $(".admin-login").on("click", function() {
        $("#email").val("admin@example.test");
      });

      $(".customer-login").on("click", function() {
        $("#email").val("customer@example.test");
      });

      $(".dlt-login").on("click", function() {
        $("#email").val("dlt@example.test");
      });
    </script>
@endpush
