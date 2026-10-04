@extends('layouts/authCover')

@section('title', __('Verify your email'))

@section('auth-form')
    {{--
        V1 signup, after provisioning: the purchase was never interrupted by
        this step — it only gates the product. The verification email is sent
        when the account is created, so this screen can truthfully say it was
        sent, and offers the two actions that matter: send it again, or leave
        and use a different account.
    --}}
    @if (session('status') === 'resent')
        <x-alert variant="success" role="status">
            {{ __('locale.auth.fresh_verification_link') }}
        </x-alert>
    @endif

    @include('auth.partials._flash-summary')

    <h1 class="card-title fw-bold mb-1 h2">{{ __('Verify your email') }}</h1>
    <p class="card-text mb-2">
        @if (auth()->check())
            {{ __('We sent a verification link to :email.', ['email' => auth()->user()->email]) }}
        @endif
        {{ __('locale.auth.resend_verification_link') }}
    </p>
    <form id="resend-form" action="{{ route('verification.send') }}" method="POST" class="mt-2">
        {{ csrf_field() }}
        <p class="card-text mb-1">{{ __('locale.auth.did_not_receive_email') }}</p>
        <button type="submit" class="btn btn-primary w-100">{{ __('Resend email') }}</button>
    </form>

    <form id="signout-form" action="{{ route('logout') }}" method="POST" class="mt-1">
        {{ csrf_field() }}
        <button type="submit" class="btn btn-link w-100">{{ __('Sign out or use a different account') }}</button>
    </form>
@endsection
