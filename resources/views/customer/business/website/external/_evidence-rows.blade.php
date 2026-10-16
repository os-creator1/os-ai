{{-- The affected pages of one issue (or one cluster of it), each with exactly what was observed. $rows, $detailRoute --}}
<ul class="list-unstyled mb-0" data-role="affected-pages">
    @foreach($rows as $row)
        <li class="mb-1 pb-1 border-bottom" data-role="affected" data-page="{{ $row['page_id'] }}">
            <div class="d-flex flex-wrap align-items-center gap-1">
                @if($row['page_id'])
                    <a href="{{ $detailRoute($row['page_id']) }}" data-role="page-link">{{ $row['path'] }}</a>
                    <a class="text-caption" href="{{ $row['page_url'] }}" target="_blank" rel="noopener noreferrer" data-role="open-page">View page</a>
                @else
                    <span class="fw-semibold">Whole website</span>
                @endif
                @if($row['status'] === 'new')<x-badge variant="accent">New</x-badge>@endif
                @if($row['priority'] !== 'info')<x-badge :variant="\App\Library\ExternalSite\ExternalAuditPresenter::priorityVariant($row['priority'])" class="text-capitalize">{{ $row['priority'] }}</x-badge>@endif
                <span class="text-caption text-muted">{{ \App\Library\ExternalSite\ExternalAuditPresenter::importanceLabel($row['importance']) }}</span>
            </div>
            <p class="mb-50 mt-50" data-role="observed">{{ $row['observed'] }}</p>

            @if(($row['values']['h1_texts'] ?? []) !== [])
                <p class="text-caption text-muted mb-50">Headings: {{ implode(' | ', array_map(fn ($t) => '“'.$t.'”', (array) $row['values']['h1_texts'])) }}</p>
            @endif

            @if($row['related'] !== [])
                <ul class="mb-50 text-caption" data-role="related">
                    @foreach($row['related'] as $rel)
                        <li>
                            {{ $rel['note'] ?? 'related' }}:
                            @if(preg_match('#^https?://#i', (string) $rel['url']))<a href="{{ $rel['url'] }}" target="_blank" rel="noopener noreferrer">{{ $rel['url'] }}</a>@else {{ $rel['url'] }} @endif
                            @if(! empty($rel['status']))<span class="text-muted">&middot; HTTP {{ $rel['status'] }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if(! empty($row['proposal']))
                <p class="text-caption mb-0" data-role="row-proposal"><em>Suggested:</em> {{ $row['proposal'] }}</p>
            @endif
        </li>
    @endforeach
</ul>
