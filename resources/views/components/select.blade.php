@props([
    'label' => null,
    'name',
    'options' => [],
    'selected' => null,
    'help' => null,
])

<div class="ds-field mb-3">
    @if ($label)
        <label for="{{ $name }}" class="form-label text-label">{{ $label }}</label>
    @endif
    <select
        name="{{ $name }}"
        id="{{ $name }}"
        {{ $attributes->merge(['class' => 'form-select transition-fast']) }}
    >
        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $text }}</option>
        @endforeach
    </select>
    @if ($help)
        <div class="form-text text-caption">{{ $help }}</div>
    @endif
</div>
