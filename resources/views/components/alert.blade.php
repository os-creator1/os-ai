@props([
    'variant' => 'neutral', // neutral | accent | success | warning | danger
    'icon' => null,
    'dismissible' => false,
])

@php
    $variantClass = match ($variant) {
        'accent' => 'alert-primary',
        'success' => 'alert-success',
        'warning' => 'alert-warning',
        'danger' => 'alert-danger',
        default => 'alert-secondary',
    };
@endphp

<div {{ $attributes->merge(['class' => "alert {$variantClass} ds-alert d-flex align-items-start gap-2" . ($dismissible ? ' alert-dismissible fade show' : '')]) }} role="alert">
    @if ($icon)
        <x-ds-icon :name="$icon" size="18" class="flex-shrink-0 mt-1" />
    @endif
    <div class="flex-grow-1">{{ $slot }}</div>
    @if ($dismissible)
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    @endif
</div>
