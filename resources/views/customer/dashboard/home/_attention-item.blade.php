<li class="home-attn-item" data-role="attention-item" data-attention="{{ $item['key'] }}" data-kind="{{ $item['kind'] }}">
    <div class="d-flex align-items-center gap-50 mb-25">
        <span class="home-kind" data-kind="{{ $item['kind'] }}" data-role="attention-kind">{{ $words[$item['kind']] }}</span>
    </div>
    <p class="mb-50" data-role="attention-text">{{ $item['text'] }}</p>
    @if(($item['action']['url'] ?? null) !== null)
        @if($item['action']['drawerUrl'] !== null)
            <a href="{{ $item['action']['url'] }}" class="btn btn-sm btn-outline-primary" data-role="attention-action"
               data-home-drawer="{{ $item['action']['drawerUrl'] }}" data-drawer-title="{{ $item['action']['label'] }}">{{ $item['action']['label'] }}</a>
        @else
            <a href="{{ $item['action']['url'] }}" class="btn btn-sm btn-outline-primary" data-role="attention-action">{{ $item['action']['label'] }}</a>
        @endif
    @endif
</li>
