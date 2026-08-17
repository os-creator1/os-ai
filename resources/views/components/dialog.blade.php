@props([
    'id',
    'title' => null,
    'size' => 'md', // sm | md | lg
])

@php
    $sizeClass = match ($size) {
        'sm' => 'modal-sm',
        'lg' => 'modal-lg',
        default => '',
    };
@endphp

{{--
    Design System Contract, Milestone 1, §9 item 22 — the centralized
    dialog (modal) component, built on Bootstrap's own modal JS/markup
    (fade transition already respects the token-driven --duration-slow/
    --ease-standard via the modal's own retokenized CSS, §9 item 9).
--}}
<div class="modal fade ds-dialog" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered {{ $sizeClass }}">
        <div {{ $attributes->merge(['class' => 'modal-content']) }}>
            @if ($title)
                <div class="modal-header">
                    <h5 class="modal-title text-section-heading" id="{{ $id }}-label">{{ $title }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            @endif
            <div class="modal-body">
                {{ $slot }}
            </div>
            @isset($footer)
                <div class="modal-footer">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
