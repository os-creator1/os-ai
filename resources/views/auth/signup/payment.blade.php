@extends('layouts/authCover')

@section('title', __('Confirm your plan'))

@section('auth-form')
    {{--
        STEP 4 — PLAN CONFIRMATION + PAYMENT. The one place the customer
        commits. The button wording follows the commercial truth of the
        catalog: a trial is only named when this plan actually has one.
        Card details are entered on Stripe's hosted page; we never see them.
    --}}
    @include('auth.signup._progress', ['step' => 4])

    <h1 class="card-title fw-bold mb-1 h2">{{ __('Confirm your plan') }}</h1>

    @include('auth.partials._flash-summary')

    @php
        $cta = $plan['trial_days'] !== null
            ? __('Start :days-day trial', ['days' => $plan['trial_days']])
            : __('Start :plan', ['plan' => $plan['display_name']]);
    @endphp

    <div class="card mb-2" data-role="plan-summary">
        <div class="card-body">
            <h2 class="h4 mb-25">{{ $plan['display_name'] }}</h2>
            <p class="mb-50">
                <span class="h3 fw-bold">{{ $plan['price'] }} {{ $plan['currency_code'] }}</span>
                <span class="text-muted">/ {{ $plan['billing_cycle'] }}</span>
            </p>
            @if ($plan['trial_days'] !== null)
                <p class="mb-50"><span class="badge bg-light-primary">{{ trans_choice('{1} :count day free trial|[2,*] :count day free trial', $plan['trial_days'], ['count' => $plan['trial_days']]) }}</span></p>
            @endif
            @if (count($plan['capabilities']) > 0)
                <ul class="list-unstyled mb-1">
                    @foreach (array_slice($plan['capabilities'], 0, 5) as $capability)
                        <li class="mb-25"><x-ds-icon name="check-circle" class="me-50" aria-hidden="true" />{{ $capability }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="text-muted small mb-0">
                {{ $account['email'] }} &middot; {{ $business['business_name'] }}
                &middot; <a href="{{ route('register.plan') }}">{{ __('Change plan') }}</a>
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('register.payment.start') }}">
        @csrf
        <button type="submit" class="btn btn-primary w-100">{{ $cta }}</button>
    </form>
    <p class="text-muted small text-center mt-1">{{ __('You will enter your card details on Stripe. We never see or store them.') }}</p>

    <p class="text-center mt-2"><a href="{{ route('register.business') }}">{{ __('Back') }}</a></p>
@endsection
