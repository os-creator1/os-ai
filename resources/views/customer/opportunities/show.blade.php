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
                @if (session('status') === 'success')
                    <div class="alert alert-success">{{ session('message') }}</div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

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

                        @if ($opportunity->status->value === 'open')
                            <hr>

                            <form method="POST" action="{{ route('customer.opportunities.configure-action', $opportunity->id) }}" class="mb-2">
                                @csrf
                                <label class="form-label" for="value">Phone number</label>
                                <div class="d-flex flex-wrap gap-1">
                                    <input
                                        type="text"
                                        class="form-control"
                                        style="max-width: 260px;"
                                        id="value"
                                        name="value"
                                        maxlength="50"
                                        value="{{ old('value', $configuredValue) }}"
                                    >
                                    <button type="submit" class="btn btn-primary">Save phone number</button>
                                </div>
                            </form>
                        @endif

                        @if ($opportunity->status->value === 'open' && $configuredValue !== null)
                            <form method="POST" action="{{ route('customer.opportunities.request-approval', $opportunity->id) }}" class="mb-2">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary">Request approval</button>
                            </form>
                        @endif

                        @if ($opportunity->status->value === 'awaiting_approval')
                            <form method="POST" action="{{ route('customer.opportunities.confirm-approval', $opportunity->id) }}" class="mb-2">
                                @csrf
                                <p class="text-muted small mb-1">Confirming will start the approved action.</p>
                                <button type="submit" class="btn btn-success">Confirm and start</button>
                            </form>
                        @endif

                        @if (in_array($opportunity->status->value, ['open', 'awaiting_approval'], true))
                            <hr>

                            <form method="POST" action="{{ route('customer.opportunities.snooze', $opportunity->id) }}" class="mb-2">
                                @csrf
                                <label class="form-label" for="duration">Snooze for</label>
                                <div class="d-flex flex-wrap gap-1">
                                    <select class="form-control" style="max-width: 200px;" id="duration" name="duration">
                                        <option value="1_day" @selected(old('duration') === '1_day')>1 day</option>
                                        <option value="3_days" @selected(old('duration') === '3_days')>3 days</option>
                                        <option value="1_week" @selected(old('duration') === '1_week')>1 week</option>
                                    </select>
                                    <button type="submit" class="btn btn-outline-secondary">Snooze opportunity</button>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('customer.opportunities.dismiss', $opportunity->id) }}" class="mb-2">
                                @csrf
                                <p class="text-muted small mb-1">Dismissing removes this opportunity from your actionable queue.</p>
                                <button type="submit" class="btn btn-outline-danger">Dismiss opportunity</button>
                            </form>
                        @endif

                        @if (in_array($opportunity->status->value, ['snoozed', 'dismissed', 'completed'], true))
                            <hr>

                            <form method="POST" action="{{ route('customer.opportunities.reopen', $opportunity->id) }}" class="mb-2">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary">Reopen opportunity</button>
                            </form>
                        @endif

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

                        @if ($canRetry)
                            <form method="POST" action="{{ route('customer.opportunities.retry', $opportunity->id) }}" class="mt-2">
                                @csrf
                                <p class="text-muted small mb-1">This will attempt the action again.</p>
                                <button type="submit" class="btn btn-outline-primary">Retry</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
