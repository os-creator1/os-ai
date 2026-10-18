{{-- A copy-friendly DNS record table. Expects $records: list of [label, value, copyable]. --}}
<div class="mg-dns-table" role="table">
    @foreach ($records as [$recordLabel, $recordValue, $copyable])
        <div class="mg-dns-row" role="row">
            <span class="mg-dns-key" role="cell">{{ $recordLabel }}</span>
            <span class="mg-mono" role="cell">{{ $recordValue }}</span>
            @if ($copyable)
                <button type="button" class="mg-copy" data-copy="{{ $recordValue }}" data-copy-label="{{ strtolower($recordLabel) }}" aria-label="Copy {{ strtolower($recordLabel) }}"><x-ds-icon name="copy" size="16" /></button>
            @else
                <span></span>
            @endif
        </div>
    @endforeach
</div>
