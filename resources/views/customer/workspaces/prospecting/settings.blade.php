@extends('layouts/contentLayoutMaster')

@section('title', 'Agent Setup')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Prospecting</h4>
        </div>
    </div>

    @include('customer.workspaces.prospecting._nav', ['prospectingActive' => 'settings'])

    <x-card title="Agent Setup" :padded="true">
        <p class="text-caption">Configure the context your AI sales agent will use once outreach and responses are enabled. Every field below is your own — nothing here is hardcoded to a specific agency or niche.</p>

        <form method="post" action="{{ route('customer.workspaces.prospecting.settings.update', $workspaceUid) }}">
            @csrf
            <div class="row">
                <div class="col-md-6 mb-1">
                    <label class="form-label" for="agency_name">Agency / company name</label>
                    <input type="text" id="agency_name" name="agency_name" class="form-control" value="{{ old('agency_name', $settings->agency_name) }}">
                </div>
                <div class="col-md-6 mb-1">
                    <label class="form-label" for="niche">Niche / ideal customer profile</label>
                    <input type="text" id="niche" name="niche" class="form-control" value="{{ old('niche', $settings->niche) }}">
                </div>
                <div class="col-md-6 mb-1">
                    <label class="form-label" for="tone">Tone</label>
                    <input type="text" id="tone" name="tone" class="form-control" value="{{ old('tone', $settings->tone) }}">
                </div>
                <div class="col-12 mb-1">
                    <label class="form-label" for="offer">Offer</label>
                    <textarea id="offer" name="offer" class="form-control" rows="2">{{ old('offer', $settings->offer) }}</textarea>
                </div>
                <div class="col-12 mb-1">
                    <label class="form-label" for="value_proposition">Value proposition</label>
                    <textarea id="value_proposition" name="value_proposition" class="form-control" rows="2">{{ old('value_proposition', $settings->value_proposition) }}</textarea>
                </div>
                <div class="col-12 mb-1">
                    <label class="form-label" for="pricing_context">Pricing context</label>
                    <textarea id="pricing_context" name="pricing_context" class="form-control" rows="2">{{ old('pricing_context', $settings->pricing_context) }}</textarea>
                </div>
                <div class="col-12 mb-1">
                    <label class="form-label" for="qualification_context">Qualification context</label>
                    <textarea id="qualification_context" name="qualification_context" class="form-control" rows="2">{{ old('qualification_context', $settings->qualification_context) }}</textarea>
                </div>
                <div class="col-12 mb-1">
                    <label class="form-label" for="geography_context">Geography / targeting context</label>
                    <textarea id="geography_context" name="geography_context" class="form-control" rows="2">{{ old('geography_context', $settings->geography_context) }}</textarea>
                </div>
                <div class="col-12 mb-1">
                    <label class="form-label" for="faqs_objections">FAQs / objections</label>
                    <textarea id="faqs_objections" name="faqs_objections" class="form-control" rows="3">{{ old('faqs_objections', $settings->faqs_objections) }}</textarea>
                </div>
                <div class="col-12 mb-1">
                    <label class="form-label" for="booking_context">Calendar / booking context</label>
                    <textarea id="booking_context" name="booking_context" class="form-control" rows="2">{{ old('booking_context', $settings->booking_context) }}</textarea>
                </div>
                <div class="col-12 mb-2">
                    <label class="form-label" for="follow_up_policy">Follow-up policy</label>
                    <textarea id="follow_up_policy" name="follow_up_policy" class="form-control" rows="2">{{ old('follow_up_policy', $settings->follow_up_policy) }}</textarea>
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label" for="booking_url">Booking URL</label>
                    <input type="text" id="booking_url" name="booking_url" class="form-control @error('booking_url') is-invalid @enderror"
                           value="{{ old('booking_url', $settings->booking_url) }}" placeholder="https://your-booking-link.example.com">
                    @error('booking_url')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <p class="text-caption mb-0">The only URL the AI responder is ever allowed to send.</p>
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label" for="follow_up_delay_hours">Follow-up delay (hours)</label>
                    <input type="number" id="follow_up_delay_hours" name="follow_up_delay_hours" class="form-control" min="1" max="168"
                           value="{{ old('follow_up_delay_hours', $settings->follow_up_delay_hours ?? 24) }}">
                </div>
            </div>

            <x-button type="submit" variant="primary">Save agent settings</x-button>
        </form>
    </x-card>
@endsection
