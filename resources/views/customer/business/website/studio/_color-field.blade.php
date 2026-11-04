{{-- One colour setting: native picker + HEX box + reset. Blank saves as "use the template's colour". Expects $name, $label, $value, $fallback; optional $hint. --}}
@php($slug = str_replace('_', '-', $name))
<div class="website-look-field">
    <label class="website-look-label" for="look-{{ $slug }}">{{ $label }}</label>
    @if (! empty($hint)) <p class="text-caption mb-50">{{ $hint }}</p> @endif
    <div class="website-look-color">
        <input type="color" class="website-look-swatch" id="look-{{ $slug }}-picker" data-color-picker="look-{{ $slug }}" value="{{ $value ?: $fallback }}" title="Pick a colour" aria-label="Pick {{ strtolower($label) }}">
        <input type="text" class="form-control" id="look-{{ $slug }}" name="{{ $name }}" value="{{ old($name, $value) }}" placeholder="Template default" maxlength="20" inputmode="text" autocomplete="off">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-color-reset="look-{{ $slug }}" aria-label="Reset {{ strtolower($label) }} to template default">Reset</button>
    </div>
    @error($name) <div class="text-danger small mt-50">{{ $message }}</div> @enderror
</div>
