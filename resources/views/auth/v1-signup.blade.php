{{--
    Implementation Contract 21 §7 — the canonical V1 signup.

    Deliberately plain: the contract permits simplifying the visual
    presentation, and requires the persisted authority to be correct.

    THERE IS NO PAYMENT-METHOD DROPDOWN. V1 takes exactly one payment route —
    a hosted lane-A Stripe Checkout Session. Braintree / Cash / NowPayments /
    Authorize.Net / EasyPay / FedaPay / Vodacom belong to the legacy signup,
    which is no longer routed.

    NOTHING COMMERCIAL IS HARD-CODED HERE. Every price, currency, cycle, trial
    length and capability line comes from the V1 plan catalog through
    PlatformPlanPresenter (§8).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Create your account') }}</title>
</head>
<body>
<main>
    <h1>{{ __('Create your account') }}</h1>

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
        <p>{{ __('Signups are not open right now. Please check back shortly.') }}</p>
    @else
        <form method="POST" action="{{ route('register') }}">
            @csrf

            <fieldset>
                <legend>{{ __('About you') }}</legend>
                <label for="first_name">{{ __('First name') }}</label>
                <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required>

                <label for="last_name">{{ __('Last name') }}</label>
                <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}">

                <label for="email">{{ __('Email') }}</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required>

                <label for="password">{{ __('Password') }}</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">

                <label for="password_confirmation">{{ __('Confirm password') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </fieldset>

            <fieldset>
                <legend>{{ __('Your business') }}</legend>
                <label for="business_name">{{ __('Business name') }}</label>
                <input id="business_name" name="business_name" type="text" value="{{ old('business_name') }}" required>

                <label for="industry">{{ __('What kind of business is it?') }}</label>
                <select id="industry" name="industry" required>
                    @foreach ($industries as $industry)
                        <option value="{{ $industry->value }}" @selected(old('industry') === $industry->value)>
                            {{ ucwords(str_replace('_', ' ', $industry->value)) }}
                        </option>
                    @endforeach
                </select>

                <label for="country_code">{{ __('Country') }}</label>
                <input id="country_code" name="country_code" type="text" maxlength="2" value="{{ old('country_code', 'US') }}" required>

                <label for="timezone">{{ __('Time zone') }}</label>
                <input id="timezone" name="timezone" type="text" value="{{ old('timezone', config('app.timezone')) }}" required>
            </fieldset>

            <fieldset>
                <legend>{{ __('Choose a plan') }}</legend>

                @foreach ($plans as $plan)
                    <div>
                        <input id="tier_{{ $plan['tier_value'] }}"
                               name="tier"
                               type="radio"
                               value="{{ $plan['tier_value'] }}"
                               @checked(old('tier') === $plan['tier_value'] || (! old('tier') && $loop->first))
                               required>
                        <label for="tier_{{ $plan['tier_value'] }}">
                            <strong>{{ $plan['display_name'] }}</strong>
                            <span>{{ $plan['price'] }} {{ $plan['currency_code'] }} / {{ $plan['billing_cycle'] }}</span>
                            @if ($plan['trial_days'] !== null)
                                <span>{{ trans_choice('{1} :count day free trial|[2,*] :count day free trial', $plan['trial_days'], ['count' => $plan['trial_days']]) }}</span>
                            @endif
                        </label>

                        @if (count($plan['capabilities']) > 0)
                            <ul>
                                @foreach ($plan['capabilities'] as $capability)
                                    <li>{{ $capability }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </fieldset>

            <p>
                {{ __('You will enter your card details on Stripe. We never see or store them.') }}
            </p>

            <button type="submit">{{ __('Continue to payment') }}</button>
        </form>
    @endif

    <p><a href="{{ route('login') }}">{{ __('Already have an account? Sign in') }}</a></p>
</main>
</body>
</html>
