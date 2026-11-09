{{--
    First onboarding screen: Google quick start (primary) and manual setup (secondary).
    The manual form is exactly what "Set up manually" opens; nothing about it changes.
--}}
<div class="gbp-start text-center py-1" data-role="google-start">
    <h4 class="mb-50">Set up your business in about a minute</h4>
    <p class="text-muted mb-2">
        Already on Google? Import your Business Profile, check it, and you are done.
        MotionGrove only reads from Google. It never changes your profile and never publishes anything.
    </p>

    <form method="POST" action="{{ route('customer.onboarding.google.connect') }}" class="text-start mx-auto" style="max-width: 640px" data-role="google-connect-form">
        @csrf

        @if ($googleStart['needsLocale'])
            <p class="form-label text-label mb-50">First, where do you do business? We never guess these.</p>
            @include('customer.business.partials.locale-fields', ['stored' => [], 'suggestDefaults' => true, 'colClass' => 'col-12 col-md-4'])
        @endif

        <div class="form-check my-1">
            <input class="form-check-input" type="checkbox" id="address_consent" name="address_consent" value="1" @checked(old('address_consent'))>
            <label class="form-check-label" for="address_consent">
                Also read my business city from Google to save typing.
                <span class="text-muted">Street and postal code are never imported.</span>
            </label>
        </div>

        <div class="d-grid d-md-flex gap-1 justify-content-md-center mt-2">
            <x-button type="submit" variant="primary" size="lg" data-role="connect-google">Connect Google Business Profile</x-button>
            <a class="btn btn-outline-secondary btn-lg" href="{{ route('customer.onboarding.show', ['step' => 'business', 'manual' => 1]) }}" data-role="set-up-manually">Set up manually</a>
        </div>
    </form>
</div>
