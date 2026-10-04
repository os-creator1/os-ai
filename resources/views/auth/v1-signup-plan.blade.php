@extends('layouts/authCover')

@section('title', __('Choose a plan'))

@section('auth-form')
    {{--
        Implementation Contract 21 §7 — the RESUMABLE state.

        An account whose customer backed out of Checkout (or whose payment
        failed) is not deleted and is not pretended to be paid: it exists, holds
        no plan, and lands here. Restarting checkout re-drives the same durable
        Workspace, Business and Primary Location rather than creating a second
        set, and the plan the customer already chose stays selected.
    --}}
    <h1 class="card-title fw-bold mb-1 h2">{{ __('Choose a plan') }}</h1>

    @if ($businessName)
        <p class="card-text mb-2">{{ __('Your account for :name is saved. Pick a plan to finish setting it up.', ['name' => $businessName]) }}</p>
    @endif

    @include('auth.partials._flash-summary')

    @if ($errors->any())
        <x-alert variant="danger" class="mb-1 alert-validation-msg" id="plan-error" role="alert">
            <div class="alert-body d-flex align-items-center">
                <x-ds-icon name="info" class="me-50" aria-hidden="true" />
                <span>{{ $errors->first() }}</span>
            </div>
        </x-alert>
    @endif

    @if (count($plans) === 0)
        <p class="card-text mb-2">{{ __('No plans are available right now. Please check back shortly.') }}</p>
    @else
        <form method="POST" action="{{ route('signup.resume') }}">
            @csrf

            @foreach ($plans as $plan)
                <div class="form-check mb-1">
                    <input class="form-check-input"
                           id="resume_{{ $plan['tier_value'] }}"
                           name="tier"
                           type="radio"
                           value="{{ $plan['tier_value'] }}"
                           @checked($pinnedTier !== null ? $pinnedTier === $plan['tier_value'] : $loop->first)
                           required>
                    <label class="form-check-label" for="resume_{{ $plan['tier_value'] }}">
                        <strong>{{ $plan['display_name'] }}</strong>
                        <span>{{ $plan['price'] }} {{ $plan['currency_code'] }} / {{ $plan['billing_cycle'] }}</span>
                        @if ($plan['trial_days'] !== null)
                            <span class="d-block text-muted small">{{ trans_choice('{1} :count day free trial|[2,*] :count day free trial', $plan['trial_days'], ['count' => $plan['trial_days']]) }}</span>
                        @endif
                    </label>
                </div>
            @endforeach

            <button type="submit" class="btn btn-primary w-100 mt-1">{{ __('Continue to payment') }}</button>
        </form>
    @endif

    <form action="{{ route('logout') }}" method="POST" class="mt-1">
        @csrf
        <button type="submit" class="btn btn-link w-100">{{ __('Sign out') }}</button>
    </form>
@endsection
