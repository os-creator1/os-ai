@extends('layouts/authCover')

@section('title', __('Setting up your Business OS'))

@section('auth-style')
    @unless ($slow)
        {{-- Re-check provider truth shortly; the webhook may finish it first. --}}
        <meta http-equiv="refresh" content="3;url={{ route('signup.success') }}">
    @endunless
@endsection

@section('auth-form')
    {{--
        Payment has been taken (or is being confirmed). The browser is never
        payment truth: this page simply asks the server again, which re-reads
        the provider and runs the same idempotent activation the webhook runs.
    --}}
    <div data-role="signup-provisioning" role="status">
        <h1 class="card-title fw-bold mb-1 h2">{{ __('Setting up your Business OS…') }}</h1>
        @if ($slow)
            <p class="card-text mb-2">{{ __('This is taking longer than usual. Your payment is safe and your account will finish setting up on its own.') }}</p>
            <a class="btn btn-primary w-100" href="{{ route('signup.success') }}">{{ __('Check again') }}</a>
        @else
            <p class="card-text mb-2">{{ __('This only takes a moment. Please keep this page open.') }}</p>
            <div class="progress" style="height: 0.25rem;"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%"></div></div>
        @endif
    </div>
@endsection
