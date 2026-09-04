@extends('layouts/contentLayoutMaster')

@section('title', 'Edit ' . $business->name)

@section('content')
    <section id="admin-business-edit">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Edit {{ $business->name }}</h4>
                        <p class="text-muted mb-0">Owner: {{ $business->customer?->user?->displayName() ?? 'Unknown' }}</p>
                    </div>
                    <div class="card-body">
                        @if ($errors->any())
                            <x-alert variant="danger">
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </x-alert>
                        @endif

                        <form method="POST" action="{{ route('admin.businesses.update', $business) }}">
                            @csrf
                            @method('PUT')

                            <x-input name="name" label="Name" type="text" maxlength="255" value="{{ old('name', $business->name) }}" required />

                            <x-select
                                name="industry"
                                label="Industry"
                                :options="collect($industries)->mapWithKeys(fn ($industry) => [$industry->value => ucfirst(str_replace('_', ' ', $industry->value))])->all()"
                                :selected="old('industry', $business->industry?->value)"
                                required
                            />

                            <x-input name="industry_other" label="Industry (other)" type="text" maxlength="255" value="{{ old('industry_other', $business->industry_other) }}" />

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" maxlength="5000" rows="4">{{ old('description', $business->description) }}</textarea>
                            </div>

                            <x-input name="email" label="Email" type="email" maxlength="255" value="{{ old('email', $business->email) }}" />

                            <x-input name="phone" label="Phone" type="text" maxlength="50" value="{{ old('phone', $business->phone) }}" />

                            <x-input name="website_url" label="Website URL" type="text" maxlength="2048" value="{{ old('website_url', $business->website_url) }}" />

                            <x-input name="google_business_profile_url" label="Google Business Profile URL" type="text" maxlength="2048" value="{{ old('google_business_profile_url', $business->google_business_profile_url) }}" />

                            <x-input name="facebook_url" label="Facebook URL" type="text" maxlength="2048" value="{{ old('facebook_url', $business->facebook_url) }}" />

                            <x-input name="instagram_url" label="Instagram URL" type="text" maxlength="2048" value="{{ old('instagram_url', $business->instagram_url) }}" />

                            <x-input name="country_code" label="Country code" type="text" maxlength="2" value="{{ old('country_code', $business->country_code) }}" required />

                            <x-input name="timezone" label="Timezone" type="text" maxlength="64" value="{{ old('timezone', $business->timezone) }}" required />

                            <x-input name="currency_code" label="Currency code" type="text" maxlength="3" value="{{ old('currency_code', $business->currency_code) }}" required />

                            <x-button type="submit" variant="primary">Save changes</x-button>
                            <x-button :href="route('admin.businesses.show', $business)" variant="secondary">Cancel</x-button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
