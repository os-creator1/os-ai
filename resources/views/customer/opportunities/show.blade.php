@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunity details')

@section('content')
    <section id="opportunities-show">
        <div class="row">
            <div class="col-12 mb-1">
                <a href="{{ route('customer.opportunities.index') }}" class="btn btn-outline-secondary btn-sm">
                    &larr; Back to opportunities
                </a>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ $opportunity->title }}</h4>
                    </div>
                    <div class="card-body">
                        <p>{{ $opportunity->summary }}</p>

                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Status</strong>
                                <div>
                                    <span class="badge bg-light-primary">
                                        {{ ucwords(str_replace('_', ' ', $opportunity->status->value)) }}
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <strong>Freshness</strong>
                                <div>
                                    <span class="badge {{ $opportunity->freshness->value === 'current' ? 'bg-light-success' : 'bg-light-warning' }}">
                                        {{ ucfirst($opportunity->freshness->value) }}
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <strong>First detected</strong>
                                <div>{{ optional($opportunity->first_detected_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                            <div class="col-md-3">
                                <strong>Last scored</strong>
                                <div>{{ optional($opportunity->scored_at)->format('M j, Y g:ia') ?? '—' }}</div>
                            </div>
                        </div>

                        @if ($opportunity->snoozed_until || $opportunity->dismissed_at || $opportunity->completed_at)
                            <div class="row mb-2">
                                @if ($opportunity->snoozed_until)
                                    <div class="col-md-4">
                                        <strong>Snoozed until</strong>
                                        <div>{{ $opportunity->snoozed_until->format('M j, Y g:ia') }}</div>
                                    </div>
                                @endif
                                @if ($opportunity->dismissed_at)
                                    <div class="col-md-4">
                                        <strong>Dismissed</strong>
                                        <div>{{ $opportunity->dismissed_at->format('M j, Y g:ia') }}</div>
                                    </div>
                                @endif
                                @if ($opportunity->completed_at)
                                    <div class="col-md-4">
                                        <strong>Completed</strong>
                                        <div>{{ $opportunity->completed_at->format('M j, Y g:ia') }}</div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <hr>

                        <h5>Supporting evidence</h5>
                        @if (empty($evidence))
                            <p class="text-muted">No supporting evidence is available.</p>
                        @else
                            <ul>
                                @foreach ($evidence as $item)
                                    <li>
                                        {{ $item['summary'] }}
                                        @if ($item['retrieved_at'])
                                            <span class="text-muted">({{ $item['retrieved_at'] }})</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <hr>

                        <h5>Recommended action</h5>
                        <p class="mb-1">{{ $actionLabel }}</p>
                        @if ($configuredValue !== null)
                            <p class="mb-1"><strong>Configured value:</strong> {{ $configuredValue }}</p>
                        @endif
                        <p class="mb-0"><strong>Completion policy:</strong> {{ $completionPolicyLabel }}</p>

                        <hr>

                        <h5>Latest execution</h5>
                        @if ($execution === null)
                            <p class="text-muted mb-0">No execution has been started for this opportunity yet.</p>
                        @else
                            @if (in_array($execution['status'], ['pending', 'running'], true))
                                <p aria-live="polite" class="mb-1">
                                    This action is currently {{ $execution['status'] === 'pending' ? 'queued' : 'in progress' }}.
                                </p>
                            @else
                                <p class="mb-1">
                                    <strong>Status:</strong> {{ ucfirst($execution['status']) }}
                                    (attempt {{ $execution['attempt_number'] }})
                                </p>
                            @endif

                            @if ($execution['safe_result_summary'])
                                <p class="mb-1 text-success">{{ $execution['safe_result_summary'] }}</p>
                            @endif

                            @if ($execution['safe_error_summary'])
                                <p class="mb-1 text-danger">{{ $execution['safe_error_summary'] }}</p>
                            @endif

                            <div class="row">
                                @if ($execution['started_at'])
                                    <div class="col-md-6">
                                        <strong>Started</strong>
                                        <div>{{ $execution['started_at']->format('M j, Y g:ia') }}</div>
                                    </div>
                                @endif
                                @if ($execution['completed_at'])
                                    <div class="col-md-6">
                                        <strong>Completed</strong>
                                        <div>{{ $execution['completed_at']->format('M j, Y g:ia') }}</div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
