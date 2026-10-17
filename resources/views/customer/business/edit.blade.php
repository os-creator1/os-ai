@extends('layouts/contentLayoutMaster')

@section('title', 'Business Profile')

@section('page-style')
    @include('customer.settings._redesign-assets')
@endsection

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('content')
    @php
        $customerContext = request()->attributes->get('customerContext');
        $settingsUrl = $customerContext instanceof \App\Library\Navigation\CustomerContext && $customerContext->selectedBusiness !== null
            ? route('customer.workspaces.businesses.settings.show', [$customerContext->selectedBusiness->workspaceUid, $customerContext->selectedBusiness->uid])
            : route('customer.workspaces.index');

        $industryLabel = ucwords(str_replace('_', ' ', $business->industry->value));
        $previewIndustry = $business->industry->value === 'other' && filled($business->industry_other) ? $business->industry_other : $industryLabel;
        $initials = '';
        foreach (preg_split('/\s+/', trim((string) $business->name)) ?: [] as $word) {
            if ($word !== '' && mb_strlen($initials) < 2) {
                $initials .= mb_strtoupper(mb_substr($word, 0, 1));
            }
        }
        $reachable = filled($business->email) || filled($business->phone);
    @endphp

    <section id="business-edit" class="sr-page sr-page--wide">
        <a href="{{ $settingsUrl }}" class="sr-back"><x-ds-icon name="arrow-left" size="14" aria-hidden="true" /> Back to Settings</a>
        <div class="sr-head">
            <div class="sr-head__main">
                <h1 class="sr-title">Business details</h1>
                <p class="sr-lede">The public details customers see for this Business.</p>
            </div>
        </div>

        @if (session('status') === 'success')
            <x-alert :dismissible="true" variant="success" role="status" data-role="flash-message">{{ session('message') }}</x-alert>
        @endif

        @if ($errors->any())
            <x-alert variant="danger" data-role="validation-summary">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <div class="sr-grid-main">
            <div class="sr-card">
                <form method="POST" action="{{ route('customer.business.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="sr-section">
                        <div class="sr-section__label">
                            <h3>About</h3>
                            <p>Who you are and what you do.</p>
                        </div>
                        <div class="sr-fields">
                            <div class="sr-field sr-span-2">
                                <label for="name">Business name</label>
                                <input type="text" id="name" name="name" value="{{ old('name', $business->name) }}" required class="form-control @error('name') is-invalid @enderror">
                            </div>
                            <div class="sr-field">
                                <label for="industry">Industry</label>
                                <select name="industry" id="industry" class="form-select" required>
                                    @foreach (\App\Enums\Business\BusinessIndustry::cases() as $industry)
                                        <option value="{{ $industry->value }}" @selected((string) old('industry', $business->industry->value) === (string) $industry->value)>{{ ucwords(str_replace('_', ' ', $industry->value)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sr-field">
                                <label for="industry_other">Industry (other)</label>
                                <input type="text" id="industry_other" name="industry_other" maxlength="255" placeholder="Only if yours isn't listed" value="{{ old('industry_other', $business->industry_other) }}" class="form-control @error('industry_other') is-invalid @enderror">
                            </div>
                            <div class="sr-field sr-span-2">
                                <label for="description">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="4" maxlength="5000" placeholder="A few sentences about your business, in your own words.">{{ old('description', $business->description) }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="sr-section">
                        <div class="sr-section__label">
                            <h3>Contact</h3>
                            <p>How customers reach you.</p>
                        </div>
                        <div class="sr-fields">
                            <div class="sr-field">
                                <label for="email">Public email</label>
                                <div class="sr-input-icon"><x-ds-icon name="mail" size="16" />
                                    <input type="email" id="email" name="email" placeholder="hello@yourbusiness.com" value="{{ old('email', $business->email) }}" class="form-control @error('email') is-invalid @enderror"></div>
                            </div>
                            <div class="sr-field">
                                <label for="phone">Phone</label>
                                <div class="sr-input-icon"><x-ds-icon name="phone" size="16" />
                                    <input type="text" id="phone" name="phone" value="{{ old('phone', $business->phone) }}" class="form-control @error('phone') is-invalid @enderror"></div>
                            </div>
                            <div class="sr-field sr-span-2">
                                <label for="website_url">Website</label>
                                <div class="sr-input-icon"><x-ds-icon name="globe" size="16" />
                                    <input type="text" id="website_url" name="website_url" placeholder="https://" value="{{ old('website_url', $business->website_url) }}" class="form-control @error('website_url') is-invalid @enderror"></div>
                            </div>
                        </div>
                    </div>

                    <div class="sr-section">
                        <div class="sr-section__label">
                            <h3>Online profiles</h3>
                            <p>Links to where you're listed.</p>
                        </div>
                        <div class="sr-fields">
                            <div class="sr-field sr-span-2">
                                <label for="google_business_profile_url">Google Business Profile URL</label>
                                <div class="sr-input-icon"><x-ds-icon name="map-pin" size="16" />
                                    <input type="text" id="google_business_profile_url" name="google_business_profile_url" placeholder="https://" value="{{ old('google_business_profile_url', $business->google_business_profile_url) }}" class="form-control @error('google_business_profile_url') is-invalid @enderror"></div>
                            </div>
                            <div class="sr-field">
                                <label for="facebook_url">Facebook URL</label>
                                <div class="sr-input-icon"><x-ds-icon name="link" size="16" />
                                    <input type="text" id="facebook_url" name="facebook_url" placeholder="https://" value="{{ old('facebook_url', $business->facebook_url) }}" class="form-control @error('facebook_url') is-invalid @enderror"></div>
                            </div>
                            <div class="sr-field">
                                <label for="instagram_url">Instagram URL</label>
                                <div class="sr-input-icon"><x-ds-icon name="link" size="16" />
                                    <input type="text" id="instagram_url" name="instagram_url" placeholder="https://" value="{{ old('instagram_url', $business->instagram_url) }}" class="form-control @error('instagram_url') is-invalid @enderror"></div>
                            </div>
                        </div>
                    </div>

                    <div class="sr-section">
                        <div class="sr-section__label">
                            <h3>Region</h3>
                            <p>Used for dates, times and prices.</p>
                        </div>
                        <div class="sr-fields">
                            <div class="sr-span-2">
                                @include('customer.business.partials.locale-fields', [
                                    'colClass' => 'col-md-6',
                                    'stored' => ['country_code' => $business->country_code, 'timezone' => $business->timezone, 'currency_code' => $business->currency_code],
                                ])
                            </div>
                        </div>
                    </div>

                    <div class="sr-card__foot" style="justify-content:flex-end">
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>

            <aside data-role="business-summary">
                <div class="sr-eyebrow">How customers see you</div>
                <div class="sr-card">
                    <div class="sr-card__body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="sr-tile sr-tile--lg" style="font-weight:700">{{ $initials !== '' ? $initials : '?' }}</span>
                            <div style="min-width:0">
                                <div class="fw-bold text-break">{{ $business->name }}</div>
                                <div class="sr-card__sub" style="color:var(--color-primary)">{{ $previewIndustry }}</div>
                            </div>
                        </div>
                        @if (filled($business->description))
                            <p class="mb-3" style="font-size:.8125rem">{{ \Illuminate\Support\Str::limit($business->description, 180) }}</p>
                        @else
                            <p class="mb-3 is-empty" style="font-size:.8125rem;font-style:italic;color:var(--color-text-muted)">No description yet.</p>
                        @endif
                        <div class="sr-dl" style="border-top:1px solid var(--color-border);padding-top:var(--space-3)">
                            <div class="{{ filled($business->website_url) ? '' : 'is-empty' }}"><x-ds-icon name="globe" size="14" /><span class="text-break">{{ filled($business->website_url) ? $business->website_url : 'No website' }}</span></div>
                            <div class="{{ filled($business->email) ? '' : 'is-empty' }}"><x-ds-icon name="mail" size="14" /><span class="text-break">{{ filled($business->email) ? $business->email : 'No public email' }}</span></div>
                            <div class="{{ filled($business->phone) ? '' : 'is-empty' }}"><x-ds-icon name="phone" size="14" /><span>{{ filled($business->phone) ? $business->phone : 'No phone' }}</span></div>
                        </div>
                    </div>
                </div>
                @unless ($reachable)
                    <div class="sr-callout sr-callout--warn" data-role="reachability-hint">
                        <x-ds-icon name="triangle-alert" size="16" />
                        <p>Add a public email or phone so customers can reach you.</p>
                    </div>
                @endunless
            </aside>
        </div>
    </section>
@endsection
