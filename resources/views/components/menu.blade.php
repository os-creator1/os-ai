@props([
    'label' => null,
    'icon' => null,
    'align' => 'end', // start | end
])

{{--
    Design System Contract, Milestone 1, §9 item 21 — the centralized
    dropdown-menu component, built on Bootstrap's own dropdown JS/markup
    (no new runtime dependency) so it inherits the same open/close
    behavior every existing dropdown already relies on; only the visual
    layer (radius, shadow, spacing, motion) is new.
--}}
<div class="dropdown ds-menu">
    <button
        class="btn btn-outline-secondary d-inline-flex align-items-center gap-1 transition-fast"
        type="button"
        data-bs-toggle="dropdown"
        aria-expanded="false"
    >
        @if ($icon)
            <x-ds-icon :name="$icon" size="16" />
        @endif
        {{ $label }}
    </button>
    <ul class="dropdown-menu dropdown-menu-{{ $align }} ds-menu-list transition-slow" role="menu">
        {{ $slot }}
    </ul>
</div>
