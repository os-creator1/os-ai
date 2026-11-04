@extends('layouts/contentLayoutMaster')

@section('title', 'Recommendation')

@section('content')
    <section id="opportunities-show">
        <div class="row">
            <div class="col-12 mb-1">
                <x-button variant="secondary" size="sm" :href="route('customer.opportunities.index')">
                    &larr; Back to recommendations
                </x-button>
            </div>

            <div class="col-12 col-lg-8">
                @if (session('status') === 'success')
                    <x-alert :dismissible="true" variant="success" role="status" data-role="flash-message">{{ session('message') }}</x-alert>
                @endif

                @if ($errors->any())
                    <x-alert variant="danger" data-role="validation-summary">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif

                <x-card>
                        <div class="d-flex flex-wrap align-items-center gap-1 mb-1" data-role="recommendation-labels">
                            @if ($isOptional)
                                <x-badge variant="secondary">Optional improvement</x-badge>
                            @endif
                            @if ($opportunity->status->value !== 'open')
                                <x-badge variant="accent">{{ ['open' => 'Open', 'awaiting_approval' => 'Waiting for your approval', 'in_progress' => 'In progress', 'snoozed' => 'Snoozed', 'dismissed' => 'Dismissed', 'completed' => 'Done'][$opportunity->status->value] ?? ucwords(str_replace('_', ' ', $opportunity->status->value)) }}</x-badge>
                            @endif
                        </div>

                        <h2 class="h3 mb-1" data-role="recommendation-title">{{ $customerTitle }}</h2>
                        <p class="mb-2">{{ $customerSummary }}</p>

                        @if ($opportunity->snoozed_until)
                            <p class="text-muted small">Snoozed until {{ $opportunity->snoozed_until->format('M j, Y') }}.</p>
                        @endif
                        @if ($opportunity->completed_at)
                            <p class="text-muted small">Done {{ $opportunity->completed_at->format('M j, Y') }}.</p>
                        @endif

                        @if ($actionLabel !== null)
                            <div class="mb-2" data-role="next-step">
                                <h3 class="h6 text-muted mb-25">Next step</h3>
                                <p class="mb-1">{{ $actionLabel }}</p>
                                @if ($actionDestination !== null && ! $canConfigureAction)
                                    <a href="{{ $actionDestination['url'] }}" class="btn btn-primary" data-role="primary-cta">{{ $actionDestination['label'] }}</a>
                                @endif
                            </div>
                        @endif

                        @if ($isOptional)
                            <p class="text-muted small mb-2" data-role="optional-note">Optional improvement &mdash; this does not affect your CRM setup.</p>
                        @endif

                        @if ($configuredValue !== null)
                            <p class="mb-1"><strong>Saved value:</strong> {{ $configuredValue }}</p>
                        @endif

                        @if ($opportunity->status->value === 'open' && $canConfigureAction)
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

                        {{-- Implementation Contract 19 §5.3, §12 19.E — before a human
                             approves a paid action, show the actual payer, the estimated
                             amount or units, the pricing basis and whether the payer's
                             wallet currently covers it. Absent for every non-paid action
                             (add_phone included) and for every action with no configured
                             price, never a fabricated free estimate. --}}
                        @if ($costEstimate !== null)
                            <div class="mb-2">
                                <h3 class="h6 text-muted mb-25">Estimated cost</h3>
                                <p class="mb-1"><strong>Payer:</strong> {{ ucfirst(str_replace('_', ' ', $costEstimate['payerType'])) }}</p>
                                @if ($costEstimate['formattedAmount'] !== null)
                                    <p class="mb-1">
                                        <strong>Estimated amount:</strong>
                                        up to {{ $costEstimate['formattedAmount'] }}
                                    </p>
                                @endif
                                @if ($costEstimate['unitCount'] !== null)
                                    <p class="mb-1">
                                        <strong>Units:</strong>
                                        up to {{ number_format($costEstimate['unitCount']) }} {{ $costEstimate['unitKind'] }}
                                    </p>
                                @endif
                                <p class="mb-1"><strong>Basis:</strong> {{ $costEstimate['basis'] === 'exact' ? 'Exact' : 'Upper bound' }}</p>
                                @if (! $costEstimate['walletSufficient'])
                                    <x-alert variant="warning" class="mb-1">
                                        The payer's wallet does not currently have enough funds to cover this action.
                                    </x-alert>
                                @endif
                            </div>
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

                        @if (! empty($evidence))
                            <details class="mb-2">
                                <summary class="text-muted small">Why we suggest this</summary>
                                <ul class="mt-1 mb-0">
                                    @foreach ($evidence as $item)
                                        <li>{{ $item['summary'] }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        @if (in_array($opportunity->status->value, ['open', 'awaiting_approval'], true))
                            <div class="d-flex flex-wrap align-items-center gap-2 border-top pt-1" data-role="secondary-actions">
                                <form method="POST" action="{{ route('customer.opportunities.dismiss', $opportunity->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Removes this from your recommendations. It stays in your history.">Dismiss recommendation</button>
                                </form>

                                <details>
                                    <summary class="text-muted small">More options</summary>
                                    <form method="POST" action="{{ route('customer.opportunities.snooze', $opportunity->id) }}" class="mt-1">
                                        @csrf
                                        <div class="d-flex flex-wrap gap-1 align-items-end">
                                            <div style="max-width: 200px;">
                                                <x-select
                                                    name="duration"
                                                    label="Remind me in"
                                                    :options="['1_day' => '1 day', '3_days' => '3 days', '1_week' => '1 week']"
                                                    :selected="old('duration')"
                                                />
                                            </div>
                                            <x-button variant="secondary" type="submit">Snooze recommendation</x-button>
                                        </div>
                                    </form>
                                </details>
                            </div>
                        @endif

                        @if (in_array($opportunity->status->value, ['snoozed', 'dismissed', 'completed'], true))
                            <form method="POST" action="{{ route('customer.opportunities.reopen', $opportunity->id) }}" class="border-top pt-1">
                                @csrf
                                <x-button variant="outline">Reopen recommendation</x-button>
                            </form>
                        @endif

                        @if ($execution !== null)
                            <div class="border-top pt-1 mt-2" data-role="activity">
                                <h3 class="h6 text-muted mb-25">Activity</h3>
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
