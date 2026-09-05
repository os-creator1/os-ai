@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Outreach'))

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">{{ __('locale.menu.Outreach') }}</h4>
        </div>
    </div>

    @if(count($accessible) === 0)
        <x-card :padded="true">
            <x-empty-state icon="send" title="No Business available yet"
                            description="Outreach is organized by Business. You don't have access to a Business yet — ask a Workspace owner to add you, or create a Business to get started." />
        </x-card>
    @else
        <x-card :padded="true">
            <p class="text-section-heading mb-2">Choose a Business to continue</p>
            <div class="list-group">
                @foreach($accessible as [$workspace, $business])
                    <a href="{{ route('customer.workspaces.businesses.outreach.index', [$workspace->uid, $business->uid]) }}"
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
