@props([
    'variant' => 'primary', // primary | secondary | outline | ghost | danger
    'size' => 'md', // sm | md | lg
    'type' => 'button',
    'href' => null,
    'icon' => null,
    'disabled' => false,
])

@php
    $variantClass = match ($variant) {
        'primary' => 'btn-primary',
        'secondary' => 'btn-outline-secondary',
        'outline' => 'btn-outline-primary',
        'ghost' => 'btn-flat-secondary',
        'danger' => 'btn-danger',
        default => 'btn-primary',
    };
    $sizeClass = match ($size) {
        'sm' => 'btn-sm',
        'lg' => 'btn-lg',
        default => '',
    };
    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    {{ $attributes->merge(['class' => "btn {$variantClass} {$sizeClass} d-inline-flex align-items-center gap-1 transition-fast"]) }}
    @if ($disabled) disabled @endif
>
    @if ($icon)
        <x-ds-icon :name="$icon" size="16" />
    @endif
    {{ $slot }}
</{{ $tag }}>
