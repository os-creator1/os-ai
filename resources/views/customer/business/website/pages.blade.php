@extends('layouts/contentLayoutMaster')

@section('title', 'Pages')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Pages</h4>
            <x-button variant="primary" href="{{ route('customer.workspaces.businesses.website.pages.create', [$workspaceUid, $businessUid]) }}">Add page</x-button>
        </div>
    </div>

    @if (session('status'))
        <x-alert :variant="session('status') === 'success' ? 'success' : 'danger'" class="mb-3">{{ session('message') }}</x-alert>
    @endif

    <x-card :padded="false">
        <div class="list-group list-group-flush">
            @forelse ($pages as $page)
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <span>
                        <strong>{{ $page->title }}</strong>
                        @if ($page->is_home)
                            <x-badge variant="accent">Home</x-badge>
                        @else
                            <span class="text-caption d-block">/{{ $page->slug }}</span>
                        @endif
                    </span>
                    <span>
                        <x-button variant="ghost" size="sm" href="{{ route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid, $page->uid]) }}">Preview</x-button>
                        <x-button variant="secondary" size="sm" href="{{ route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $page->uid]) }}">Edit</x-button>
                    </span>
                </div>
            @empty
                <div class="p-4">
                    <x-empty-state icon="file" title="No pages yet" description="Add your first page to get started." />
                </div>
            @endforelse
        </div>
    </x-card>
@endsection
