{{-- One "What to do next" item. Inputs: $item, $canManage, $gbpUrl. --}}
@php $itemRow = $item['row']; @endphp
<li data-role="action-item" data-action="{{ $itemRow ? $itemRow->directory->key : 'google' }}" data-priority="{{ $item['priority'] }}" data-kind="{{ $item['kind'] }}">
    <span class="cz-dir-icon"><x-ds-icon :name="$itemRow ? ($itemRow->directory->icon ?: 'map') : 'map-pin'" size="18" /></span>
    <div class="cz-actions-body">
        <div class="cz-actions-title">{{ $item['name'] }}
            @if($itemRow === null || (! $itemRow->isCustom() && $itemRow->importance()->value === 'essential'))<x-badge variant="accent">Essential</x-badge>@endif
        </div>
        <div class="cz-actions-copy">{{ $item['message'] }}</div>
    </div>
    @if($itemRow === null)
        <x-button :href="$gbpUrl" variant="outline" size="sm">{{ $item['action'] }}</x-button>
    @elseif($canManage && $itemRow->writable)
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#citation-drawer-{{ $itemRow->directory->key }}">{{ $item['action'] }}</button>
    @endif
</li>
