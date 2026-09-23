{{--
    Implementation Contract 21 §7 — the RESUMABLE state.

    An account whose customer backed out of Checkout is not deleted and is not
    pretended to be paid: it exists, holds no plan, and lands here. Restarting
    checkout re-drives the same durable Workspace, Business and Primary
    Location rather than creating a second set.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Choose a plan') }}</title>
</head>
<body>
<main>
    <h1>{{ __('Choose a plan') }}</h1>

    @if ($businessName)
        <p>{{ __('Your account for :name is saved. Pick a plan to finish setting it up.', ['name' => $businessName]) }}</p>
    @endif

    @if (session('message'))
        <p role="status">{{ session('message') }}</p>
    @endif

    @if ($errors->any())
        <ul role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    @if (count($plans) === 0)
        <p>{{ __('No plans are available right now. Please check back shortly.') }}</p>
    @else
        <form method="POST" action="{{ route('signup.resume') }}">
            @csrf

            @foreach ($plans as $plan)
                <div>
                    <input id="resume_{{ $plan['tier_value'] }}"
                           name="tier"
                           type="radio"
                           value="{{ $plan['tier_value'] }}"
                           @checked($loop->first)
                           required>
                    <label for="resume_{{ $plan['tier_value'] }}">
                        <strong>{{ $plan['display_name'] }}</strong>
                        <span>{{ $plan['price'] }} {{ $plan['currency_code'] }} / {{ $plan['billing_cycle'] }}</span>
                        @if ($plan['trial_days'] !== null)
                            <span>{{ trans_choice('{1} :count day free trial|[2,*] :count day free trial', $plan['trial_days'], ['count' => $plan['trial_days']]) }}</span>
                        @endif
                    </label>
                </div>
            @endforeach

            <button type="submit">{{ __('Continue to payment') }}</button>
        </form>
    @endif
</main>
</body>
</html>
