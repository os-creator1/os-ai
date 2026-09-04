@extends('layouts/contentLayoutMaster')

@section('title', 'Business Profile')

@section('content')
    <section id="business-edit">
        <div class="row">
            <div class="col-12">
                <x-card title="Business Profile">
                    @if (session('status') === 'success')
                        <x-alert variant="success">{{ session('message') }}</x-alert>
                    @endif

                    @if ($errors->any())
                        <x-alert variant="danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif

                    <form method="POST" action="{{ route('customer.business.update') }}">
                        @csrf
                        @method('PUT')

                        <x-input name="name" label="Business name" type="text" value="{{ old('name', $business->name) }}" required />

                        <x-select
                            name="industry"
                            label="Industry"
                            :options="collect(\App\Enums\Business\BusinessIndustry::cases())->mapWithKeys(fn ($industry) => [$industry->value => ucwords(str_replace('_', ' ', $industry->value))])->all()"
                            :selected="old('industry', $business->industry->value)"
                            required
                        />

                        <x-input name="industry_other" label="Industry (other)" type="text" maxlength="255" value="{{ old('industry_other', $business->industry_other) }}" />

                        <div class="mb-1">
                            <label class="form-label" for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" maxlength="5000">{{ old('description', $business->description) }}</textarea>
                        </div>

                        <x-input name="email" label="Public email" type="email" value="{{ old('email', $business->email) }}" />

                        <x-input name="phone" label="Phone" type="text" value="{{ old('phone', $business->phone) }}" />

                        <x-input name="website_url" label="Website" type="text" value="{{ old('website_url', $business->website_url) }}" />

                        <x-input name="google_business_profile_url" label="Google Business Profile URL" type="text" value="{{ old('google_business_profile_url', $business->google_business_profile_url) }}" />

                        <x-input name="facebook_url" label="Facebook URL" type="text" value="{{ old('facebook_url', $business->facebook_url) }}" />

                        <x-input name="instagram_url" label="Instagram URL" type="text" value="{{ old('instagram_url', $business->instagram_url) }}" />

                        <div class="row">
                            <div class="col-md-4">
                                <x-input name="country_code" label="Country code" type="text" maxlength="2" value="{{ old('country_code', $business->country_code) }}" required />
                            </div>
                            <div class="col-md-4">
                                <x-input name="timezone" label="Timezone" type="text" value="{{ old('timezone', $business->timezone) }}" required />
                            </div>
                            <div class="col-md-4">
                                <x-input name="currency_code" label="Currency code" type="text" maxlength="3" value="{{ old('currency_code', $business->currency_code) }}" required />
                            </div>
                        </div>

                        <x-button type="submit" variant="primary" class="mt-2">Save changes</x-button>
                    </form>
                </x-card>
            </div>
        </div>
    </section>
@endsection
