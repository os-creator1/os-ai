@extends('customer.business.website.external.layout')

@section('external-content')
    @include('customer.business.website.external._status')

    @if($crawl === null)
        <x-card :padded="true" data-role="pages-empty"><p class="mb-0 text-muted">Your pages appear here once your website has been checked.</p></x-card>
    @else
        <x-card :padded="false" class="mb-2">
            <div class="table-responsive">
                <table class="table mb-0 align-middle" data-role="pages-table">
                    <thead>
                        <tr><th>Page</th><th>Title</th><th>Search visibility</th><th class="text-end">Issues</th><th>Last checked</th></tr>
                    </thead>
                    <tbody>
                        @foreach($pages as $page)
                            <tr data-role="page-row">
                                <td>
                                    <a href="{{ route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $page->id]) }}">{{ $page->displayPath() }}</a>
                                    <a class="text-caption ms-1" href="{{ $page->url }}" target="_blank" rel="noopener noreferrer">Open page</a>
                                </td>
                                <td>{{ $page->title ?: '—' }}</td>
                                <td data-cell="visibility">{{ \App\Library\ExternalSite\ExternalWebsiteReader::pageState($page) }}</td>
                                <td class="text-end" data-cell="issues">
                                    @if($page->issue_count === 0)
                                        <span class="text-muted">None</span>
                                    @else
                                        <x-badge :variant="($page->worst?->value) === 'critical' ? 'danger' : (($page->worst?->value) === 'warning' ? 'warning' : 'neutral')">{{ $page->issue_count }}</x-badge>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $page->fetched_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
@endsection
