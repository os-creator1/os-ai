@extends('layouts/contentLayoutMaster')

{{--
    CRM Opportunities — the board for one pipeline (CrmBoard).

    The toolbar (pipeline, search, status, contact status) is an ordinary GET
    form; with JavaScript it updates the board region in place
    (window.AsyncRegion) and keeps the address bar in step, so refresh, bookmarks
    and Back/Forward show the same board. It sits outside the region so what
    someone types is never replaced while results load.

    Dragging an open card onto another column moves it (the same endpoint and
    rules as the move form on the opportunity's own page, which is the keyboard
    and no-JavaScript way to move one).
--}}

@section('title', 'Opportunities')

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
        $boardUrl = route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid]);
        $canManage = Gate::allows(\App\Http\Controllers\Customer\Business\CrmOpportunitiesController::MANAGE_PERMISSION);
    @endphp

    <section id="crm-opportunities">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-2">
            <h1 class="h3 mb-0">Opportunities</h1>

            @if ($canManage)
                <div class="d-flex flex-wrap gap-1" data-role="crm-actions">
                    <details class="position-relative" data-role="crm-new-pipeline">
                        <summary class="btn btn-outline-primary">New pipeline</summary>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.crm.pipelines.store', [$workspaceUid, $businessUid]) }}"
                              class="card card-body position-absolute end-0 mt-50 shadow" style="z-index: 5; min-width: 18rem;">
                            @csrf
                            <label for="crm-new-pipeline-name" class="form-label text-label">Pipeline name</label>
                            <input id="crm-new-pipeline-name" name="name" class="form-control mb-1" maxlength="100" required placeholder="e.g. Weddings">
                            <p class="text-caption mb-1">Starts with the standard stages; adjust them next.</p>
                            <x-button type="submit" variant="primary" size="sm">Create pipeline</x-button>
                        </form>
                    </details>
                </div>
            @endif
        </div>

        <form method="GET" action="{{ $boardUrl }}" class="row g-1 align-items-end mb-2" role="search" data-role="crm-filters" data-async-form="crm-board">
            <div class="col-md-3 col-sm-6">
                <label for="crm-pipeline" class="form-label text-label">Pipeline</label>
                <select id="crm-pipeline" name="pipeline" class="form-select" data-role="crm-pipeline-select">
                    @foreach ($pipelines as $option)
                        <option value="{{ $option->uid }}" @selected($option->is($pipeline))>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 col-sm-6">
                <x-search-field id="crm-search" name="q" :value="$filters->search" label="Search opportunities" placeholder="Search by name, contact or phone" />
            </div>
            <div class="col-md-2 col-sm-4">
                <label for="crm-status" class="form-label text-label">Status</label>
                <select id="crm-status" name="status" class="form-select">
                    @foreach (['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'all' => 'All'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 col-sm-4">
                <label for="crm-contact-status" class="form-label text-label">Contact status</label>
                <select id="crm-contact-status" name="contact_status" class="form-select">
                    @foreach (['any' => 'Any', 'no_contact' => 'No contact', 'in_contact' => 'In contact'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters->contactStatus === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 col-sm-4 d-flex gap-1">
                <x-button type="submit" variant="outline">Apply</x-button>
                @unless ($filters->isDefault())
                    <x-button variant="ghost" :href="$boardUrl . '?pipeline=' . $pipeline->uid">Clear</x-button>
                @endunless
            </div>
        </form>

        <div data-async-region="crm-board" data-role="crm-board" data-pipeline="{{ $pipeline->uid }}">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
                <p class="text-caption mb-0" data-role="crm-board-summary">
                    {{ $board['total'] }} {{ \Illuminate\Support\Str::plural('opportunity', $board['total']) }}
                    in {{ $pipeline->name }}@if (! $filters->isDefault()) matching these filters @endif
                </p>
                @if ($canManage)
                    {{-- Inside the region: both follow the pipeline being shown. --}}
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ route('customer.workspaces.businesses.crm.pipelines.settings', [$workspaceUid, $businessUid, $pipeline->uid]) }}" data-role="crm-pipeline-settings">Customize stages</a>
                        <x-button variant="primary" :href="route('customer.workspaces.businesses.crm.opportunities.create', [$workspaceUid, $businessUid, 'pipeline' => $pipeline->uid])" data-role="crm-add-opportunity">+ Add opportunity</x-button>
                    </div>
                @endif
            </div>

            <div class="d-flex gap-1 overflow-auto pb-1" data-role="crm-columns">
                @foreach ($board['columns'] as $column)
                    @php $isStage = $column['stage'] !== null; @endphp
                    <div class="flex-shrink-0 rounded bg-light-secondary p-1" style="width: 18rem;"
                         data-role="crm-column" data-column="{{ $column['key'] }}"
                         @if ($isStage && $canManage) data-drop-stage="{{ $column['stage']->uid }}" @endif>
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div>
                                <h2 class="h6 mb-0" data-role="crm-column-name">{{ $column['name'] }}</h2>
                                <span class="text-caption" data-role="crm-column-total">
                                    {{ $column['count'] }}@if ($column['value_minor'] > 0) · {{ \App\Library\Crm\CrmMoney::format($column['value_minor'], $board['currency']) }}@endif
                                </span>
                            </div>
                        </div>

                        <div class="d-flex flex-column gap-1" data-role="crm-cards" style="min-height: 3rem;">
                            @forelse ($column['cards'] as $card)
                                @php $isOpen = $card['status'] === \App\Enums\Crm\CrmOpportunityStatus::Open; @endphp
                                <article class="card mb-0 shadow-none border" data-role="crm-card" data-uid="{{ $card['uid'] }}"
                                         @if ($isOpen && $canManage && $isStage) draggable="true" data-move-url="{{ route('customer.workspaces.businesses.crm.opportunities.move', [$workspaceUid, $businessUid, $card['uid']]) }}" @endif>
                                    <div class="card-body p-1">
                                        <a class="fw-bolder d-block" href="{{ route('customer.workspaces.businesses.crm.opportunities.show', [$workspaceUid, $businessUid, $card['uid']]) }}" data-role="crm-card-title">{{ $card['title'] }}</a>
                                        @if ($card['contact'] !== null)
                                            <a class="text-caption d-block" href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $card['contact']['uid']]) }}" data-role="crm-card-contact">{{ $card['contact']['name'] ?? $card['contact']['phone'] }}</a>
                                        @else
                                            <span class="text-caption d-block text-muted">Contact removed</span>
                                        @endif
                                        <div class="d-flex flex-wrap align-items-center gap-50 mt-50">
                                            <x-badge :variant="$card['contact_status'] === \App\Enums\Crm\CrmContactStatus::InContact ? 'success' : 'warning'" data-role="crm-card-contact-status">{{ $card['contact_status']->label() }}</x-badge>
                                            @unless ($isOpen)
                                                <x-badge :variant="$card['status'] === \App\Enums\Crm\CrmOpportunityStatus::Won ? 'success' : 'danger'" data-role="crm-card-status">{{ $card['status']->label() }}</x-badge>
                                            @endunless
                                            @if ($card['value'] !== null)
                                                <span class="text-caption text-numeric ms-auto">{{ $card['value'] }}</span>
                                            @endif
                                        </div>
                                        @if ($card['stage_entered_at'])
                                            <span class="text-caption text-muted d-block mt-50">In stage {{ $card['stage_entered_at']->diffForHumans(null, true) }}</span>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <p class="text-caption text-muted mb-0" data-role="crm-column-empty">No opportunities</p>
                            @endforelse

                            @if ($column['count'] > count($column['cards']))
                                <p class="text-caption mb-0">+ {{ $column['count'] - count($column['cards']) }} more — narrow the search to see them</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection

