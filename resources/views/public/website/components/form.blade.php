{{-- Website Component Library — form (Photo Booth quote-request slice). Plain escaped text only. --}}
@php($websiteForm = !empty($data['form_uid']) ? \App\Models\WebsiteForm::where('website_id', $website->id)->where('uid', $data['form_uid'])->first() : null)
@if ($websiteForm)
    <section class="website-section website-form">
        @if (! empty($data['heading']))
            <h2>{{ $data['heading'] }}</h2>
        @endif

        @if ($isPreview ?? false)
            <p class="website-form-preview-note">Preview — this form does not accept submissions here.</p>
            <div class="website-form-fields" aria-hidden="true">
                @foreach ($websiteForm->fields as $field)
                    <div class="website-form-field">
                        <label>{{ $field['label'] }}{{ ($field['required'] ?? false) ? ' *' : '' }}</label>
                        @if (($field['type'] ?? 'text') === 'textarea')
                            <textarea disabled rows="4"></textarea>
                        @else
                            <input type="{{ $field['type'] ?? 'text' }}" disabled>
                        @endif
                    </div>
                @endforeach
                <button type="button" disabled>{{ $websiteForm->submit_label }}</button>
            </div>
        @else
            <form method="POST" action="{{ route('public.website.form.submit', [$website->public_id, $websiteForm->uid]) }}" class="website-form-fields">
                @csrf
                <input type="hidden" name="page_slug" value="{{ $pageSlug ?? '' }}">
                <input type="text" name="{{ \App\Library\Website\WebsiteFormSubmissionService::HONEYPOT_FIELD }}" value="" tabindex="-1" autocomplete="off" class="website-form-honeypot" aria-hidden="true">
                @foreach ($websiteForm->fields as $field)
                    <div class="website-form-field">
                        <label for="website-form-{{ $field['key'] }}">{{ $field['label'] }}{{ ($field['required'] ?? false) ? ' *' : '' }}</label>
                        @if (($field['type'] ?? 'text') === 'textarea')
                            <textarea id="website-form-{{ $field['key'] }}" name="{{ $field['key'] }}" rows="4" @if($field['required'] ?? false) required @endif>{{ old($field['key']) }}</textarea>
                        @else
                            <input type="{{ $field['type'] ?? 'text' }}" id="website-form-{{ $field['key'] }}" name="{{ $field['key'] }}" value="{{ old($field['key']) }}" @if($field['required'] ?? false) required @endif>
                        @endif
                    </div>
                @endforeach
                <button type="submit">{{ $websiteForm->submit_label }}</button>
            </form>
        @endif
    </section>
@endif
