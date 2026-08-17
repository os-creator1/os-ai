@props([
    'title' => null,
    'padded' => true,
])

<div {{ $attributes->merge(['class' => 'card ds-card shadow-none transition-base']) }}>
    @if ($title)
        <div class="card-header">
            <h4 class="card-title text-section-heading">{{ $title }}</h4>
            @isset($actions)
                <div class="d-flex align-items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="{{ $padded ? 'card-body' : '' }}">
        {{ $slot }}
    </div>
    @isset($footer)
        <div class="card-footer">{{ $footer }}</div>
    @endisset
</div>
