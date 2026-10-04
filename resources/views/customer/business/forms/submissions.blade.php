@extends('layouts/contentLayoutMaster')

@section('title', 'Form responses')

@section('content')
    @php
        // Forms V1 — `$locations` and `$submissions` are ALREADY bounded to the
        // Locations this actor may reach (FormSubmissionReader over
        // LocationAccessGuard). Nothing here can list, count or name any other
        // Location's responses, and no overall total is shown, because a total
        // would disclose how many responses the actor cannot see.
        $scope = [$workspace->uid, $business->uid];
    @endphp

    @include('customer.business.forms._messages')

    @if ($form)
        <link rel="stylesheet" href="{{ asset('css/forms/form-builder.css') }}?v={{ filemtime(public_path('css/forms/form-builder.css')) }}">
        <div class="fb">
            @include('customer.business.forms._builder_header', ['tab' => 'submissions'])
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Responses{{ $form ? ' — '.$form->name : '' }}</h4>

            <form method="GET" action="{{ route('customer.workspaces.businesses.forms.submissions.index', $scope) }}" class="form-inline mb-2" data-role="forms-location-filter">
                @if ($form)
                    <input type="hidden" name="form" value="{{ $form->uid }}">
                @endif
                <label for="location" class="mr-1">Location</label>
                <select id="location" name="location" class="form-control form-control-sm mr-1">
                    <option value="">All my locations</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->uid }}" @selected($selectedLocationUid === $location->uid)>{{ $location->name ?: 'Unnamed location' }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
            </form>

            @if ($submissions->isEmpty())
                <p class="mb-0" data-role="forms-submissions-empty">No responses yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table" data-role="forms-submissions">
                        <thead>
                            <tr>
                                <th>Received</th>
                                <th>Form</th>
                                <th>Location</th>
                                <th>Contact</th>
                                <th>Answers</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($submissions as $submission)
                                <tr data-submission="{{ $submission->uid }}">
                                    <td>{{ $submission->created_at?->format('M j, Y g:i A') }}</td>
                                    <td>{{ $submission->form?->name }}</td>
                                    <td>{{ $submission->location?->name ?: 'Unnamed location' }}</td>
                                    @php($answers = $submission->version ? \App\Library\Forms\FormAnswerPresenter::keyAnswers($submission->version, $submission->values ?? []) : [])
                                    @php($person = $submission->version ? \App\Library\Forms\FormAnswerPresenter::personName($submission->version, $submission->values ?? []) : '')
                                    <td>
                                        @if ($person !== '')<div>{{ $person }}</div>@endif
                                        <div class="{{ $person !== '' ? 'text-muted small' : '' }}">{{ $submission->contact?->phone ?? ($submission->contact_resolution->value === 'ambiguous' ? 'Needs review' : '—') }}</div>
                                    </td>
                                    <td class="small" data-role="forms-key-answers">
                                        @foreach ($answers as $answer)<div><span class="text-muted">{{ $answer['label'] }}:</span> {{ $answer['value'] }}</div>@endforeach
                                    </td>
                                    <td data-role="forms-submission-status">
                                        @if ($submission->contact_resolution->value === 'ambiguous')
                                            <span class="badge badge-light-warning">Needs review</span>
                                        @elseif ($submission->contact)
                                            <span class="badge badge-light-success">Contact {{ $submission->contact_resolution->value === 'created' ? 'created' : 'matched' }}</span>
                                        @else
                                            <span class="badge badge-light-secondary">No contact</span>
                                        @endif
                                        @if ($submission->crm_opportunity_id)<span class="badge badge-light-primary">Opportunity</span>@endif
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ route('customer.workspaces.businesses.forms.submissions.show', array_merge($scope, [$submission->uid])) }}">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $submissions->appends(array_filter(['location' => $selectedLocationUid, 'form' => $form?->uid]))->links() }}
            @endif

            <a href="{{ route('customer.workspaces.businesses.forms.index', $scope) }}">Back to forms</a>
        </div>
    </div>
@endsection
