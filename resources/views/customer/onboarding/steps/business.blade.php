@php($business = $onboarding->business)

<form method="POST" action="{{ route('customer.onboarding.business.store') }}">
    @csrf

    <div class="mb-1">
        <label class="form-label" for="name">Business name</label>
        <input type="text" class="form-control" id="name" name="name" value="{{ old('name', $business->name ?? '') }}" required>
    </div>

    <div class="mb-1">
        <label class="form-label" for="industry">Industry</label>
        <select class="form-control" id="industry" name="industry" required>
            @foreach (\App\Enums\Business\BusinessIndustry::cases() as $industry)
                <option value="{{ $industry->value }}" @selected(old('industry', $business->industry->value ?? null) === $industry->value)>
                    {{ ucwords(str_replace('_', ' ', $industry->value)) }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="mb-1">
        <label class="form-label" for="description">Description</label>
        <textarea class="form-control" id="description" name="description" maxlength="5000">{{ old('description', $business->description ?? '') }}</textarea>
    </div>

    <div class="mb-1">
        <label class="form-label" for="email">Public email</label>
        <input type="email" class="form-control" id="email" name="email" value="{{ old('email', $business->email ?? '') }}">
    </div>

    <div class="mb-1">
        <label class="form-label" for="phone">Phone</label>
        <input type="text" class="form-control" id="phone" name="phone" value="{{ old('phone', $business->phone ?? '') }}">
    </div>

    <div class="mb-1">
        <label class="form-label" for="website_url">Website</label>
        <input type="text" class="form-control" id="website_url" name="website_url" value="{{ old('website_url', $business->website_url ?? '') }}">
    </div>

    <div class="row">
        <div class="col-md-4 mb-1">
            <label class="form-label" for="country_code">Country code</label>
            <input type="text" class="form-control" id="country_code" name="country_code" maxlength="2" value="{{ old('country_code', $business->country_code ?? '') }}" required>
        </div>
        <div class="col-md-4 mb-1">
            <label class="form-label" for="timezone">Timezone</label>
            <input type="text" class="form-control" id="timezone" name="timezone" value="{{ old('timezone', $business->timezone ?? '') }}" required>
        </div>
        <div class="col-md-4 mb-1">
            <label class="form-label" for="currency_code">Currency code</label>
            <input type="text" class="form-control" id="currency_code" name="currency_code" maxlength="3" value="{{ old('currency_code', $business->currency_code ?? '') }}" required>
        </div>
    </div>

    <button type="submit" class="btn btn-primary mt-2">Continue</button>
</form>
