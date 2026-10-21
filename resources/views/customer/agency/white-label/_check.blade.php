<li class="d-flex gap-1 mb-1" data-check="{{ $item['key'] }}" data-status="{{ $item['status'] }}">
    <span aria-hidden="true">{{ $item['status'] === 'pass' ? '✓' : ($item['status'] === 'warn' ? '!' : '✕') }}</span>
    <span><strong>{{ $item['label'] }}</strong>
        <span class="sr-only">{{ $item['status'] }}</span><br><span class="text-caption {{ $item['status'] === 'fail' ? 'text-danger' : '' }}">{{ $item['detail'] }}</span></span>
</li>
