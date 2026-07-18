@php($business = $onboarding->business)

<p>Add any public profile links you already have. This step can be skipped.</p>

<form method="POST" action="{{ route('customer.onboarding.assets.store') }}">
    @csrf

    <div class="mb-1">
        <label class="form-label" for="google_business_profile_url">Google Business Profile URL</label>
        <input type="text" class="form-control" id="google_business_profile_url" name="google_business_profile_url" value="{{ old('google_business_profile_url', $business->google_business_profile_url ?? '') }}">
    </div>

    <div class="mb-1">
        <label class="form-label" for="facebook_url">Facebook URL</label>
        <input type="text" class="form-control" id="facebook_url" name="facebook_url" value="{{ old('facebook_url', $business->facebook_url ?? '') }}">
    </div>

    <div class="mb-1">
        <label class="form-label" for="instagram_url">Instagram URL</label>
        <input type="text" class="form-control" id="instagram_url" name="instagram_url" value="{{ old('instagram_url', $business->instagram_url ?? '') }}">
    </div>

    <button type="submit" class="btn btn-primary mt-2">Continue</button>
</form>

<form method="POST" action="{{ route('customer.onboarding.assets.skip') }}" class="mt-1">
    @csrf
    <button type="submit" class="btn btn-outline-secondary">Skip for now</button>
</form>
