{{--
    One NAP field cell of a listing row: what the user recorded for this
    directory, with the read-time comparison against the business profile.

    Inputs: $label, $field (name|phone|address), $row (SeoCitationRow),
            $value (the recorded listing value or null).

    Missing data is never a mismatch: no recorded value renders "Not checked"
    (or "Not compared" when the business profile itself has no value to compare
    against). Only two recorded values that differ render the "Differs" state.
--}}
@php
    use App\Enums\Seo\SeoNapFieldResult;

    $result = $row->nap[$field];
    $tone = match ($result) {
        SeoNapFieldResult::Consistent => 'ok',
        SeoNapFieldResult::Mismatch => 'diff',
        default => 'none',
    };
    $icon = match ($result) {
        SeoNapFieldResult::Consistent => 'circle-check',
        SeoNapFieldResult::Mismatch => 'triangle-alert',
        default => 'circle-dashed',
    };
@endphp
<div class="cz-field cz-field--{{ $tone }}" data-field="{{ $field }}" data-result="{{ $result->value }}">
    <span class="cz-label">{{ $label }}</span>
    <x-ds-icon :name="$icon" size="14" />
    <span class="cz-field-value">
        @if($value !== null)
            {{ $value }}
            @if($result === SeoNapFieldResult::Mismatch)
                <span class="d-block text-caption">Differs from your business profile</span>
            @endif
        @else
            {{ $result === SeoNapFieldResult::NotComparable ? 'Not compared' : 'Not checked' }}
        @endif
    </span>
</div>
