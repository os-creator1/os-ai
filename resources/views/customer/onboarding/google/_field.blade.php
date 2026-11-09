{{--
    One inline-editable row of the listing card.

    $f        field array from GoogleOnboardingPreview ({key,value,status,imported,saved,offer,essential})
    $label    visible label
    $type     input type (default text)
    $help     optional helper text
    $as       'input' (default) | 'textarea'
    $max      maxlength
--}}
@php
    $type = $type ?? 'text';
    $as = $as ?? 'input';
    $max = $max ?? 255;
    $id = 'gbp-' . $f['key'];
    $statusLabels = \App\Library\Business\GoogleOnboardingPreview::STATUS_LABELS;
@endphp
<div class="gbp-row" data-field="{{ $f['key'] }}" data-modes="{{ $visibleIn ?? '' }}">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-50 mb-25">
        <label class="gbp-label" for="{{ $id }}">
            {{ $label }}@if ($f['essential']) <span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
        <span class="gbp-badge gbp-badge--{{ $f['status'] }}" data-role="status" data-imported="{{ $f['imported'] }}" data-saved="{{ $f['saved'] }}">{{ $statusLabels[$f['status']] }}@if ($f['status'] === 'missing' && ! $f['essential']) (optional)@endif</span>
    </div>

    @if ($as === 'textarea')
        <textarea class="gbp-inline" id="{{ $id }}" name="{{ $f['key'] }}" rows="2" maxlength="{{ $max }}" placeholder="{{ $placeholder ?? '' }}">{{ $f['value'] }}</textarea>
    @else
        <input class="gbp-inline" id="{{ $id }}" name="{{ $f['key'] }}" type="{{ $type }}" value="{{ $f['value'] }}" maxlength="{{ $max }}" placeholder="{{ $placeholder ?? 'Add ' . strtolower($label) }}" @if ($f['essential']) aria-required="true" @endif>
    @endif

    @if (! empty($help))<div class="gbp-help">{{ $help }}</div>@endif
    @if ($f['offer'] !== null)
        <div class="gbp-help">
            Google has “{{ $f['offer'] }}”.
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-use-google="{{ $f['offer'] }}" data-target="{{ $id }}" hidden>Use Google’s</button>
        </div>
    @endif
    @error($f['key'])<div class="gbp-error">{{ $message }}</div>@enderror
</div>
