@extends('offers._shell')

@php
    /*
     * A PRIVATE OFFER. Opening this page changes nothing: it shows the exact
     * terms and, only after an explicit tick-box acceptance by a signed-in
     * customer, sends them on to Stripe Checkout. Nothing is paid, started or
     * unlocked until Stripe confirms it.
     *
     * Every state of the link renders in the SAME shell (offers/_shell). Only
     * the right-hand content and its actions differ, and an unavailable state
     * never gets an invented action.
     */
    $state = $view['state'];
    $terms = $view['terms'];
    $viewer = auth()->user();
    $viewerEmail = $viewer !== null ? (string) $viewer->email : '';
    $impersonating = session()->has('admin_user_id') || session()->has('parent_user_id');
    $hasWorkspaceChoice = $state === 'needs_workspace' || ($state === 'not_for_you' && ! empty($view['workspaces']));
    // The identity check failed (this account is not the one the offer was made for).
    $wrongAccount = $state === 'not_for_you' && ! $hasWorkspaceChoice;
    $isChange = $terms !== null && $terms->kind() === 'billing_change';

    $permanent = $terms !== null && ! $terms->hasPromotionEnd() && ($terms->get('standard_source') === 'negotiated' || $terms->promotion() !== null);
    $planName = $terms !== null ? (string) $terms->get('plan_name') : '';

    $heading = match (true) {
        $state === 'needs_login' => __('Something special, just for you.'),
        $wrongAccount => __('This gift is for another account.'),
        $state === 'needs_verification' => __('Verify your email to open your offer.'),
        $hasWorkspaceChoice => __('Choose the workspace for this offer.'),
        $state === 'needs_account' => __('Finish setting up to claim your offer.'),
        $state === 'ready' => $isChange ? __('Your new pricing is ready.') : __('Your special offer is ready.'),
        $state === 'in_progress' => __('Your offer is waiting for you.'),
        $state === 'redeemed' => __('Your offer is confirmed.'),
        $state === 'subscribed' => __('This offer can’t be applied here.'),
        default => __('This offer is no longer available.'),
    };

    $termsLead = match (true) {
        $permanent => __('A price we don’t offer publicly — and it stays yours for as long as you subscribe.'),
        $terms !== null && $terms->hasPromotionEnd() => __('A private promotional price on the :plan plan. The exact terms, including what happens when it ends, are below.', ['plan' => $planName]),
        default => __('A private offer on the :plan plan. The exact terms are below.', ['plan' => $planName]),
    };
@endphp

@section('title', __('Your private offer'))
@section('heading', $heading)
@if ($wrongAccount)
    @section('gift-locked', 'yes')
@endif

@if ($state === 'needs_login' && $terms !== null)
    @section('lead', $termsLead)
@elseif ($wrongAccount)
    @section('lead', __('Your private offer was sent to a different email. Switch to that account to open it.'))
@elseif ($state === 'ready' && $terms !== null)
    @section('lead', $termsLead)
@endif

