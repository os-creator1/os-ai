@extends('layouts/authCover')

@section('title', __('Choose your plan'))

@section('auth-form')
    {{--
        STEP 1 — PLAN. Every price, currency, cycle, trial length and
        capability line comes from the V1 plan catalog through
        PlatformPlanPresenter (§8); nothing commercial is written here.
    --}}
    @include('auth.signup._progress', ['step' => 1])

    <h1 class="card-title fw-bold mb-1 h2">{{ __('Choose your plan') }}</h1>
    <p class="card-text mb-2">{{ __('Pick the plan that fits. You can change it later.') }}</p>

    @include('auth.partials._flash-summary')

    @if ($errors->has('plan'))
        <x-alert variant="danger" class="mb-1 alert-validation-msg" id="plan-error" role="alert">
            <div class="alert-body d-flex align-items-center">
                <x-ds-icon name="info" class="me-50" aria-hidden="true" />
                <span>{{ $errors->first('plan') }}</span>
            </div>
        </x-alert>
    @endif

    @foreach ($plans as $plan)
        @php
            $featured = $selected !== null ? $selected === $plan['tier_value'] : $plan['tier_value'] === 'growth';
            $capabilities = $plan['capabilities'];
            $shown = array_slice($capabilities, 0, 5);
        @endphp
        <div class="card mb-1 {{ $featured ? 'border-primary' : '' }}" data-role="plan-card" data-plan="{{ $plan['tier_value'] }}">
            <div class="card-body">
                <h2 class="h4 mb-25">{{ $plan['display_name'] }}</h2>
                <p class="mb-50">
                    <span class="h3 fw-bold">{{ $plan['price'] }} {{ $plan['currency_code'] }}</span>
                    <span class="text-muted">/ {{ $plan['billing_cycle'] }}</span>
                </p>
                @if ($plan['trial_days'] !== null)
                    <p class="mb-50"><span class="badge bg-light-primary">{{ trans_choice('{1} :count day free trial|[2,*] :count day free trial', $plan['trial_days'], ['count' => $plan['trial_days']]) }}</span></p>
                @endif
                @if (count($shown) > 0)
                    <ul class="list-unstyled mb-1">
                        @foreach ($shown as $capability)
                            <li class="mb-25"><x-ds-icon name="check-circle" class="me-50" aria-hidden="true" />{{ $capability }}</li>
                        @endforeach
                        @if (count($capabilities) > count($shown))
                            <li class="text-muted">{{ __('and :count more', ['count' => count($capabilities) - count($shown)]) }}</li>
                        @endif
                    </ul>
                @endif
                <form method="POST" action="{{ route('register.plan.select') }}">
                    @csrf
                    <input type="hidden" name="tier" value="{{ $plan['tier_value'] }}">
                    <button type="submit" class="btn {{ $featured ? 'btn-primary' : 'btn-outline-primary' }} w-100">{{ __('Start with :plan', ['plan' => $plan['display_name']]) }}</button>
                </form>
            </div>
        </div>
    @endforeach

    <p class="text-center mt-2">
        <span>{{ __('Already have an account?') }}</span>
        <a href="{{ route('login') }}">{{ __('Sign in') }}</a>
    </p>
@endsection
