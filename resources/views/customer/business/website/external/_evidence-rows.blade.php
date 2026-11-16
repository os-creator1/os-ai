{{-- The affected pages of one issue (or one cluster of it), each with exactly what was observed. $rows, $detailRoute --}}
@php use App\Library\ExternalSite\ExternalAuditPresenter as P; @endphp
<div class="xw-evid" data-role="affected-pages">
    <div class="xw-evid-head"><span>Page</span><span>Type</span><span>What we saw</span><span></span></div>
    @foreach($rows as $row)
        <div class="xw-evid-row" data-role="affected" data-page="{{ $row['page_id'] }}">
            <span class="xw-minw">
                @if($row['page_id'])
                    <a class="xw-code" href="{{ $detailRoute($row['page_id']) }}" data-role="page-link">{{ $row['path'] }}</a>
                @else
                    <span style="font-weight:600">Whole website</span>
                @endif
                @if($row['status'] === 'new')<span class="xw-tg xw-t-new">New</span>@endif
                @if($row['priority'] !== 'info')<span class="xw-tg {{ P::toneClass(P::priorityVariant($row['priority'])) }}" style="text-transform:capitalize">{{ $row['priority'] }}</span>@endif
            </span>
            <span class="xw-mut">{{ P::importanceLabel($row['importance']) }}</span>
            <span data-role="observed">{{ $row['observed'] }}</span>
            @if($row['page_id'])
                <a class="xw-lk xw-lk-sm" href="{{ $row['page_url'] }}" target="_blank" rel="noopener noreferrer" data-role="open-page">View page<svg class="xi" viewBox="0 0 24 24" style="width:12px;height:12px"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg></a>
            @else
                <span></span>
            @endif

            @php $headings = (array) ($row['values']['h1_texts'] ?? []); @endphp
            @if($headings !== [] || $row['related'] !== [] || ! empty($row['proposal']))
                <div class="xw-evid-extra">
                    @if($headings !== [])
                        <span>Headings: {{ implode(' | ', array_map(fn ($t) => '“'.$t.'”', $headings)) }}</span>
                    @endif

                    @if($row['related'] !== [])
                        <ul data-role="related" style="margin:0;padding-left:18px">
                            @foreach($row['related'] as $rel)
                                <li>
                                    {{ $rel['note'] ?? 'related' }}:
                                    @if(preg_match('#^https?://#i', (string) $rel['url']))<a class="xw-lk xw-lk-sm" href="{{ $rel['url'] }}" target="_blank" rel="noopener noreferrer">{{ $rel['url'] }}</a>@else {{ $rel['url'] }} @endif
                                    @if(! empty($rel['status']))<span>&middot; HTTP {{ $rel['status'] }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if(! empty($row['proposal']))
                        <span data-role="row-proposal"><em>Suggested:</em> {{ $row['proposal'] }}</span>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
