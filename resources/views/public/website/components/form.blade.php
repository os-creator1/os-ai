{{-- Website Component Library — form (Photo Booth quote-request slice).
     Plain escaped text only. $formsByUid is embedded in the immutable
     published snapshot (or, in preview, read live) — never a per-render
     database query — so a published page's fields stay exactly as they
     were at publish time until the next publish. --}}
@php($websiteForm = !empty($data['form_uid']) ? ($formsByUid[$data['form_uid']] ?? null) : null)
@if ($websiteForm)
    <section class="website-section website-form">
        @if (! empty($data['heading']))
            <h2>{{ $data['heading'] }}</h2>
        @endif

        @if ($isPreview ?? false)
            <p class="website-form-preview-note">Preview — this form does not accept submissions here.</p>
            <div class="website-form-fields" aria-hidden="true">
                @foreach ($websiteForm['fields'] as $field)
                    <div class="website-form-field">
                        <label>{{ $field['label'] }}{{ ($field['required'] ?? false) ? ' *' : '' }}</label>
                        @if (($field['type'] ?? 'text') === 'textarea')
                            <textarea disabled rows="4"></textarea>
                        @else
                            <input type="{{ $field['type'] ?? 'text' }}" disabled>
                        @endif
                    </div>
                @endforeach
                <button type="button" disabled>{{ $websiteForm['submit_label'] }}</button>
            </div>
        @else
            {{-- The page uid is part of the action URL itself, not a
                 posted field: the submit controller looks it up in the
                 published snapshot to verify and record the real source
                 page, since a posted value would be visitor-controlled
                 and unverifiable. The same form can appear on more than
                 one page, so the form_uid alone can't say which. --}}
            <form method="POST" action="{{ route('public.website.form.submit', [$website->public_id, $websiteForm['uid'], $pageUid]) }}" class="website-form-fields">
                @csrf
                <input type="text" name="{{ \App\Library\Website\WebsiteFormSubmissionService::HONEYPOT_FIELD }}" value="" tabindex="-1" autocomplete="off" class="website-form-honeypot" aria-hidden="true">
                @foreach ($websiteForm['fields'] as $field)
                    <div class="website-form-field">
                        <label for="website-form-{{ $field['key'] }}">{{ $field['label'] }}{{ ($field['required'] ?? false) ? ' *' : '' }}</label>
                        @if (($field['type'] ?? 'text') === 'textarea')
                            <textarea id="website-form-{{ $field['key'] }}" name="{{ $field['key'] }}" rows="4" @if($field['required'] ?? false) required @endif>{{ old($field['key']) }}</textarea>
                        @else
                            <input type="{{ $field['type'] ?? 'text' }}" id="website-form-{{ $field['key'] }}" name="{{ $field['key'] }}" value="{{ old($field['key']) }}" @if($field['required'] ?? false) required @endif>
                        @endif
                    </div>
                @endforeach
                <button type="submit">{{ $websiteForm['submit_label'] }}</button>
            </form>
        @endif
    </section>
@endif
