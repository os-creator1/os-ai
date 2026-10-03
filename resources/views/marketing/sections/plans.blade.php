@if (count($plans) === 0)
    <div class="marketing-plans-empty">
        {{ __('Signups are not open right now. Please check back shortly.') }}
    </div>
@else
    <div class="marketing-plans">
        @foreach ($plans as $index => $plan)
            <div class="marketing-plan-card {{ $index === 1 && count($plans) > 1 ? 'marketing-plan-card--featured' : '' }}">
                <h3 class="marketing-plan-card__name">{{ $plan['display_name'] }}</h3>
                <p class="marketing-plan-card__price">
                    {{ $plan['price'] }} {{ $plan['currency_code'] }}
                    <small>/ {{ $plan['billing_cycle'] }}</small>
                </p>
                @if ($plan['trial_days'] !== null)
                    <p class="marketing-plan-card__trial">
                        {{ trans_choice('{1} :count day free trial|[2,*] :count day free trial', $plan['trial_days'], ['count' => $plan['trial_days']]) }}
                    </p>
                @endif
                @if (count($plan['capabilities']) > 0)
                    <ul class="marketing-plan-card__features">
                        @foreach ($plan['capabilities'] as $capability)
                            <li>{{ $capability }}</li>
                        @endforeach
                    </ul>
                @endif
                @if (config('account.can_register'))
                    <a href="{{ route('register') }}" class="marketing-btn marketing-btn--primary">{{ __('Create account') }}</a>
                @endif
            </div>
        @endforeach
    </div>
@endif
