@php
    // Public Marketing Homepage contract. This is the real, shipped Forms
    // preset field list for a Photo Booth business
    // (App\Library\Website\WebsiteFormPresets::photoBoothQuoteRequest()) —
    // not an invented mock-up — rendered here as example data, clearly
    // labelled, rather than a live customer's actual submissions.
    //
    // Correction: a submission is saved to Forms and creates a Contact
    // (plus a CRM Opportunity when a pipeline exists) — it never appears
    // in Conversations (the separate SMS/chat inbox), and nothing here
    // claims booking/proposal happen "in the same screen".
    $fields = \App\Library\Website\WebsiteFormPresets::photoBoothQuoteRequest();
@endphp
<div class="marketing-example">
    <div class="marketing-example__copy">
        <p>{{ __('A photo booth company publishes a site with their packages and a quote-request form. When a couple submits it, it is saved to Forms and a Contact appears in the CRM.') }}</p>
        <ul class="marketing-example__steps">
            <li>{{ __('The quote request form asks exactly what a photo booth business needs to quote a job — no more.') }}</li>
            <li>{{ __('The owner follows up with the couple, checks the date on Calendar, and confirms the booking.') }}</li>
            <li>{{ __('Once confirmed, a proposal goes out and payment is collected against it.') }}</li>
        </ul>
        <p class="marketing-auth__note">{{ __('Photo Booth is one of several local-service niches the platform supports today.') }}</p>
    </div>
    <div>
        <div class="marketing-mock" aria-hidden="true">
            <div class="marketing-mock__bar">
                <span class="marketing-mock__dot"></span>
                <span class="marketing-mock__dot"></span>
                <span class="marketing-mock__dot"></span>
                <span class="marketing-mock__title">{{ __('Request a Quote — Photo Booth') }}</span>
            </div>
            <div class="marketing-mock__body">
                @foreach ($fields as $field)
                    <div class="marketing-mock__field">
                        <label>{{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}</label>
                        <div class="marketing-mock__input {{ $field['type'] === 'textarea' ? 'marketing-mock__input--tall' : '' }}"></div>
                    </div>
                @endforeach
            </div>
        </div>
        <p class="marketing-mock__caption">{{ __('Example data shown — the actual quote-request form fields for a Photo Booth business.') }}</p>
    </div>
</div>
