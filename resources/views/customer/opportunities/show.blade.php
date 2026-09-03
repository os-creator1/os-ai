@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunity details')

@section('content')
    <section id="opportunities-show">
        <div class="row">
            <div class="col-12 mb-1">
                <x-button variant="secondary" size="sm" :href="route('customer.opportunities.index')">
                    &larr; Back to opportunities
                </x-button>
            </div>

            <div class="col-12">
                @if (session('status') === 'success')
                    <x-alert variant="success">{{ session('message') }}</x-alert>
                @endif

                @if ($errors->any())
                    <x-alert variant="danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif

                <x-card :title="$opportunity->title">
                        <p>{{ $opportunity->summary }}</p>

                        <div class="row mb-2">
                            <div class="col-md-3">
                                <strong>Status</strong>
                                <div>
                                    <x-badge variant="accent">
                                        {{ ucwords(str_replace('_', ' ', $opportunity->status->value)) }}
                                    </x-badge>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <strong>Freshness</strong>
                                <div>
                                    <x-badge :variant="$opportunity->freshness->value === 'current' ? 'success' : 'warning'">
                                        {{ ucfirst($opportunity->freshness->value) }}
                                    </x-badge>
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

                        @if ($opportunity->status->value === 'open' && $canConfigureAction)
                            <hr>

                            <form method="POST" action="{{ route('customer.opportunities.configure-action', $opportunity->id) }}" class="mb-2">
                                @csrf
                                <div class="d-flex flex-wrap gap-1 align-items-end">
                                    <div style="max-width: 260px;">
                                        <x-input
                                            type="text"
                                            name="value"
                                            label="Phone number"
                                            maxlength="50"
                                            value="{{ old('value', $configuredValue) }}"
                                        />
                                    </div>
                                    <x-button type="submit">Save phone number</x-button>
                                </div>
                            </form>
                        @endif

                        @if ($opportunity->status->value === 'open' && $configuredValue !== null)
                            <form method="POST" action="{{ route('customer.opportunities.request-approval', $opportunity->id) }}" class="mb-2">
                                @csrf
                                <x-button variant="outline">Request approval</x-button>
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
                                <div class="d-flex flex-wrap gap-1 align-items-end">
                                    <div style="max-width: 200px;">
                                        <x-select
                                            name="duration"
                                            label="Snooze for"
                                            :options="['1_day' => '1 day', '3_days' => '3 days', '1_week' => '1 week']"
                                            :selected="old('duration')"
                                        />
                                    </div>
                                    <x-button variant="secondary" type="submit">Snooze opportunity</x-button>
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
                                <x-button variant="outline">Reopen opportunity</x-button>
                            </form>
                        @endif

                        <hr>

                        <h5>Latest execution</h5>
                        @if ($execution === null)
                            <p class="text-muted mb-0">No execution has been started for this opportunity yet.</p>
                        @else
                            @if (in_array($execution['status'], ['pending', 'running'], true))
                                <p id="execution-status-text" role="status" aria-live="polite" class="mb-1">
                                    This action is currently {{ $execution['status'] === 'pending' ? 'queued' : 'in progress' }}.
                                </p>

                                @if ($shouldPollExecution)
                                    <p class="mb-1">
                                        <a href="{{ route('customer.opportunities.show', $opportunity->id) }}">Refresh status</a>
                                    </p>
                                @endif
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

                        @if ($shouldPollExecution)
                            <script id="opportunity-execution-poller">
                                (function () {
                                    var statusUrl = @json(route('customer.opportunities.execution-status', $opportunity->id));
                                    var statusEl = document.getElementById('execution-status-text');
                                    var delays = [2000, 4000, 8000, 15000];
                                    var attempt = 1;
                                    var consecutiveFailures = 0;
                                    var maxConsecutiveFailures = 3;
                                    var stoppedMessage = 'Automatic status updates stopped. Refresh this page to try again.';
                                    var timerId = null;
                                    var requestInFlight = false;
                                    var pendingResume = false;

                                    function setStatusText(text) {
                                        if (statusEl && statusEl.textContent !== text) {
                                            statusEl.textContent = text;
                                        }
                                    }

                                    function nextDelay() {
                                        var delay = delays[Math.min(attempt, delays.length - 1)];
                                        attempt++;
                                        return delay;
                                    }

                                    function scheduleNext(delay) {
                                        timerId = setTimeout(poll, delay);
                                    }

                                    function stopPolling() {
                                        if (timerId !== null) {
                                            clearTimeout(timerId);
                                            timerId = null;
                                        }
                                        setStatusText(stoppedMessage);
                                    }

                                    function isValidPayload(data) {
                                        return !!data
                                            && typeof data.message === 'string'
                                            && typeof data.is_terminal === 'boolean'
                                            && typeof data.opportunity_status === 'string'
                                            && (data.execution_status === null || typeof data.execution_status === 'string');
                                    }

                                    function handleFailure() {
                                        consecutiveFailures++;

                                        if (consecutiveFailures >= maxConsecutiveFailures) {
                                            stopPolling();
                                            return;
                                        }

                                        scheduleNext(15000);
                                    }

                                    function poll() {
                                        if (document.hidden) {
                                            pendingResume = true;
                                            return;
                                        }

                                        requestInFlight = true;

                                        fetch(statusUrl, {
                                            headers: {
                                                'Accept': 'application/json',
                                                'X-Requested-With': 'XMLHttpRequest'
                                            }
                                        }).then(function (response) {
                                            if (response.status === 401 || response.status === 403 || response.status === 404) {
                                                requestInFlight = false;
                                                stopPolling();
                                                return;
                                            }

                                            if (!response.ok) {
                                                requestInFlight = false;
                                                handleFailure();
                                                return;
                                            }

                                            response.json().then(function (data) {
                                                requestInFlight = false;

                                                if (!isValidPayload(data)) {
                                                    handleFailure();
                                                    return;
                                                }

                                                consecutiveFailures = 0;
                                                setStatusText(data.message);

                                                if (data.is_terminal) {
                                                    setTimeout(function () {
                                                        window.location.reload();
                                                    }, 1000);
                                                    return;
                                                }

                                                scheduleNext(nextDelay());
                                            }).catch(function () {
                                                requestInFlight = false;
                                                handleFailure();
                                            });
                                        }).catch(function () {
                                            requestInFlight = false;
                                            handleFailure();
                                        });
                                    }

                                    document.addEventListener('visibilitychange', function () {
                                        if (!document.hidden && pendingResume && !requestInFlight) {
                                            pendingResume = false;
                                            poll();
                                        }
                                    });

                                    scheduleNext(delays[0]);
                                })();
                            </script>
                        @endif

                        @if ($canRetry)
                            <form method="POST" action="{{ route('customer.opportunities.retry', $opportunity->id) }}" class="mt-2">
                                @csrf
                                <p class="text-muted small mb-1">This will attempt the action again.</p>
                                <x-button variant="outline">Retry</x-button>
                            </form>
                        @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
