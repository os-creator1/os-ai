@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php $authBrand = $authBranding->for(request()); @endphp

@extends('layouts/fullLayoutMaster')

@section('title', __('locale.auth.password_reset'))

@section('page-style')
    {{-- Page Css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">
    @if(config('no-captcha.login'))
        {!! RecaptchaV3::initJs() !!}
    @endif
@endsection

@section('content')

    {{-- Customer Experience Slice 2: neutral/Agency identity through the branding seam; no reset-password-v2* fallback. --}}
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

            <!-- Reset password-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">
                    <h1 class="card-title fw-bold mb-1 h2">{{ __('locale.auth.password_reset') }}</h1>
                    <p class="card-text mb-2">{{ __('locale.auth.enter_new_password') }}</p>

                    @php
                        $authFailed = in_array(session('status'), ['error', 'warning'], true) && is_string(session('message')) && trim(session('message')) !== '';
                        $emailDescribedBy = implode(' ', array_filter([$errors->has('email') ? 'email-error' : null, $authFailed ? 'auth-flash' : null]));
                    @endphp

                    @include('auth.partials._flash-summary')

                    @if ($errors->has('token'))
                        <x-alert variant="danger" class="mb-1" role="alert">{{ __('locale.auth.reset_link_invalid') }}</x-alert>
                    @endif

                    <form class="auth-reset-password-form mt-2" method="POST" action="{{ route('password.update') }}" novalidate>
                        @csrf

                        <div class="mb-1">
                            <label class="form-label" for="email">{{ __('locale.labels.email') }}</label>
                            <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ $email ?? old('email') }}" required autocomplete="email" autofocus
                                   @if($emailDescribedBy !== '') aria-describedby="{{ $emailDescribedBy }}" @endif
                                   @if($errors->has('email') || $authFailed) aria-invalid="true" @endif>
                            @error('email')
                            <x-alert variant="danger" class="mt-1" id="email-error" role="alert">
                                <strong>{{ $message }}</strong>
                            </x-alert>
                            @enderror
                        </div>

                        <div class="mb-1">
                            <div class="d-flex justify-content-between">
                                <label class="form-label" for="password">{{ __('locale.labels.new_password') }}</label>
                            </div>
                            <div class="input-group input-group-merge form-password-toggle">
                                <input id="password" type="password" class="form-control form-control-merge @error('password') is-invalid @enderror" name="password" required autocomplete="new-password"
                                       @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                                <button type="button" class="input-group-text cursor-pointer" data-role="password-toggle"
                                        aria-controls="password" aria-pressed="false"
                                        aria-label="{{ __('locale.auth.show_password') }}"
                                        data-label-show="{{ __('locale.auth.show_password') }}"
                                        data-label-hide="{{ __('locale.auth.hide_password') }}"><x-ds-icon name="eye" aria-hidden="true" /></button>
                            </div>
                            @error('password')
                            <x-alert variant="danger" class="mt-1" id="password-error" role="alert">
                                <strong>{{ $message }}</strong>
                            </x-alert>
                            @enderror
                        </div>

                        <div class="mb-1">
                            <div class="d-flex justify-content-between">
                                <label class="form-label" for="password-confirm">{{ __('locale.labels.password_confirmation') }}</label>
                            </div>
                            <div class="input-group input-group-merge form-password-toggle">
                                <input id="password-confirm" type="password" class="form-control form-control-merge" name="password_confirmation" required autocomplete="new-password">
                                <button type="button" class="input-group-text cursor-pointer" data-role="password-toggle"
                                        aria-controls="password-confirm" aria-pressed="false"
                                        aria-label="{{ __('locale.auth.show_password') }}"
                                        data-label-show="{{ __('locale.auth.show_password') }}"
                                        data-label-hide="{{ __('locale.auth.hide_password') }}"><x-ds-icon name="eye" aria-hidden="true" /></button>
                            </div>
                        </div>


                        @if(config('no-captcha.login'))
                            <div class="mb-1">
                                {!! RecaptchaV3::field('reset') !!}
                            </div>
                        @endif
                        <input type="hidden" name="token" value="{{ $token }}">
                        <button type="submit" class="btn btn-primary w-100">{{ __('locale.buttons.reset') }}</button>
                    </form>
                    <p class="text-center mt-2">
                        <a href="{{ route('login') }}">
                            <x-ds-icon name="chevron-left" aria-hidden="true" /> {{ __('locale.auth.back_to_login') }}
                        </a>
                    </p>
                </div>
            </div>
            <!-- /Reset password-->
        </div>
    </div>
@endsection
