@props([
    'label' => null,
    'name',
    'type' => 'text',
    'help' => null,
    'error' => null,
])

<div class="ds-field mb-3">
    @if ($label)
        <label for="{{ $name }}" class="form-label text-label">{{ $label }}</label>
    @endif
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        {{ $attributes->merge(['class' => 'form-control transition-fast' . ($error ? ' is-invalid' : '')]) }}
    />
    @if ($help && ! $error)
        <div class="form-text text-caption">{{ $help }}</div>
    @endif
    @if ($error)
        <div class="invalid-feedback d-block text-caption">{{ $error }}</div>
    @endif
</div>
