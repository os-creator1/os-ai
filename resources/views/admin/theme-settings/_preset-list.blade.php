{{--
    Design System M2 Slice 1 contract §9 item 34. Every preset's name +
    Draft/Saved/Active/Factory status badge, and the create/duplicate/
    rename/activate/delete entry points (§4.6).
--}}
<x-card title="Presets" :padded="false">
    <x-slot:actions>
        <button type="button" class="btn btn-sm btn-primary" id="theme-preset-create-btn" data-bs-toggle="modal" data-bs-target="#theme-preset-create-modal">
            <x-ds-icon name="plus" size="14" /> New
        </button>
    </x-slot:actions>

    <div class="list-group list-group-flush" id="theme-preset-list">
        @foreach ($presets as $preset)
            <button
                type="button"
                class="list-group-item list-group-item-action theme-preset-list-item d-flex justify-content-between align-items-center"
                data-preset-uid="{{ $preset->uid }}"
            >
                <span class="text-truncate">{{ $preset->name }}</span>
                <span class="d-flex gap-1">
                    @if ($preset->is_factory)
                        <x-badge variant="accent">Factory</x-badge>
                    @endif
                    @if ($preset->status === \App\Models\PlatformThemePreset::STATUS_ACTIVE)
                        <x-badge variant="success">Active</x-badge>
                    @elseif ($preset->status === \App\Models\PlatformThemePreset::STATUS_SAVED)
                        <x-badge variant="neutral">Saved</x-badge>
                    @else
                        <x-badge variant="warning">Draft</x-badge>
                    @endif
                </span>
            </button>
        @endforeach
    </div>
</x-card>

<div class="modal fade" id="theme-preset-create-modal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.theme-presets.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Create preset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <x-input name="name" label="Preset name" required placeholder="e.g. Ocean Blue" />
                <p class="text-caption text-muted mb-0">Starts from the Factory preset's current colors and font.</p>
            </div>
            <div class="modal-footer">
                <x-button type="submit" variant="primary">Create</x-button>
            </div>
        </form>
    </div>
</div>
