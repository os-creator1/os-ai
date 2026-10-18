{{-- The pinned plan, with the way back to change it. --}}
@if (! empty($plan))
    <p class="card-text mb-2" data-role="selected-plan">
        {{ __('Selected plan') }}: <strong>{{ $plan['display_name'] }}</strong>
        &middot; <a href="{{ route('register.plan') }}">{{ __('Change plan') }}</a>
    </p>
@endif
