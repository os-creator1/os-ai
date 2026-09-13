@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@php
    $status = $registration->status?->value ?? 'not_started';
    $useCase = old('use_case', $registration->use_case ?? null);
@endphp

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Text messaging</h4>
            <p class="text-caption mb-0">One more step before this number is ready to send and receive texts.</p>
        </div>
    </div>

    <x-card :padded="true" class="mb-2">
        <dl class="row mb-0">
            <dt class="col-sm-4">Phone number</dt>
            <dd class="col-sm-8" data-role="phone-number">{{ $phoneNumber }}</dd>

            <dt class="col-sm-4">Messaging registration</dt>
            <dd class="col-sm-8">
                @switch($status)
                    @case('pending')
                        <x-badge variant="warning">Pending review</x-badge>
                        @break
                    @case('rejected')
                        <x-badge variant="danger">Needs attention</x-badge>
                        @break
                    @case('approved')
                        <x-badge variant="success">Approved</x-badge>
                        @break
                    @default
                        <x-badge variant="neutral">Not started</x-badge>
                @endswitch
            </dd>
        </dl>

        @switch($status)
            @case('pending')
                <p class="text-caption text-muted mt-2 mb-0">We've submitted your business details for messaging registration. This is typically reviewed within a few business days — nothing else to do right now.</p>
                @break
            @case('rejected')
                <x-alert variant="danger" icon="alert-circle" class="mt-2 mb-0" data-role="rejection-reason">
                    <strong class="d-block">This needs a correction</strong>
                    {{ $registration->rejection_reason ?: 'The reviewer did not approve this submission. Please review the details below and try again.' }}
                </x-alert>
                @break
            @case('approved')
                @break
            @default
                <p class="text-caption text-muted mt-2 mb-0">Carriers require a few details about this Business before texts can be delivered reliably. This is a one-time step.</p>
        @endswitch
    </x-card>

    @if($status !== 'pending')
        <x-card title="Business details" :padded="true">
            <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.update', [$workspaceUid, $businessUid]) }}">
                @csrf

                <div class="mb-1">
                    <label class="form-label required" for="legal_business_name">Legal business name</label>
                    <input type="text" class="form-control @error('legal_business_name') is-invalid @enderror" id="legal_business_name" name="legal_business_name"
                           value="{{ old('legal_business_name', $registration->legal_business_name ?? '') }}" maxlength="191" required>
                    @error('legal_business_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label class="form-label required">Business type</label>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" id="entity_ein" name="entity_type" value="ein"
                               {{ old('entity_type', $registration->entity_type?->value ?? 'ein') === 'ein' ? 'checked' : '' }}>
                        <label class="form-check-label" for="entity_ein">Registered business (has an EIN)</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" id="entity_sole" name="entity_type" value="sole_proprietor"
                               {{ old('entity_type', $registration->entity_type?->value ?? '') === 'sole_proprietor' ? 'checked' : '' }}>
                        <label class="form-check-label" for="entity_sole">Individual / sole proprietor (no EIN)</label>
                    </div>
                </div>

                <div class="mb-1" id="ein-field">
                    <label class="form-label" for="ein">EIN</label>
                    <input type="text" class="form-control @error('ein') is-invalid @enderror" id="ein" name="ein"
                           value="{{ old('ein', $registration->ein ?? '') }}" maxlength="20">
                    @error('ein')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="address_line_1">Business address</label>
                    <input type="text" class="form-control mb-1 @error('address_line_1') is-invalid @enderror" id="address_line_1" name="address_line_1"
                           value="{{ old('address_line_1', $registration->address_line_1 ?? '') }}" maxlength="191" placeholder="Street address" required>
                    @error('address_line_1')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <input type="text" class="form-control" name="address_line_2"
                           value="{{ old('address_line_2', $registration->address_line_2 ?? '') }}" maxlength="191" placeholder="Apt, suite, etc. (optional)">
                </div>

                <div class="row">
                    <div class="col-md-5 mb-1">
                        <label class="form-label required" for="city">City</label>
                        <input type="text" class="form-control @error('city') is-invalid @enderror" id="city" name="city"
                               value="{{ old('city', $registration->city ?? '') }}" maxlength="120" required>
                        @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4 mb-1">
                        <label class="form-label required" for="region">State</label>
                        <input type="text" class="form-control @error('region') is-invalid @enderror" id="region" name="region"
                               value="{{ old('region', $registration->region ?? '') }}" maxlength="120" required>
                        @error('region')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3 mb-1">
                        <label class="form-label required" for="postal_code">ZIP code</label>
                        <input type="text" class="form-control @error('postal_code') is-invalid @enderror" id="postal_code" name="postal_code"
                               value="{{ old('postal_code', $registration->postal_code ?? '') }}" maxlength="20" required>
                        @error('postal_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="website_url">Website</label>
                    <input type="url" class="form-control @error('website_url') is-invalid @enderror" id="website_url" name="website_url"
                           value="{{ old('website_url', $registration->website_url ?? '') }}" maxlength="255" placeholder="https://" required>
                    @error('website_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row">
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="contact_email">Contact email</label>
                        <input type="email" class="form-control @error('contact_email') is-invalid @enderror" id="contact_email" name="contact_email"
                               value="{{ old('contact_email', $registration->contact_email ?? '') }}" maxlength="191" required>
                        @error('contact_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="contact_phone">Contact phone</label>
                        <input type="text" class="form-control @error('contact_phone') is-invalid @enderror" id="contact_phone" name="contact_phone"
                               value="{{ old('contact_phone', $registration->contact_phone ?? '') }}" maxlength="32" required>
                        @error('contact_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="use_case">What will you use texting for?</label>
                    <select class="form-select @error('use_case') is-invalid @enderror" id="use_case" name="use_case" required>
                        <option value="" disabled {{ $useCase ? '' : 'selected' }}>Choose one</option>
                        @foreach($useCases as $key => $label)
                            <option value="{{ $key }}" {{ $useCase === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('use_case')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="opt_in_method">How do customers agree to receive texts?</label>
                    <textarea class="form-control @error('opt_in_method') is-invalid @enderror" id="opt_in_method" name="opt_in_method" rows="2" maxlength="2000" required
                              placeholder="e.g. Customers check a box on our signup form agreeing to receive text updates.">{{ old('opt_in_method', $registration->opt_in_method ?? '') }}</textarea>
                    @error('opt_in_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="sample_message_1">Sample text message 1</label>
                    <textarea class="form-control @error('sample_message_1') is-invalid @enderror" id="sample_message_1" name="sample_message_1" rows="2" maxlength="500" required
                              placeholder="Include your business name and how to opt out, e.g. &quot;Reply STOP to unsubscribe.&quot;">{{ old('sample_message_1', $registration->sample_message_1 ?? '') }}</textarea>
                    @error('sample_message_1')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-1">
                    <label class="form-label required" for="sample_message_2">Sample text message 2</label>
                    <textarea class="form-control @error('sample_message_2') is-invalid @enderror" id="sample_message_2" name="sample_message_2" rows="2" maxlength="500" required>{{ old('sample_message_2', $registration->sample_message_2 ?? '') }}</textarea>
                    @error('sample_message_2')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="row">
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="privacy_policy_url">Privacy policy URL</label>
                        <input type="url" class="form-control @error('privacy_policy_url') is-invalid @enderror" id="privacy_policy_url" name="privacy_policy_url"
                               value="{{ old('privacy_policy_url', $registration->privacy_policy_url ?? '') }}" maxlength="255" placeholder="https://" required>
                        @error('privacy_policy_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-1">
                        <label class="form-label required" for="terms_url">Terms of service URL</label>
                        <input type="url" class="form-control @error('terms_url') is-invalid @enderror" id="terms_url" name="terms_url"
                               value="{{ old('terms_url', $registration->terms_url ?? '') }}" maxlength="255" placeholder="https://" required>
                        @error('terms_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <x-button type="submit" variant="primary">Save details</x-button>
            </form>
        </x-card>

        @if(($registration->legal_business_name ?? null) !== null)
            <x-card :padded="true" class="mt-2">
                <p class="text-caption text-muted mb-2">Once your details look right, submit them for messaging registration.</p>
                <form method="post" action="{{ route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <x-button type="submit" variant="primary" :disabled="! $available">Submit for review</x-button>
                </form>
                @unless($available)
                    <p class="text-caption text-muted mt-1 mb-0">Preview — submission isn't available in this environment yet.</p>
                @endunless
            </x-card>
        @endif
    @endif
@endsection

@section('page-script')
    <script>
        $(document).ready(function () {
            function syncEinField() {
                $('#ein-field').prop('hidden', !$('#entity_ein').is(':checked'));
            }
            $('input[name="entity_type"]').on('change', syncEinField);
            syncEinField();
        });
    </script>
@endsection
