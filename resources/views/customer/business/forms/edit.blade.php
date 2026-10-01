@extends('layouts/contentLayoutMaster')

@section('title', $form->name)

@section('content')
    @php
        $scope = [$workspace->uid, $business->uid];
        $formScope = array_merge($scope, [$form->uid]);
    @endphp

    @include('customer.business.forms._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">
                {{ $form->name }}
                <span class="badge badge-light-secondary" data-role="forms-state">{{ $form->lifecycle_state->label() }}</span>
            </h4>

            @if ($form->isActive())
                <form method="POST" action="{{ route('customer.workspaces.businesses.forms.deactivate', $formScope) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger" data-role="forms-deactivate">Switch off</button>
                </form>
            @else
                <form method="POST" action="{{ route('customer.workspaces.businesses.forms.activate', $formScope) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-primary" data-role="forms-activate">{{ $form->lifecycle_state->value === 'inactive' ? 'Switch on again' : 'Activate' }}</button>
                </form>
            @endif
            <a href="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}?form={{ $form->uid }}" class="btn btn-outline-secondary">View responses</a>
            <a href="{{ route('customer.workspaces.businesses.forms.index', $scope) }}" class="btn btn-link">All forms</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Where it is offered</h4>
            <p class="card-text text-muted">
                Each location gets its own link, so every response is tied to the location it came through. Only locations you can access are listed.
            </p>

            @if (count($locations) === 0)
                <p class="mb-0" data-role="forms-no-locations">You don't have access to any locations for this business.</p>
            @else
                <ul class="list-group" data-role="forms-locations">
                    @foreach ($locations as $location)
                        @php
                            $deployment = $deployments->get($location->id);
                            $on = $deployment !== null && $deployment->is_enabled;
                        @endphp
                        <li class="list-group-item" data-location="{{ $location->uid }}">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    {{ $location->name ?: 'Unnamed location' }}
                                    @if ($location->isArchived())
                                        <span class="badge badge-light-secondary">Archived</span>
                                    @endif
                                </span>
                                <form method="POST" action="{{ route('customer.workspaces.businesses.forms.locations.set', array_merge($formScope, [$location->uid])) }}">
                                    @csrf
                                    <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
                                    <button type="submit" class="btn btn-sm {{ $on ? 'btn-outline-danger' : 'btn-outline-primary' }}" data-role="forms-location-toggle">
                                        {{ $on ? 'Stop offering here' : 'Offer here' }}
                                    </button>
                                </form>
                            </div>
                            @if ($on)
                                <div class="mt-1">
                                    <small class="text-muted">Link:</small>
                                    <a href="{{ route('public.forms.show', [$deployment->uid]) }}" data-role="forms-public-link">{{ route('public.forms.show', [$deployment->uid]) }}</a>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Edit questions</h4>
            <p class="card-text text-muted">Editing a form never changes how earlier responses read — each response keeps the questions it was answered against.</p>

            <form method="POST" action="{{ route('customer.workspaces.businesses.forms.update', $formScope) }}" data-role="forms-edit-form">
                @csrf

                @include('customer.business.forms._editor')

                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
    </div>
@endsection
