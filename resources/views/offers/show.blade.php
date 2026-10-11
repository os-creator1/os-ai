@extends('layouts/authCover')

@section('title', __('Your private offer'))

@section('auth-form')
    {{--
        A PRIVATE OFFER. Opening this page changes nothing: it shows the exact
        terms and, only after an explicit tick-box acceptance by a signed-in
        customer, sends them on to Stripe Checkout. Nothing is paid, started or
        unlocked until Stripe confirms it.
    --}}
    <h1 class="card-title fw-bold mb-1 h2">{{ __('Your private offer') }}</h1>

    @include('auth.partials._flash-summary')

    @if ($errors->any())
        <x-alert variant="danger" class="mb-1 alert-validation-msg" role="alert" data-role="offer-errors">
            <div class="alert-body">{{ $errors->first() }}</div>
        </x-alert>
    @endif

    @php($state = $view['state'])

    @if ($state === 'unavailable')
        <p data-role="offer-unavailable">{{ __('This offer is no longer available. Please contact us if you think this is a mistake.') }}</p>

    @elseif ($state === 'not_for_you')
        <p data-role="offer-not-for-you">{{ $view['message'] ?? __('This offer was not made for this account.') }}</p>
        <p class="text-muted small">{{ __('Sign in with the account the offer was sent to to continue.') }}</p>
        <a class="btn btn-outline-primary w-100" href="{{ route('logout') }}">{{ __('Sign out') }}</a>

    @elseif ($state === 'needs_verification')
        <p data-role="offer-needs-verification">{{ __('This offer was made for your email address. Please verify it first — we sent you a verification email when you signed up.') }}</p>
        @if (\Illuminate\Support\Facades\Route::has('verification.send'))
            <form method="POST" action="{{ route('verification.send') }}" class="mb-1">
                @csrf
                <button type="submit" class="btn btn-primary w-100">{{ __('Send the verification email again') }}</button>
            </form>
            <p class="text-muted small">{{ __('After verifying, open your offer link again.') }}</p>
        @else
            <p class="text-muted small" data-role="offer-verification-contact">{{ __('Open the verification email we sent you, then open your offer link again. If you cannot find it, please contact us.') }}</p>
        @endif

    @elseif ($state === 'needs_workspace')
        <p data-role="offer-needs-workspace">{{ __('You own more than one workspace. Choose the one this offer is for:') }}</p>
        <ul class="list-unstyled">
            @foreach ($view['workspaces'] as $choice)
                <li class="mb-50">
                    <a class="btn btn-outline-primary w-100" data-role="offer-choose-workspace"
                       href="{{ route('offers.show', ['token' => $token, 'workspace' => $choice->uid]) }}">{{ $choice->name }}</a>
                </li>
            @endforeach
        </ul>

    @elseif ($state === 'subscribed')
        <p data-role="offer-subscribed">{{ __('This account already has a subscription, so this offer cannot be applied to it. Please contact us if you were expecting special pricing.') }}</p>

    @elseif ($state === 'redeemed')
        @include('offers._terms', ['terms' => $view['terms'], 'plan' => $plan])
        <p data-role="offer-redeemed" class="text-success">{{ __('You have already accepted this offer — these are the terms your subscription is on.') }}</p>

    @else
        {{-- needs_login / needs_account / ready / in_progress: the terms are shown. --}}
        @if ($view['terms'] !== null)
            @include('offers._terms', ['terms' => $view['terms'], 'plan' => $plan])
        @endif

        @if ($state === 'needs_login')
            <p class="text-muted" data-role="offer-needs-login">
                {{ __('Sign in or create an account to review and accept this offer.') }}
                @if ($offer->customer_email) {{ __('Use the email address this offer was sent to.') }} @endif
            </p>
            <a class="btn btn-primary w-100 mb-50" href="{{ route('login') }}" data-role="offer-sign-in">{{ __('Sign in') }}</a>
            @if ($canSignUp)
                <a class="btn btn-outline-primary w-100" href="{{ route('offers.signup', ['token' => $token]) }}" data-role="offer-sign-up">{{ __('Create an account') }}</a>
            @endif

        @elseif ($state === 'needs_account')
            <p class="text-muted" data-role="offer-needs-account">{{ __('Finish setting up your account and business first, then come back to this link.') }}</p>
            <a class="btn btn-primary w-100" href="{{ route('signup.plan') }}">{{ __('Finish setting up') }}</a>

        @else
            @if ($state === 'in_progress')
                <p class="text-muted" data-role="offer-in-progress">{{ __('You started this offer but have not finished paying. You can continue below.') }}</p>
            @endif

            @if ($view['terms'] !== null)
                <form method="POST" action="{{ route('offers.accept', ['token' => $token]) }}" data-role="offer-accept-form">
                    @csrf
                    <input type="hidden" name="terms_hash" value="{{ $view['terms']->hash() }}">
                    @if ($offer->target_workspace_id === null && $view['workspace'] !== null)
                        <input type="hidden" name="workspace_uid" value="{{ $view['workspace']->uid }}">
                        <p class="text-muted small" data-role="offer-workspace">{{ __('For workspace: :name', ['name' => $view['workspace']->name]) }}</p>
                    @endif

                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="accept_terms" id="accept_terms" value="1" required>
                        <label class="form-check-label" for="accept_terms">
                            {{ $view['terms']->kind() === 'billing_change'
                                ? __('I accept these terms for my existing subscription.')
                                : __('I accept these terms, including the recurring charge shown above.') }}
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100" data-role="offer-accept">
                        {{ $view['terms']->kind() === 'billing_change' ? __('Accept new pricing') : __('Accept and continue to secure payment') }}
                    </button>
                </form>
                @if ($view['terms']->kind() !== 'billing_change')
                    <p class="text-muted small text-center mt-1">{{ __('You will enter your card details on Stripe. We never see or store them.') }}</p>
                @endif
            @endif
        @endif
    @endif
@endsection
