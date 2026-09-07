@extends('layouts/contentLayoutMaster')

@section('title', 'Set up your Website')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Set up your Website</h4>
        </div>
    </div>

    @if (session('status'))
        <x-alert :variant="session('status') === 'success' ? 'success' : 'danger'" class="mb-3">{{ session('message') }}</x-alert>
    @endif

    <x-card :padded="true">
        <p class="text-caption mb-3">Create one Website for {{ $business->name }}. You can generate an initial draft with AI once it's created, or build pages manually.</p>
        <form method="POST" action="{{ route('customer.workspaces.businesses.website.store', [$workspaceUid, $businessUid]) }}">
            @csrf
            <x-input name="name" label="Website name" help="Shown to you only — never rendered on the public site." required />
            <x-button type="submit" variant="primary">Create Website</x-button>
        </form>
    </x-card>
@endsection
