@extends('layouts/authCover')

@section('title', __('locale.auth.forgot_password'))

@section('auth-style')
@if(config('no-captcha.login'))
    {!! RecaptchaV3::initJs() !!}
@endif
@endsection

{{-- Shared viewport-fitted authCover shell: same branding/image bounds as Login. --}}
@section('auth-form')
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
@endsection
