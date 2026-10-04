@extends('layouts/contentLayoutMaster')

{{--
    CRM Opportunities — the board for one pipeline (CrmBoard).

    The toolbar (pipeline, search, status, contact status) is an ordinary GET
    form; with JavaScript it updates the board region in place
    (window.AsyncRegion) and keeps the address bar in step, so refresh, bookmarks
    and Back/Forward show the same board. It sits outside the region so what
    someone types is never replaced while results load.

    Dragging an open card onto another column moves it. The browser shows the move
    at once and saves it in the background (public/js/crm/board.js + board-moves.js);
    the board is never re-fetched or re-rendered because of a move. The server
    endpoint is the same one, with the same rules, as the move form on the
    opportunity's own page, which is the keyboard and no-JavaScript way to move one.

    Each column carries its own count and value (data-count / data-value-minor) and
    each card its value (data-value-minor), so the headers can be corrected from the
    moved card without parsing text.
--}}

@section('title', 'Opportunities')

@section('page-style')
    <style>
        #crm-opportunities .crm-columns { display: flex; align-items: flex-start; gap: var(--space-3, .75rem); overflow-x: auto; padding-bottom: var(--space-2, .5rem); }
        #crm-opportunities .crm-column { flex: 1 0 17rem; max-width: 18.75rem; background: var(--color-surface-secondary, #f3f4f6); border-radius: var(--radius-md, 8px); padding: .5rem; transition: background-color 120ms ease; }
        #crm-opportunities .crm-column.is-drop-target { background: var(--color-primary-soft-bg, #e8f0fe); box-shadow: inset 0 0 0 2px var(--color-primary, #4f46e5); }
        #crm-opportunities .crm-column-head { padding: .125rem .25rem .5rem; }
        #crm-opportunities .crm-column-name { font-size: .8125rem; font-weight: 600; margin: 0; line-height: 1.3; }
        #crm-opportunities .crm-column-total { font-size: .75rem; color: var(--color-text-muted, #6b7280); font-variant-numeric: tabular-nums; }
        #crm-opportunities .crm-cards { display: flex; flex-direction: column; gap: .375rem; min-height: 3rem; }
        #crm-opportunities .crm-card { position: relative; margin: 0; padding: .5rem .625rem; background: var(--color-surface, #fff); border: 1px solid var(--color-border-subtle, #e5e7eb); border-radius: 6px; transition: border-color 120ms ease, box-shadow 120ms ease; }
        #crm-opportunities .crm-card[draggable="true"] { cursor: grab; }
        #crm-opportunities .crm-card[draggable="true"]:active { cursor: grabbing; }
        #crm-opportunities .crm-card:hover { border-color: var(--color-border, #d1d5db); box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
        #crm-opportunities .crm-card.is-dragging { opacity: .4; }
        #crm-opportunities .crm-card-title { display: block; font-size: .8125rem; font-weight: 600; line-height: 1.35; color: var(--color-text-primary, inherit); overflow-wrap: anywhere; }
        #crm-opportunities .crm-card-contact { display: block; font-size: .75rem; color: var(--color-text-muted, #6b7280); overflow-wrap: anywhere; }
        #crm-opportunities .crm-card-foot { display: flex; align-items: center; gap: .375rem; margin-top: .375rem; font-size: .75rem; }
        #crm-opportunities .crm-card-value { margin-left: auto; font-weight: 600; font-variant-numeric: tabular-nums; }
        #crm-opportunities .crm-card-age { display: block; margin-top: .125rem; font-size: .6875rem; color: var(--color-text-muted, #6b7280); }
        /* "Saving" shows only if a save is slow: a dot that fades in after .6s, so a fast save shows nothing at all. */
        #crm-opportunities .crm-card.is-saving::after { content: ""; position: absolute; top: .5rem; right: .5rem; width: .4375rem; height: .4375rem; border-radius: 50%; background: var(--color-primary, #4f46e5); opacity: 0; animation: crm-saving 1s ease-in-out .6s infinite alternate; }
        #crm-opportunities .crm-drop-indicator { height: 3px; border-radius: 2px; background: var(--color-primary, #4f46e5); flex: 0 0 auto; }
        @keyframes crm-saving { from { opacity: .25; } to { opacity: 1; } }
        @media (prefers-reduced-motion: reduce) {
            #crm-opportunities .crm-column, #crm-opportunities .crm-card { transition: none; }
            #crm-opportunities .crm-card.is-saving::after { animation: none; opacity: 1; }
        }
    </style>
