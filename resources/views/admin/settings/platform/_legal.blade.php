@php
    $legalCompany = app(\App\Library\Legal\LegalCompany::class)->current();
    $legalFields = [
        'brand_name' => 'Trading / Brand Name',
        'legal_entity' => 'Registered Legal Entity',
        'company_number' => 'Company Registration Number',
        'registered_address' => 'Registered Office Address',
        'country' => 'Country',
        'legal_email' => 'Legal Contact Email',
    ];
@endphp

<h5 class="mb-1">Legal Company Information</h5>
<p class="text-muted mb-3">
    The registered company behind the MotionGrove brand. These details appear on every public legal page
    (Terms, Privacy, Billing, and the other legal documents) as soon as you save. They describe the platform
    operator only and are separate from any customer, location or Agency address. Documents already accepted
    keep the text they were accepted with.
</p>

<form class="form form-vertical" action="{{ route('admin.platform-owner.legal-company.save') }}" method="post">
    @csrf
    <input type="hidden" name="tab" value="legal">
    <input type="hidden" name="base_revision" value="{{ old('base_revision', $legalCompany['revision']) }}">

    <div class="row">
        <div class="col-md-8">
            @foreach ($legalFields as $field => $label)
                <div class="ds-field mb-3">
                    <label for="legal_{{ $field }}" class="form-label text-label required">{{ $label }}</label>
                    @if ($field === 'registered_address')
                        <textarea id="legal_{{ $field }}" name="{{ $field }}" rows="3" maxlength="{{ \App\Library\Legal\LegalCompany::MAX[$field] }}" class="form-control transition-fast" required>{{ old($field, $legalCompany[$field]) }}</textarea>
                        <div class="text-caption text-muted mt-1">Changing this updates the registered office shown on all current legal pages.</div>
                    @else
                        <input id="legal_{{ $field }}" type="{{ $field === 'legal_email' ? 'email' : 'text' }}" name="{{ $field }}" value="{{ old($field, $legalCompany[$field]) }}" maxlength="{{ \App\Library\Legal\LegalCompany::MAX[$field] }}" class="form-control transition-fast" required>
                    @endif
                    @error($field)<div class="invalid-feedback d-block text-caption">{{ $message }}</div>@enderror
                </div>
            @endforeach
            @error('base_revision')<div class="invalid-feedback d-block text-caption mb-2">{{ $message }}</div>@enderror
        </div>
    </div>

    <button type="submit" class="btn btn-primary">{{ __('locale.buttons.save') }}</button>
</form>
