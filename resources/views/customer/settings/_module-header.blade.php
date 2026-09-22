<div class="row mb-2" data-role="settings-module-header">
    <div class="col-12">
        <a href="{{ $backUrl }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-1">
            <x-ds-icon name="arrow-left" size="16" aria-hidden="true" />
            {{ $backLabel ?? 'Back to Settings' }}
        </a>
        <h4 class="mb-0">{{ $title }}</h4>
        @isset($description)
            <p class="text-caption mb-0">{{ $description }}</p>
        @endisset
    </div>
</div>
