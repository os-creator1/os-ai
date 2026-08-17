@props([
    'tabs' => [], // ['key' => 'label']
    'active' => null,
    'id' => 'ds-tabs-' . uniqid(),
])

@php($active = $active ?? array_key_first($tabs))

<div {{ $attributes }}>
    <ul class="nav nav-tabs ds-tabs" role="tablist">
        @foreach ($tabs as $key => $label)
            <li class="nav-item" role="presentation">
                <button
                    class="nav-link transition-fast @if ($key === $active) active @endif"
                    id="{{ $id }}-{{ $key }}-tab"
                    data-bs-toggle="tab"
                    data-bs-target="#{{ $id }}-{{ $key }}"
                    type="button"
                    role="tab"
                    aria-controls="{{ $id }}-{{ $key }}"
                    aria-selected="{{ $key === $active ? 'true' : 'false' }}"
                >
                    {{ $label }}
                </button>
            </li>
        @endforeach
    </ul>
    <div class="tab-content pt-3">
        {{ $slot }}
    </div>
</div>
