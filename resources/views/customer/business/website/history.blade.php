@extends('layouts/contentLayoutMaster')

@section('title', 'Publish history')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Publish history</h4>
            <x-badge :variant="$website->status->value === 'published' ? 'success' : ($website->status->value === 'archived' ? 'neutral' : 'warning')">
                {{ ucfirst($website->status->value) }}
            </x-badge>
        </div>
    </div>

    @if (session('status'))
        <x-alert :variant="session('status') === 'success' ? 'success' : 'danger'" class="mb-3">{{ session('message') }}</x-alert>
    @endif

    <x-card :padded="false">
        <div class="list-group list-group-flush">
            @forelse ($revisions as $revision)
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <span>
                        <strong>Version {{ $revision->version_number }}</strong>
                        @if ($website->published_revision_id === $revision->id)
                            <x-badge variant="success">Currently live</x-badge>
                        @endif
                        <span class="text-caption d-block">
                            Published {{ $revision->created_at->diffForHumans() }}
                            @if ($revision->creator)
                                by {{ $revision->creator->name ?? $revision->creator->email }}
                            @endif
                        </span>
                    </span>
                    <span>
                        @if ($website->published_revision_id !== $revision->id)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.website.history.rollback', [$workspaceUid, $businessUid, $revision->uid]) }}" class="d-inline" onsubmit="return confirm('Roll back the live site to version {{ $revision->version_number }}?');">
                                @csrf
                                <x-button variant="secondary" size="sm" type="submit">Roll back to this version</x-button>
                            </form>
                        @endif
                    </span>
                </div>
            @empty
                <div class="p-4">
                    <x-empty-state icon="clock" title="No published versions yet" description="Publish your Website to create the first version." />
                </div>
            @endforelse
        </div>
    </x-card>
@endsection
