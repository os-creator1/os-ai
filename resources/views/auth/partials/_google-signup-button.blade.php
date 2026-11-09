{{-- "Continue with Google" for the signup flow: a full-width, accessible button (min 44px tall) that works at any width. --}}
<a class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-1 mb-1" style="min-height:44px"
   href="{{ route('register.google') }}" data-role="google-signup" rel="nofollow">
    @include('auth.partials._google-mark')
    <span>{{ __('locale.auth.continue_with', ['provider' => 'Google']) }}</span>
</a>
<div class="divider my-1" aria-hidden="true"><div class="divider-text">{{ __('locale.auth.or') }}</div></div>
