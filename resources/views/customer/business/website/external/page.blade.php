@extends('customer.business.website.external.layout')

@section('external-content')
    <p class="mb-1"><a href="{{ route('customer.workspaces.businesses.website.external.pages', [$workspaceUid, $businessUid]) }}">&larr; All pages</a></p>

    <x-card :padded="true" class="mb-2" data-role="page-detail">
        <h2 class="h4 mb-1">{{ $page->displayPath() }}</h2>
        <p class="mb-1">
            <a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer" data-role="open-page">Open page</a>
            &middot; {{ \App\Library\ExternalSite\ExternalWebsiteReader::pageState($page) }}
            &middot; checked {{ $page->fetched_at?->diffForHumans() }}
        </p>
        <dl class="row mb-0">
            <dt class="col-sm-4">Title</dt><dd class="col-sm-8" data-fact="title">{{ $page->title ?: 'None set' }}</dd>
            <dt class="col-sm-4">Search result description</dt><dd class="col-sm-8" data-fact="description">{{ $page->meta_description ?: 'None set' }}</dd>
            <dt class="col-sm-4">Main headings</dt><dd class="col-sm-8">{{ $page->h1_count ?? '—' }}</dd>
            <dt class="col-sm-4">Words on the page</dt><dd class="col-sm-8">{{ number_format($page->word_count) }}</dd>
            <dt class="col-sm-4">Images</dt><dd class="col-sm-8">{{ $page->image_count }}@if($page->images_missing_alt > 0) ({{ $page->images_missing_alt }} without a description)@endif</dd>
            <dt class="col-sm-4">Links to your own pages</dt><dd class="col-sm-8">{{ $page->internal_link_count }}@if($page->broken_link_count > 0) ({{ $page->broken_link_count }} broken)@endif</dd>
        </dl>
    </x-card>

    @forelse($findings as $finding)
        <x-card :padded="true" class="mb-2" data-role="issue" data-rule="{{ $finding->rule_key }}">
            <div class="d-flex align-items-center flex-wrap gap-1 mb-1">
                <x-badge :variant="$finding->severity()->value === 'critical' ? 'danger' : ($finding->severity()->value === 'warning' ? 'warning' : 'neutral')">{{ ucfirst($finding->severity()->value) }}</x-badge>
                <h3 class="h5 mb-0">{{ $finding->title() }}</h3>
            </div>
            <p class="mb-1">{{ $finding->description() }}</p>

            <div class="d-flex flex-wrap gap-1 align-items-center mb-1">
                <a class="btn btn-sm btn-outline-secondary" href="{{ $page->url }}" target="_blank" rel="noopener noreferrer">Open page</a>
                @if($suggestsTitle($finding->rule_key))
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-role="copy-title" data-copy="{{ $suggestedTitle }}"
                            onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy); this.textContent='Copied';">Copy suggested title</button>
                    <span class="text-caption text-muted" data-role="suggested-title">{{ $suggestedTitle }}</span>
                @endif
            </div>

            @php($howTo = $steps($finding->rule_key))
            @if($howTo !== [])
                <details data-role="how-to" open>
                    <summary class="fw-semibold">Show me how to fix this</summary>
                    <ol class="mt-1 mb-0">
                        @foreach($howTo as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                </details>
            @endif
        </x-card>
    @empty
        <x-card :padded="true" data-role="page-clean"><p class="mb-0">We did not find any problems on this page.</p></x-card>
    @endforelse
@endsection
