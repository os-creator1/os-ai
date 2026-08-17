@props([
    'variant' => 'neutral', // neutral | accent | success | warning | danger
])

@php
    $variantClass = match ($variant) {
        'accent' => 'bg-light-primary text-primary',
        'success' => 'bg-light-success text-success',
        'warning' => 'bg-light-warning text-warning',
        'danger' => 'bg-light-danger text-danger',
        default => 'bg-light-secondary text-secondary',
    };
@endphp

<span {{ $attributes->merge(['class' => "badge rounded-pill {$variantClass} text-caption fw-medium"]) }}>
    {{ $slot }}
</span>
