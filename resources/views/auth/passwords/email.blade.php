@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php $authBrand = $authBranding->for(request()); @endphp

@extends('layouts/fullLayoutMaster')

@section('title', __('locale.auth.forgot_password'))

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">
    @if(config('no-captcha.login'))
        {!! RecaptchaV3::initJs() !!}
    @endif
@endsection

@section('content')

    {{-- Customer Experience Slice 2: neutral/Agency identity through the branding seam; no login-v2*/forgot-password-v2* fallback. --}}
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

            <!-- Forgot password-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">
                    <h1 class="card-title fw-bold mb-1 h2">{{ __('locale.auth.recover_your_password') }}</h1>
                    <p class="card-text mb-2">{{ __('locale.auth.recover_password_instructions') }}</p>
                    @php
                        $authFailed = in_array(session('status'), ['error', 'warning'], true) && is_string(session('message')) && trim(session('message')) !== '';
                        $emailDescribedBy = implode(' ', array_filter([$errors->has('email') ? 'email-error' : null, $authFailed ? 'auth-flash' : null]));
                    @endphp

                    @include('auth.partials._flash-summary')

                    <form class="auth-forgot-password-form mt-2" method="POST" action="{{ route('password.email') }}" novalidate>
                        @csrf
                        <div class="mb-1">
                            <label class="form-label" for="email">{{ __('locale.labels.email') }}</label>
                            <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus
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

                        @if(config('no-captcha.login'))
                            <div class="mb-1">
                                {!! RecaptchaV3::field('email') !!}
                            </div>
                        @endif
                        <button type="submit" class="btn btn-primary w-100">{{ __('locale.auth.recover_password') }}</button>
                    </form>

                    <p class="text-center mt-2">
                        <a href="{{ route('login') }}">
                            <x-ds-icon name="chevron-left" aria-hidden="true" /> {{ __('locale.auth.back_to_login') }}
                        </a>
                    </p>
                </div>
            </div>
            <!-- /Forgot password-->

        </div>
    </div>
@endsection
