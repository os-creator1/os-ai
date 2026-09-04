@php($business = $onboarding->business)

<p>Add any public profile links you already have. This step can be skipped.</p>

<form method="POST" action="{{ route('customer.onboarding.assets.store') }}">
    @csrf

    <x-input name="google_business_profile_url" label="Google Business Profile URL" type="text" value="{{ old('google_business_profile_url', $business->google_business_profile_url ?? '') }}" />

    <x-input name="facebook_url" label="Facebook URL" type="text" value="{{ old('facebook_url', $business->facebook_url ?? '') }}" />

    <x-input name="instagram_url" label="Instagram URL" type="text" value="{{ old('instagram_url', $business->instagram_url ?? '') }}" />

    <x-button type="submit" variant="primary" class="mt-2">Continue</x-button>
</form>

<form method="POST" action="{{ route('customer.onboarding.assets.skip') }}" class="mt-1">
    @csrf
    <x-button type="submit" variant="secondary">Skip for now</x-button>
</form>
