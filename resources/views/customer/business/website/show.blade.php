@extends('layouts/contentLayoutMaster')

@section('title', 'Website')

@section('content')
    <div class="row mb-2 align-items-center">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $website->name }}</h4>
            <x-badge :variant="$website->status->value === 'published' ? 'success' : ($website->status->value === 'archived' ? 'neutral' : 'warning')">
                {{ ucfirst($website->status->value) }}
            </x-badge>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($website->status->value === 'published')
        <x-alert variant="accent" class="mb-3">
            Live at
            <a href="{{ route('public.website.home', $website->public_id) }}" target="_blank" rel="noopener">{{ route('public.website.home', $website->public_id) }}</a>
        </x-alert>
    @endif

    <div class="row">
        <div class="col-md-6 mb-3">
            <x-card title="Pages">
                <p class="text-caption">{{ $pageCount }} page(s).</p>
                <x-button variant="primary" href="{{ route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid]) }}">Manage pages</x-button>
                @if ($pageCount === 0)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.generate', [$workspaceUid, $businessUid]) }}" class="d-inline">
                        @csrf
                        <x-button variant="outline" type="submit">Generate draft with AI</x-button>
                    </form>
                @endif
            </x-card>
        </div>
        <div class="col-md-6 mb-3">
            <x-card title="Publish">
                <p class="text-caption">Publishing creates a new, immutable snapshot of every current page.</p>
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.publish', [$workspaceUid, $businessUid]) }}" class="d-inline">
                    @csrf
                    <x-button variant="primary" type="submit">Publish now</x-button>
                </form>
                <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid]) }}">Preview</x-button>
                <x-button variant="ghost" href="{{ route('customer.workspaces.businesses.website.history', [$workspaceUid, $businessUid]) }}">History</x-button>
            </x-card>
        </div>
    </div>
@endsection
