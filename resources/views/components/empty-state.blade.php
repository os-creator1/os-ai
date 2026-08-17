@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'ds-empty-state text-center py-5']) }}>
    <div class="ds-empty-state-icon mb-3 d-inline-flex align-items-center justify-content-center">
        <x-ds-icon :name="$icon" size="28" />
    </div>
    <p class="text-section-heading mb-1">{{ $title }}</p>
    @if ($description)
        <p class="text-caption mb-3">{{ $description }}</p>
    @endif
    @isset($action)
        <div>{{ $action }}</div>
    @endisset
</div>
