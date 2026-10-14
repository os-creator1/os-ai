@extends('layouts/contentLayoutMaster')

@section('title', 'Services')

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        $active = $services->filter(fn ($service) => $service->status->value === 'active')->values();
        $inactive = $services->filter(fn ($service) => $service->status->value !== 'active')->values();
    @endphp

    @include('customer.settings._module-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', $scope),
        'title' => 'Services',
        'description' => 'What this business offers. Your primary service comes first on your website and in your search plan.',
    ])

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $key => $variant)
        @if (session($key))
            <x-alert :variant="$variant" class="mb-2" role="status">{{ session($key) }}</x-alert>
        @endif
    @endforeach

    @if ($errors->any())
        <x-alert variant="danger" class="mb-2" role="alert">{{ $errors->first() }}</x-alert>
    @endif

    @if ($canManage)
        <x-card title="Add a service" class="mb-2" data-section="service-add">
            <form method="POST" action="{{ route('customer.workspaces.businesses.services.store', $scope) }}" class="d-flex align-items-end" style="gap: 1rem; flex-wrap: wrap;">
                @csrf
                <div style="flex: 1 1 18rem;">
                    <label for="service-name" class="form-label">Service name</label>
                    <input id="service-name" type="text" name="name" class="form-control" maxlength="255" required value="{{ old('name') }}" placeholder="For example: Emergency plumbing">
                </div>
                <button type="submit" class="btn btn-primary" data-action="add-service">Add service</button>
            </form>
        </x-card>
    @endif

    <x-card title="Your services" class="mb-2" data-section="services-active">
        @if ($active->isEmpty())
            <p class="mb-0" data-role="no-active-services">This business has no active service yet.{{ $canManage ? ' Add one above.' : '' }}</p>
        @else
            <ul class="list-unstyled mb-0">
                @foreach ($active as $service)
                    <li class="py-1 border-bottom" data-service="{{ $service->uid }}">
                        <div class="d-flex align-items-center" style="gap: 1rem; flex-wrap: wrap;">
                            <strong>{{ $service->name }}</strong>
                            @if ($service->is_primary)
                                <span class="badge badge-light-primary" data-role="primary-badge">Primary service</span>
                            @endif
                        </div>
                        @if ($canManage)
                            <div class="d-flex mt-50" style="gap: .5rem; flex-wrap: wrap;">
                                <form method="POST" action="{{ route('customer.workspaces.businesses.services.rename', [...$scope, $service->uid]) }}" class="d-flex" style="gap: .5rem;">
                                    @csrf
                                    <input type="text" name="name" class="form-control form-control-sm" maxlength="255" required value="{{ $service->name }}" aria-label="Rename {{ $service->name }}">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Rename</button>
                                </form>
                                @unless ($service->is_primary)
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.services.primary', [...$scope, $service->uid]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary" data-action="make-primary">Make primary</button>
                                    </form>
                                @endunless
                                <form method="POST" action="{{ route('customer.workspaces.businesses.services.deactivate', [...$scope, $service->uid]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" data-action="deactivate">Turn off</button>
                                </form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    @if ($inactive->isNotEmpty())
        <x-card title="Turned off" class="mb-2" data-section="services-inactive">
            <ul class="list-unstyled mb-0">
                @foreach ($inactive as $service)
                    <li class="py-1 border-bottom d-flex align-items-center" style="gap: 1rem;" data-service="{{ $service->uid }}">
                        <span>{{ $service->name }}</span>
                        @if ($canManage)
                            <form method="POST" action="{{ route('customer.workspaces.businesses.services.activate', [...$scope, $service->uid]) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary" data-action="activate">Turn on</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
@endsection