@endsection

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
        $boardUrl = route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid]);
        $canManage = Gate::allows(\App\Http\Controllers\Customer\Business\CrmOpportunitiesController::MANAGE_PERMISSION);
    @endphp

    <section id="crm-opportunities" data-csrf="{{ csrf_token() }}">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
            <h1 class="h3 mb-0">Opportunities</h1>

            @if ($canManage)
                <div class="d-flex flex-wrap gap-1" data-role="crm-actions">
                    <details class="position-relative" data-role="crm-new-pipeline">
                        <summary class="btn btn-outline-primary btn-sm">New pipeline</summary>
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

        <form method="GET" action="{{ $boardUrl }}" class="row g-1 align-items-end mb-1" role="search" data-role="crm-filters" data-async-form="crm-board">
            <div class="col-lg-3 col-md-4 col-sm-6">
                <label for="crm-pipeline" class="form-label text-label">Pipeline</label>
                <select id="crm-pipeline" name="pipeline" class="form-select" data-role="crm-pipeline-select">
                    @foreach ($pipelines as $option)
                        <option value="{{ $option->uid }}" @selected($option->is($pipeline))>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <x-search-field id="crm-search" name="q" :value="$filters->search" label="Search opportunities" placeholder="Search by name, contact or phone" />
            </div>
            <div class="col-lg-2 col-md-4 col-sm-4">
                <label for="crm-status" class="form-label text-label">Status</label>
                <select id="crm-status" name="status" class="form-select">
                    @foreach (['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'all' => 'All'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-4">
                <label for="crm-contact-status" class="form-label text-label">Contact status</label>
                <select id="crm-contact-status" name="contact_status" class="form-select">
                    @foreach (['any' => 'Any', 'no_contact' => 'No contact', 'in_contact' => 'In contact'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters->contactStatus === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-4 d-flex gap-1">
                <x-button type="submit" variant="outline">Apply</x-button>
                @unless ($filters->isDefault())
                    <x-button variant="ghost" :href="$boardUrl . '?pipeline=' . $pipeline->uid">Clear</x-button>
                @endunless
            </div>
        </form>

        <div data-async-region="crm-board" data-role="crm-board" data-pipeline="{{ $pipeline->uid }}" data-currency="{{ $board['currency'] }}">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
                <p class="text-caption mb-0" data-role="crm-board-summary">
                    {{ $board['total'] }} {{ \Illuminate\Support\Str::plural('opportunity', $board['total']) }}
                    in {{ $pipeline->name }}@if (! $filters->isDefault()) matching these filters @endif
                </p>
                @if ($canManage)
                    {{-- Inside the region: both follow the pipeline being shown. --}}
                    <div class="d-flex align-items-center gap-1">
                        <a href="{{ route('customer.workspaces.businesses.crm.pipelines.settings', [$workspaceUid, $businessUid, $pipeline->uid]) }}" data-role="crm-pipeline-settings">Customize stages</a>
                        <x-button variant="primary" :href="route('customer.workspaces.businesses.crm.opportunities.create', [$workspaceUid, $businessUid, 'pipeline' => $pipeline->uid])" data-role="crm-add-opportunity">+ New opportunity</x-button>
                    </div>
                @endif
            </div>

            <span class="visually-hidden" role="status" aria-live="polite" data-role="crm-board-status"></span>

            <div class="crm-columns" data-role="crm-columns">
                @foreach ($board['columns'] as $column)
                    @php $isStage = $column['stage'] !== null; @endphp
                    <div class="crm-column"
                         data-role="crm-column" data-column="{{ $column['key'] }}"
                         data-count="{{ $column['count'] }}" data-value-minor="{{ $column['value_minor'] }}"
                         @if ($isStage && $canManage) data-drop-stage="{{ $column['stage']->uid }}" @endif>
                        <div class="crm-column-head">
                            <h2 class="crm-column-name" data-role="crm-column-name">{{ $column['name'] }}</h2>
                            <span class="crm-column-total" data-role="crm-column-total">{{ \App\Library\Crm\CrmBoard::totalLabel($column['count'], $column['value_minor'], $board['currency']) }}</span>
                        </div>

                        <div class="crm-cards" data-role="crm-cards">
                            @forelse ($column['cards'] as $card)
                                @php $isOpen = $card['status'] === \App\Enums\Crm\CrmOpportunityStatus::Open; @endphp
                                <article class="crm-card" data-role="crm-card" data-uid="{{ $card['uid'] }}" data-value-minor="{{ $card['value_minor'] }}"
                                         @if ($isOpen && $canManage && $isStage) draggable="true" data-move-url="{{ route('customer.workspaces.businesses.crm.opportunities.move', [$workspaceUid, $businessUid, $card['uid']]) }}" @endif>
                                    <a class="crm-card-title" href="{{ route('customer.workspaces.businesses.crm.opportunities.show', [$workspaceUid, $businessUid, $card['uid']]) }}" draggable="false" data-role="crm-card-title">{{ $card['title'] }}</a>
                                    @if ($card['contact'] !== null)
                                        <a class="crm-card-contact" href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $card['contact']['uid']]) }}" draggable="false" data-role="crm-card-contact">{{ $card['contact']['name'] ?? $card['contact']['phone'] }}</a>
                                    @else
                                        <span class="crm-card-contact">Contact removed</span>
                                    @endif
                                    <div class="crm-card-foot">
                                        <x-badge :variant="$card['contact_status'] === \App\Enums\Crm\CrmContactStatus::InContact ? 'success' : 'warning'" data-role="crm-card-contact-status">{{ $card['contact_status']->label() }}</x-badge>
                                        @unless ($isOpen)
                                            <x-badge :variant="$card['status'] === \App\Enums\Crm\CrmOpportunityStatus::Won ? 'success' : 'danger'" data-role="crm-card-status">{{ $card['status']->label() }}</x-badge>
                                        @endunless
                                        @if ($card['value'] !== null)
                                            <span class="crm-card-value">{{ $card['value'] }}</span>
                                        @endif
                                    </div>
                                    @if ($card['stage_entered_at'])
                                        <span class="crm-card-age" data-role="crm-card-age">In stage {{ $card['stage_entered_at']->diffForHumans(null, true) }}</span>
                                    @endif
                                </article>
                            @empty
                                <p class="text-caption text-muted mb-0" data-role="crm-column-empty">No opportunities</p>
                            @endforelse

                            @if ($column['count'] > count($column['cards']))
                                <p class="text-caption mb-0" data-role="crm-column-more">+ {{ $column['count'] - count($column['cards']) }} more — narrow the search to see them</p>
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
        // Choosing a pipeline shows it straight away (the Apply button stays for the
        // other filters and for keyboard users).
        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches('[data-role="crm-pipeline-select"]')) {
                event.target.form.requestSubmit ? event.target.form.requestSubmit() : event.target.form.submit();
            }
        });
    </script>
    @foreach (['board-moves', 'board'] as $script)
        <script src="{{ asset('js/crm/' . $script . '.js') }}?v={{ filemtime(public_path('js/crm/' . $script . '.js')) }}"></script>
    @endforeach
@endsection
