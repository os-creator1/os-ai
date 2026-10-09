@extends('customer.business.website.external.layout')

@section('external-content')
    @include('customer.business.website.external._status')

    @if($crawl === null)
        <x-card :padded="true" data-role="audit-empty"><p class="mb-0 text-muted">The audit appears here once your website has been checked.</p></x-card>
    @else
        <p class="text-caption text-muted mb-2" data-role="audit-meta">
            Checked {{ $crawl->finished_at?->diffForHumans() }} &middot; {{ $crawl->pages_fetched }} {{ $crawl->pages_fetched === 1 ? 'page' : 'pages' }}.
            MotionGrove cannot edit your website, so each item shows what to change and where.
            @if($onlyRule) <a href="{{ route('customer.workspaces.businesses.website.external.audit', [$workspaceUid, $businessUid]) }}">Show all issues</a> @endif
        </p>

        @forelse($groups as $group)
            <x-card :padded="true" class="mb-2" data-role="issue" data-rule="{{ $group['rule_key'] }}" data-severity="{{ $group['severity']->value }}">
                <div class="d-flex align-items-center flex-wrap gap-1 mb-1">
                    <x-badge :variant="$group['severity']->value === 'critical' ? 'danger' : ($group['severity']->value === 'warning' ? 'warning' : 'neutral')">{{ ucfirst($group['severity']->value) }}</x-badge>
                    <h2 class="h5 mb-0">{{ $group['title'] }}</h2>
                </div>
                <p class="mb-1">{{ $group['description'] }}</p>

                @if($group['pages'] !== [])
                    <p class="text-label mb-50">{{ $group['count'] }} {{ $group['count'] === 1 ? 'page' : 'pages' }} affected</p>
                    <ul class="mb-1" data-role="affected-pages">
                        @foreach($group['pages'] as $affected)
                            <li>
                                <a href="{{ route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $affected['id']]) }}">{{ $affected['path'] }}</a>
                                <a class="text-caption ms-1" href="{{ $affected['url'] }}" target="_blank" rel="noopener noreferrer">Open page</a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @php($howTo = $steps($group['rule_key']))
                @if($howTo !== [])
                    <details data-role="how-to">
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
            <x-card :padded="true" data-role="audit-clean"><p class="mb-0">We did not find any problems on the pages we checked.</p></x-card>
        @endforelse
    @endif
@endsection
