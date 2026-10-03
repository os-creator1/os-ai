<div class="marketing-final-cta">
    <h2>{{ __('Ready to run your business from one place?') }}</h2>
    <p>{{ __('Create your account and pick a plan — you can always change it later.') }}</p>
    @if (config('account.can_register'))
        <a href="{{ route('register') }}" class="marketing-btn marketing-btn--primary marketing-btn--large">{{ __('Create account') }}</a>
    @else
        <a href="{{ route('login') }}" class="marketing-btn marketing-btn--primary marketing-btn--large">{{ __('Log in') }}</a>
    @endif
</div>
