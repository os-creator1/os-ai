@extends('layouts/authCover')

@section('title', __('Create your account'))

@section('auth-form')
    {{-- STEP 2 — ACCOUNT. Identity only: no Business or product questions. --}}
    @include('auth.signup._progress', ['step' => 2])

    <h1 class="card-title fw-bold mb-1 h2">{{ __('Create your account') }}</h1>
    @include('auth.signup._plan-line')

    @include('auth.partials._flash-summary')

    <form class="auth-register-form" method="POST" action="{{ route('register.account.store') }}" novalidate>
        @csrf
        <div class="mb-1">
            <label class="form-label" for="first_name">{{ __('First name') }}</label>
            <input id="first_name" name="first_name" type="text" class="form-control @error('first_name') is-invalid @enderror"
                   value="{{ old('first_name', $account['first_name'] ?? '') }}" required autocomplete="given-name" autofocus>
            @include('auth.signup._field-error', ['field' => 'first_name'])
        </div>

        <div class="mb-1">
            <label class="form-label" for="last_name">{{ __('Last name') }}</label>
            <input id="last_name" name="last_name" type="text" class="form-control @error('last_name') is-invalid @enderror"
                   value="{{ old('last_name', $account['last_name'] ?? '') }}" autocomplete="family-name">
            @include('auth.signup._field-error', ['field' => 'last_name'])
        </div>

        <div class="mb-1">
            <label class="form-label" for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email', $account['email'] ?? '') }}" required autocomplete="email">
            @include('auth.signup._field-error', ['field' => 'email'])
        </div>

        <div class="mb-1">
            <label class="form-label" for="password">{{ __('Password') }}</label>
            <div class="input-group input-group-merge form-password-toggle">
                <input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror"
                       required autocomplete="new-password">
                <button type="button" class="input-group-text cursor-pointer" data-role="password-toggle"
                        aria-controls="password" aria-pressed="false"
                        aria-label="{{ __('locale.auth.show_password') }}"
                        data-label-show="{{ __('locale.auth.show_password') }}"
                        data-label-hide="{{ __('locale.auth.hide_password') }}"><x-ds-icon name="eye" aria-hidden="true" /></button>
            </div>
            @include('auth.signup._field-error', ['field' => 'password'])
        </div>

        <div class="mb-2">
            <label class="form-label" for="password_confirmation">{{ __('Confirm password') }}</label>
            <div class="input-group input-group-merge form-password-toggle">
                <input id="password_confirmation" name="password_confirmation" type="password" class="form-control"
                       required autocomplete="new-password">
                <button type="button" class="input-group-text cursor-pointer" data-role="password-toggle"
                        aria-controls="password_confirmation" aria-pressed="false"
                        aria-label="{{ __('locale.auth.show_password') }}"
                        data-label-show="{{ __('locale.auth.show_password') }}"
                        data-label-hide="{{ __('locale.auth.hide_password') }}"><x-ds-icon name="eye" aria-hidden="true" /></button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100">{{ __('Continue') }}</button>
    </form>

    <p class="text-center mt-2">
        <span>{{ __('Already have an account?') }}</span>
        <a href="{{ route('login') }}">{{ __('Sign in') }}</a>
    </p>
@endsection
