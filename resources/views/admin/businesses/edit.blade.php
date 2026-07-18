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
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('admin.businesses.update', $business) }}">
                            @csrf
                            @method('PUT')

                            <div class="mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" name="name" class="form-control" maxlength="255" required
                                       value="{{ old('name', $business->name) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Industry</label>
                                <select name="industry" class="form-select" required>
                                    @foreach ($industries as $industry)
                                        <option value="{{ $industry->value }}" @selected(old('industry', $business->industry?->value) === $industry->value)>
                                            {{ ucfirst(str_replace('_', ' ', $industry->value)) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Industry (other)</label>
                                <input type="text" name="industry_other" class="form-control" maxlength="255"
                                       value="{{ old('industry_other', $business->industry_other) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" maxlength="5000" rows="4">{{ old('description', $business->description) }}</textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" maxlength="255"
                                       value="{{ old('email', $business->email) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" maxlength="50"
                                       value="{{ old('phone', $business->phone) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Website URL</label>
                                <input type="text" name="website_url" class="form-control" maxlength="2048"
                                       value="{{ old('website_url', $business->website_url) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Google Business Profile URL</label>
                                <input type="text" name="google_business_profile_url" class="form-control" maxlength="2048"
                                       value="{{ old('google_business_profile_url', $business->google_business_profile_url) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Facebook URL</label>
                                <input type="text" name="facebook_url" class="form-control" maxlength="2048"
                                       value="{{ old('facebook_url', $business->facebook_url) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Instagram URL</label>
                                <input type="text" name="instagram_url" class="form-control" maxlength="2048"
                                       value="{{ old('instagram_url', $business->instagram_url) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Country code</label>
                                <input type="text" name="country_code" class="form-control" maxlength="2" required
                                       value="{{ old('country_code', $business->country_code) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Timezone</label>
                                <input type="text" name="timezone" class="form-control" maxlength="64" required
                                       value="{{ old('timezone', $business->timezone) }}">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Currency code</label>
                                <input type="text" name="currency_code" class="form-control" maxlength="3" required
                                       value="{{ old('currency_code', $business->currency_code) }}">
                            </div>

                            <button type="submit" class="btn btn-primary">Save changes</button>
                            <a href="{{ route('admin.businesses.show', $business) }}" class="btn btn-outline-secondary">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
