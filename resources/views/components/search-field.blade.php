@props([
    'id',
    'label',
    'name' => null,
    'value' => null,
    'placeholder' => null,
    // 'search' by default. A caller whose script listens only for keyup passes
    // 'text': the browser's built-in clear button on a search input fires
    // `input`, not `keyup`, and would leave a filtered list filtered.
    'type' => 'search',
])

{{--
    One search field: one input, one border, one focus ring, and a search icon
    drawn inside it.

    WHY NOT AN INPUT GROUP. The previous inbox treatment put the icon in its own
    `.input-group-text` beside the input. That span is a separate bordered box —
    the theme's merge rule removes the INPUT's left border but never the icon
    box's right one, so the icon sat in a visible square — and on focus the
    design system's ring outlined the input alone, leaving the icon box's border
    beside it: two outlines, one around half the control.

    Here the icon is positioned over a single `<input>` and ignores the pointer,
    so there is exactly one bordered element. Its focus treatment is the design
    system's own `.form-control:focus-visible` ring, drawn around the whole
    control. No stylesheet is involved beyond utilities that already ship.
--}}
<div {{ $attributes->merge(['class' => 'position-relative']) }} data-role="search-field">
    <label for="{{ $id }}" class="visually-hidden">{{ $label }}</label>
    <x-ds-icon name="search" size="16" class="position-absolute top-50 translate-middle-y text-muted pe-none" style="left: 0.875rem;" aria-hidden="true" />
    <input type="{{ $type }}"
           id="{{ $id }}"
           @if($name) name="{{ $name }}" @endif
           class="form-control rounded-pill"
           style="padding-left: 2.5rem;"
           value="{{ $value }}"
           placeholder="{{ $placeholder ?? $label }}"
           autocomplete="off">
</div>
