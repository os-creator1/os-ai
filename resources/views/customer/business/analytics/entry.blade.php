@extends('layouts/contentLayoutMaster')

@section('title', 'Analytics')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Analytics</h4>
        </div>
    </div>

    {{-- Flash left by the legacy campaign actions, which redirect here now that the customer Reports product is gone. --}}
    @if (session('status'))
        <x-alert variant="{{ match (session('status')) { 'success' => 'success', 'warning' => 'warning', 'info' => 'accent', default => 'danger' } }}" icon="alert-circle" class="mb-2" data-role="flash-message">
            {{ session('message') }}
        </x-alert>
    @endif
    @if(count($accessible) === 0)
        <x-card :padded="true">
            <x-empty-state icon="bar-chart-2" title="No Business available yet"
                            description="Analytics are organized by Business. You don't have access to a Business yet — ask a Workspace owner to add you, or create a Business to get started." />
        </x-card>
    @else
        <x-card :padded="true">
            <p class="text-section-heading mb-2">Choose a Business to continue</p>
            <div class="list-group">
                @foreach($accessible as [$workspace, $business])
                    <a href="{{ route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]) }}"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span>
                            <strong>{{ $business->name }}</strong>
                            <span class="text-caption d-block">{{ $workspace->name }}</span>
                        </span>
                        <x-ds-icon name="chevron-right" size="18" />
                    </a>
                @endforeach
            </div>
        </x-card>
    @endif
@endsection
