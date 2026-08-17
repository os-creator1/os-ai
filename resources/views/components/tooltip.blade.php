@props([
    'text',
    'placement' => 'top',
])

{{--
    Design System Contract, Milestone 1, §9 item 26 — the centralized
    tooltip component, built on Bootstrap's own existing tooltip JS
    (data-bs-toggle="tooltip", already initialized app-wide) so no new
    runtime dependency is introduced; only the visual layer is new.
--}}
<span
    {{ $attributes->merge([
        'class' => 'ds-tooltip-trigger',
        'data-bs-toggle' => 'tooltip',
        'data-bs-placement' => $placement,
        'title' => $text,
    ]) }}
>
    {{ $slot }}
</span>
