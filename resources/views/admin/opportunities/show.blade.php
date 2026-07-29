@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunity #' . $opportunity->id)

@section('content')
    <section id="admin-opportunities-show">
        <div class="row">
            <div class="col-12 mb-1">
                <a href="{{ route('admin.opportunities.index') }}" class="btn btn-outline-secondary btn-sm">
                    &larr; Back to opportunities
                </a>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Opportunity #{{ $opportunity->id }}</h4>
                    </div>
                    <div class="card-body">
                        <h5>Opportunity</h5>
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Business ID</strong>
                                <div>{{ $opportunity->business_id }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Business name</strong>
                                <div>{{ $opportunity->business?->name ?? 'Unknown' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Owning customer</strong>
                                <div>{{ $opportunity->business?->customer?->user?->displayName() ?? 'Unknown' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Worker</strong>
                                <div>{{ ucfirst(str_replace('_', ' ', $opportunity->worker_key->value)) }}</div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Title</strong>
                                <div>{{ $opportunity->title }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Type</strong>
                                <div>{{ $opportunity->type ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Status</strong>
                                <div>{{ ucfirst(str_replace('_', ' ', $opportunity->status->value)) }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Freshness</strong>
                                <div>{{ ucfirst($opportunity->freshness->value) }}</div>
                            </div>
                        </div>

                        <p class="mb-2">{{ $opportunity->summary }}</p>

                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Priority score</strong>
                                <div>{{ $opportunity->priority_score }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Impact</strong>
                                <div>{{ $opportunity->impact }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Urgency</strong>
                                <div>{{ $opportunity->urgency }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Occurrence #</strong>
                                <div>{{ $opportunity->occurrence_number }}</div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>First detected</strong>
                                <div>{{ optional($opportunity->first_detected_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Last confirmed</strong>
                                <div>{{ optional($opportunity->last_confirmed_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Created</strong>
                                <div>{{ optional($opportunity->created_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Updated</strong>
                                <div>{{ optional($opportunity->updated_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                        </div>

                        <hr>

                        <h5>Recommended action</h5>
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Action key</strong>
                                <div>{{ $recommendedAction['action_key'] ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Action schema version</strong>
                                <div>{{ $recommendedAction['action_schema_version'] ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Recommended action hash</strong>
                                <div class="text-break">{{ $recommendedAction['recommended_action_hash'] ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Completion policy</strong>
                                <div>{{ $recommendedAction['completion_policy'] ?? '—' }}</div>
                            </div>
                        </div>
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Approval required</strong>
                                <div>
                                    @if ($recommendedAction['approval_required'] === null)
                                        —
                                    @else
                                        {{ $recommendedAction['approval_required'] ? 'Yes' : 'No' }}
                                    @endif
                                </div>
                            </div>
                        </div>

                        <hr>

                        <h5>Transition history</h5>
                        <div class="table-responsive mb-3">
                            <table class="table table-hover">
                                <thead class="table-primary">
                                <tr>
                                    <th>ID</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Reason</th>
                                    <th>Actor type</th>
                                    <th>Actor user ID</th>
                                    <th>Occurred at</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($transitions as $transition)
                                    <tr>
                                        <td>{{ $transition->id }}</td>
                                        <td>{{ $transition->from_status ?? '—' }}</td>
                                        <td>{{ $transition->to_status }}</td>
                                        <td>{{ $transition->reason_code ?? '—' }}</td>
                                        <td>{{ $transition->actor_type->value }}</td>
                                        <td>{{ $transition->actor_user_id ?? '—' }}</td>
                                        <td>{{ optional($transition->created_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7">No transitions recorded for this opportunity.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        <hr>

                        <h5>Execution history</h5>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-primary">
                                <tr>
                                    <th>ID</th>
                                    <th>Status</th>
                                    <th>Attempt #</th>
                                    <th>Occurrence #</th>
                                    <th>Action key</th>
                                    <th>Schema version</th>
                                    <th>Idempotency key</th>
                                    <th>Result / error</th>
                                    <th>Started</th>
                                    <th>Completed</th>
                                    <th>Created</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($executions as $execution)
                                    <tr>
                                        <td>{{ $execution->id }}</td>
                                        <td>{{ ucfirst($execution->status->value) }}</td>
                                        <td>{{ $execution->attempt_number }}</td>
                                        <td>{{ $execution->occurrence_number }}</td>
                                        <td>{{ $execution->action_key }}</td>
                                        <td>{{ $execution->action_schema_version }}</td>
                                        <td class="text-break">{{ $execution->idempotency_key }}</td>
                                        <td>
                                            @if ($execution->safe_result_summary)
                                                <span class="text-success">{{ $execution->safe_result_summary }}</span>
                                            @elseif ($execution->safe_error_summary)
                                                <span class="text-danger">{{ $execution->safe_error_summary }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ optional($execution->started_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                        <td>{{ optional($execution->completed_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                        <td>{{ optional($execution->created_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11">No executions recorded for this opportunity.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
