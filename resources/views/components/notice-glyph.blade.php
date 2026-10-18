@props([
    'variant' => 'info', // success | danger | warning | info | close
])

{{-- One hand-drawn 24x24 glyph per status, stroked in currentColor, so the artwork is centred in its viewBox by construction. --}}
@php
    $paths = match ($variant) {
        'success' => 'M5.5 12.5l4.25 4.25L18.5 8',
        'danger', 'close' => 'M7 7l10 10M17 7L7 17',
        'warning' => 'M12 6.5v7M12 17.5h.01',
        default => 'M12 11v6M12 7.5h.01',
    };
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="{{ $variant === 'close' ? 2 : 2.75 }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $paths }}"/></svg>
