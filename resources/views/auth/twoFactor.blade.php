@php
    use App\Helpers\Helper;$configData = Helper::applClasses();
@endphp
@inject('authBranding', 'App\Library\Branding\AuthBrandPresenter')
@php $authBrand = $authBranding->for(request()); @endphp
@extends('layouts/fullLayoutMaster')

@section('title', __('locale.auth.two_factor_authentication'))

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/authentication.css')) }}">
@endsection

@section('content')
    {{-- Customer Experience Slice 2: neutral/Agency identity through the branding seam; no two-steps-verification-illustration* fallback. --}}
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

            <!-- Two-factor verification-->
            <div class="d-flex col-lg-4 align-items-center auth-bg px-2 p-lg-5">
                <div class="col-12 col-sm-8 col-md-6 col-lg-12 px-xl-2 mx-auto">
                    <h1 class="card-title fw-bolder mb-1 h2">{{ __('locale.auth.two_factor_authentication') }}</h1>
                    <p class="card-text mb-75">{{ __('locale.auth.two_factor_code_sent_to_email') }}</p>
                    @if(Auth::check())
                        <p class="card-text fw-bolder mb-2">{{ preg_replace_callback('/(\w)(.*?)(\w)(@.*?)$/s', function ($matches){
    return $matches[1].preg_replace("/\w/", "*", $matches[2]).$matches[3].$matches[4];
}, Auth::user()->email) }}</p>
                    @endif
                    @php
                        $authFailed = in_array(session('status'), ['error', 'warning'], true) && is_string(session('message')) && trim(session('message')) !== '';
                        $codeDescribedBy = implode(' ', array_filter([$errors->has('two_factor_code') ? 'two_factor_code-error' : null, $authFailed ? 'auth-flash' : null]));
                    @endphp

                    @include('auth.partials._flash-summary')

                    <form method="POST" action="{{ route('verify.store') }}" class="mt-2" novalidate>
                        @csrf
                        <label class="form-label" for="two_factor_code">{{ __('locale.auth.enter_security_code') }}</label>
                        <div class="auth-input-wrapper d-flex align-items-center justify-content-between">
                            <input id="two_factor_code" type="number" inputmode="numeric" autocomplete="one-time-code" class="form-control  text-center numeral-mask mb-1 @error('two_factor_code') is-invalid @enderror" name="two_factor_code" value="{{ old('two_factor_code') }}" required autofocus
                                   @if($codeDescribedBy !== '') aria-describedby="{{ $codeDescribedBy }}" @endif
                                   @if($errors->has('two_factor_code') || $authFailed) aria-invalid="true" @endif>

                            @error('two_factor_code')
                            <span class="invalid-feedback" role="alert" id="two_factor_code-error">
                        <strong>{{ $message }}</strong>
                      </span>
                            @enderror
                        </div>
                        <button class="btn btn-primary w-100" type="submit">{{ __('locale.auth.verify') }}</button>
                    </form>
                    <p class="text-center mt-2">
                        <span>{{ __('locale.auth.did_not_get_code') }}</span>
                        <a href="{{ route('verify.resend') }}">{{ __('locale.auth.resend_code') }}</a>
                        <span>{{ __('locale.auth.or_lowercase') }}</span>
                        <a href="{{ route('verify.backup')}}">{{ __('locale.auth.verify_with_backup_code') }}</a>

                    </p>
                </div>
            </div>
            <!-- /Two-factor verification-->
        </div>
    </div>
@endsection
