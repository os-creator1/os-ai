@extends('layouts/contentLayoutMaster')

@section('title', 'Forms')

@section('content')
    @php
        // Forms V1 — presentation only. Every authorization answer (tenancy,
        // capability, entitlement) was decided before this view rendered; every
        // control below posts to a route that runs the full chain again.
        $scope = [$workspace->uid, $business->uid];
    @endphp

    @include('customer.business.forms._messages')

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Forms</h4>
            <p class="card-text text-muted">
                Questionnaires and lead forms for <strong>{{ $business->name }}</strong>. Build a form once, then offer it at the
                locations you choose — each response belongs to the location it came through.
            </p>

            <a href="{{ route('customer.workspaces.businesses.forms.create', $scope) }}" class="btn btn-primary mb-2" data-role="forms-add">New form</a>
            <a href="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}" class="btn btn-outline-secondary mb-2" data-role="forms-submissions">View responses</a>

            @if ($forms->isEmpty())
                <p class="mb-0" data-role="forms-empty">No forms yet. Create your first form.</p>
            @else
                <div class="table-responsive">
                    <table class="table" data-role="forms-list">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($forms as $form)
                                <tr data-form="{{ $form->uid }}">
                                    <td>
                                        <a href="{{ route('customer.workspaces.businesses.forms.edit', array_merge($scope, [$form->uid])) }}">{{ $form->name }}</a>
                                    </td>
                                    <td>{{ $form->lifecycle_state->label() }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}?form={{ $form->uid }}" class="btn btn-sm btn-outline-secondary">Responses</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
