@extends('layouts/authCover')

@section('title', __('locale.auth.password_reset'))

@section('auth-style')
@if(config('no-captcha.login'))
    {!! RecaptchaV3::initJs() !!}
@endif
@endsection

{{-- Shared viewport-fitted authCover shell: same branding/image bounds as Login. --}}
@section('auth-form')
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
@endsection