@section('offer-body')
    @if ($state === 'unavailable')
        <p class="pof-lead" data-role="offer-unavailable">{{ __('This offer is no longer available. Please contact us if you think this is a mistake.') }}</p>

    @elseif ($wrongAccount)
        {{-- Privacy: nothing about the offer and never the intended recipient's email. --}}
        <div class="pof-account" data-role="offer-not-for-you">
            <span class="pof-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::substr($viewerEmail, 0, 1) }}</span>
            <span class="pof-account-meta">
                <span class="pof-account-label">{{ __('Signed in as') }}</span>
                <span class="pof-account-email" data-role="offer-current-account">{{ $viewerEmail }}</span>
            </span>
        </div>

        <div class="pof-actions">
            @if ($impersonating)
                <p class="pof-alert pof-alert-error" role="note" data-role="offer-switch-blocked">{{ __('You are viewing as another user. Exit that session first, then open this offer link again.') }}</p>
            @else
                <form method="POST" action="{{ route('offers.switch-account', ['token' => $token]) }}" class="pof-form" data-role="offer-switch-account-form">
                    @csrf
                    <button type="submit" class="pof-btn pof-btn-primary" data-role="offer-switch-account">{{ __('Switch account') }}</button>
                </form>
            @endif
            <a class="pof-btn pof-btn-secondary" href="{{ \App\Helpers\Helper::home_route() }}" data-role="offer-go-dashboard">{{ __('Go to my dashboard') }}</a>
        </div>
        <p class="pof-small">{{ __("Can't find the right email? Ask the person who sent you the offer.") }}</p>

    @elseif ($state === 'needs_verification')
        <p class="pof-lead" data-role="offer-needs-verification">{{ __('This offer was made for your email address. Please verify it first — we sent you a verification email when you signed up.') }}</p>
        @if (\Illuminate\Support\Facades\Route::has('verification.send'))
            <form method="POST" action="{{ route('verification.send') }}" class="pof-form">
                @csrf
                <button type="submit" class="pof-btn pof-btn-primary">{{ __('Send the verification email again') }}</button>
            </form>
            <p class="pof-small">{{ __('After verifying, open your offer link again.') }}</p>
        @else
            <p class="pof-small" data-role="offer-verification-contact">{{ __('Open the verification email we sent you, then open your offer link again. If you cannot find it, please contact us.') }}</p>
        @endif

    @elseif ($hasWorkspaceChoice)
        @if ($state === 'not_for_you')
            <p class="pof-alert pof-alert-error" role="alert" data-role="offer-not-for-you">{{ __('That workspace is not available for this offer.') }}</p>
        @endif
        <p class="pof-lead" @if ($state === 'needs_workspace') data-role="offer-needs-workspace" @endif>{{ __('You own more than one workspace. Choose the one this offer is for:') }}</p>
        <ul class="pof-choices">
            @foreach ($view['workspaces'] as $choice)
                <li>
                    <a class="pof-btn pof-btn-secondary" data-role="offer-choose-workspace"
                       href="{{ route('offers.show', ['token' => $token, 'workspace' => $choice->uid]) }}">{{ $choice->name }}</a>
                </li>
            @endforeach
        </ul>

    @elseif ($state === 'subscribed')
        <p class="pof-lead" data-role="offer-subscribed">{{ __('This account already has a subscription, so this offer cannot be applied to it. Please contact us if you were expecting special pricing.') }}</p>
        <div class="pof-actions">
            <a class="pof-btn pof-btn-secondary" href="{{ \App\Helpers\Helper::home_route() }}" data-role="offer-go-dashboard">{{ __('Go to my dashboard') }}</a>
        </div>

    @elseif ($state === 'redeemed')
        <p class="pof-lead" data-role="offer-redeemed">{{ __('You have already accepted this offer — these are the terms your subscription is on.') }}</p>
        @include('offers._terms', ['terms' => $terms, 'plan' => $plan])
        <div class="pof-actions">
            <a class="pof-btn pof-btn-secondary" href="{{ \App\Helpers\Helper::home_route() }}" data-role="offer-go-dashboard">{{ __('Go to my dashboard') }}</a>
        </div>

    @else
        {{-- needs_login / needs_account / ready / in_progress: the terms are shown. --}}
        @if ($terms !== null)
            @include('offers._terms', ['terms' => $terms, 'plan' => $plan])
        @endif

        @if ($state === 'needs_login')
            <div class="pof-actions">
                <a class="pof-btn pof-btn-primary" href="{{ route('login') }}" data-role="offer-sign-in">
                    {{ __('Sign in to claim') }}
                    <svg class="pof-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                @if ($canSignUp)
                    <a class="pof-btn pof-btn-secondary" href="{{ route('offers.signup', ['token' => $token]) }}" data-role="offer-sign-up">{{ __('Create an account') }}</a>
                @endif
                <p class="pof-small" data-role="offer-needs-login">
                    {{ __('Sign in or create an account to review and accept this offer.') }}
                    @if ($offer->customer_email) {{ __('Use the email address this offer was sent to.') }} @endif
                </p>
            </div>
            @if ($terms !== null && ! $isChange && $terms->trialDays() !== null)
                <p class="pof-small">{{ __('A card is needed to start. Cancel before :date and you won’t be charged.', ['date' => $terms->firstPaymentAt()->utc()->toFormattedDateString()]) }}</p>
            @endif

        @elseif ($state === 'needs_account')
            <p class="pof-lead" data-role="offer-needs-account">{{ __('Finish setting up your account and business first, then come back to this link.') }}</p>
            <div class="pof-actions">
                <a class="pof-btn pof-btn-primary" href="{{ \Illuminate\Support\Facades\Route::has('signup.plan') ? route('signup.plan') : route('user.home') }}">{{ __('Finish setting up') }}</a>
            </div>

        @else
            @if ($state === 'in_progress')
                <p class="pof-lead" data-role="offer-in-progress">{{ __('You started this offer but have not finished paying. You can continue below.') }}</p>
            @endif

            @if ($terms !== null)
                <form method="POST" action="{{ route('offers.accept', ['token' => $token]) }}" class="pof-form pof-actions" data-role="offer-accept-form">
                    @csrf
                    <input type="hidden" name="terms_hash" value="{{ $terms->hash() }}">
                    @if ($offer->target_workspace_id === null && $view['workspace'] !== null)
                        <input type="hidden" name="workspace_uid" value="{{ $view['workspace']->uid }}">
                        <p class="pof-small" style="text-align:start" data-role="offer-workspace">{{ __('For workspace: :name', ['name' => $view['workspace']->name]) }}</p>
                    @endif

                    <label class="pof-consent" for="accept_terms">
                        <input type="checkbox" name="accept_terms" id="accept_terms" value="1" required>
                        <span>
                            {{ $isChange
                                ? __('I accept these terms for my existing subscription.')
                                : __('I accept these terms, including the recurring charge shown above.') }}
                        </span>
                    </label>

                    <button type="submit" class="pof-btn pof-btn-primary" data-role="offer-accept">
                        {{ $isChange ? __('Accept new pricing') : __('Accept offer & continue to payment') }}
                    </button>
                </form>
                @if (! $isChange)
                    <p class="pof-small">{{ __('You will enter your card details on Stripe. We never see or store them.') }}</p>
                @endif
            @endif
        @endif
    @endif
@endsection