@section('page-script')
    <script>
        (function () {
            // Choosing a pipeline shows it straight away (the Apply button stays
            // for the other filters and for keyboard users).
            document.addEventListener('change', function (event) {
                if (event.target && event.target.matches('[data-role="crm-pipeline-select"]')) {
                    event.target.form.requestSubmit ? event.target.form.requestSubmit() : event.target.form.submit();
                }
            });

            var token = @json(csrf_token());
            var dragged = null;

            function refreshBoard() {
                if (window.AsyncRegion && window.AsyncRegion.supported) {
                    window.AsyncRegion.load(['crm-board'], window.location.href, { history: 'none' });
                } else {
                    window.location.reload();
                }
            }

            document.addEventListener('dragstart', function (event) {
                var card = event.target.closest && event.target.closest('[data-role="crm-card"][data-move-url]');
                if (!card) { return; }
                dragged = { card: card, from: card.parentNode, next: card.nextSibling };
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', card.getAttribute('data-uid'));
                card.classList.add('opacity-50');
            });

            document.addEventListener('dragend', function () {
                if (dragged) { dragged.card.classList.remove('opacity-50'); }
                document.querySelectorAll('[data-drop-stage].border-primary').forEach(function (el) { el.classList.remove('border', 'border-primary'); });
            });

            document.addEventListener('dragover', function (event) {
                var column = dragged && event.target.closest && event.target.closest('[data-drop-stage]');
                if (!column) { return; }
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                column.classList.add('border', 'border-primary');
            });

            document.addEventListener('dragleave', function (event) {
                var column = event.target.closest && event.target.closest('[data-drop-stage]');
                if (column && !column.contains(event.relatedTarget)) { column.classList.remove('border', 'border-primary'); }
            });

            document.addEventListener('drop', function (event) {
                var column = dragged && event.target.closest && event.target.closest('[data-drop-stage]');
                if (!column) { return; }
                event.preventDefault();

                var move = dragged;
                dragged = null;
                column.classList.remove('border', 'border-primary');

                if (move.from.closest('[data-drop-stage]') === column) { return; }

                // Show the move at once; put the card back if the server refuses.
                column.querySelector('[data-role="crm-cards"]').prepend(move.card);

                fetch(move.card.getAttribute('data-move-url'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify({ stage: column.getAttribute('data-drop-stage') })
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (body) {
                        if (!response.ok) { throw new Error(body.message || 'That move could not be saved.'); }
                    });
                }).then(refreshBoard, function (error) {
                    move.from.insertBefore(move.card, move.next);
                    if (window.toastr) { window.toastr.error(error.message); }
                });
            });
        })();
    </script>
@endsection
