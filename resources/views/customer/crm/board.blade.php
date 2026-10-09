@extends('layouts/contentLayoutMaster')

{{--
    CRM Opportunities — the board for one pipeline (CrmBoard).

    The toolbar (pipeline, search, status, contact status) is an ordinary GET
    form; with JavaScript it updates the board region in place
    (window.AsyncRegion) and keeps the address bar in step, so refresh, bookmarks
    and Back/Forward show the same board. It sits outside the region so what
    someone types is never replaced while results load.

    Visual order is header -> filter bar -> board. The region holds the header
    (title, summary, actions — all of which follow the pipeline being shown) and
    the board, and is `display: contents` so the filter bar can sit between them
    by `order` without being inside what gets replaced. "Customize stages" lives in
    the filter bar, so a few lines of script keep its address in step with the
    chosen pipeline.

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
        #crm-opportunities { display: flex; flex-direction: column; gap: var(--space-3, .75rem); min-width: 0; }
        #crm-opportunities [data-role="crm-board"] { display: contents; }
        #crm-opportunities .crm-head { order: 1; display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-3, .75rem); }
        #crm-opportunities .crm-filters { order: 2; }
        #crm-opportunities .crm-board-wrap { order: 3; min-width: 0; }
        #crm-opportunities .crm-summary { margin: 0; font-size: .875rem; color: var(--color-text-muted, #6F6D67); }
        #crm-opportunities .crm-summary strong { color: var(--color-text-primary, #1F1E1B); font-weight: 600; }
        #crm-opportunities .crm-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2, .5rem); }
        #crm-opportunities .crm-new-pipeline-panel { z-index: 5; min-width: 18rem; }

        /* ---- filter bar: one compact card ---- */
        #crm-opportunities .crm-filters-card { padding: .75rem 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .625rem; background: var(--color-surface, #fff); }
        #crm-opportunities .crm-filter-grid { display: grid; grid-template-columns: minmax(10rem, 1.1fr) minmax(12rem, 1.6fr) minmax(7rem, .7fr) minmax(8rem, .8fr) auto; gap: .625rem .75rem; align-items: end; }
        #crm-opportunities .crm-filter-grid .form-label { margin-bottom: .25rem; font-size: .75rem; }
        #crm-opportunities .crm-filter-buttons { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .75rem; }
        #crm-opportunities .crm-customize { font-size: .8125rem; white-space: nowrap; }
        @media (max-width: 991.98px) {
            #crm-opportunities .crm-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            #crm-opportunities .crm-filter-search, #crm-opportunities .crm-filter-buttons { grid-column: 1 / -1; }
        }
        @media (max-width: 575.98px) {
            #crm-opportunities .crm-head { flex-direction: column; align-items: stretch; }
            #crm-opportunities .crm-actions .btn, #crm-opportunities .crm-actions details { flex: 1 1 auto; }
            #crm-opportunities .crm-actions details > summary { text-align: center; }
            #crm-opportunities .crm-new-pipeline-panel { right: auto; left: 0; min-width: 0; width: 100%; }
        }

        /* ---- board: consistent columns, intentional sideways scroll ---- */
        #crm-opportunities .crm-columns { display: flex; align-items: flex-start; gap: var(--space-3, .75rem); overflow-x: auto; padding-bottom: .75rem; scroll-snap-type: x proximity; scrollbar-width: thin; scrollbar-color: var(--color-border-strong, #C9C5BC) transparent; }
        #crm-opportunities .crm-columns::-webkit-scrollbar { height: 8px; }
        #crm-opportunities .crm-columns::-webkit-scrollbar-track { background: transparent; }
        #crm-opportunities .crm-columns::-webkit-scrollbar-thumb { background: var(--color-border-strong, #C9C5BC); border-radius: 999px; }
        #crm-opportunities .crm-columns::-webkit-scrollbar-thumb:hover { background: var(--color-text-muted, #6F6D67); }
        #crm-opportunities .crm-column { --crm-tone: var(--color-primary, #4B5FD6); flex: 0 0 17.5rem; width: 17.5rem; scroll-snap-align: start; background: var(--color-surface-secondary, #F7F5F1); border: 1px solid var(--color-border-subtle, #EEEBE5); border-top: 3px solid var(--crm-tone); border-radius: var(--radius-md, 8px); padding: .5rem; transition: background-color 120ms ease; }
        #crm-opportunities .crm-column[data-tone="1"] { --crm-tone: var(--color-status-info-icon, #2B7BB9); }
        #crm-opportunities .crm-column[data-tone="2"] { --crm-tone: var(--color-status-warning-icon, #B7791F); }
        #crm-opportunities .crm-column[data-tone="3"] { --crm-tone: var(--color-status-success-icon, #2E7D4F); }
        #crm-opportunities .crm-column[data-tone="4"] { --crm-tone: var(--color-text-muted, #6F6D67); }
        #crm-opportunities .crm-column.is-drop-target { background: var(--color-primary-soft-bg, #e8f0fe); box-shadow: inset 0 0 0 2px var(--color-primary, #4f46e5); }
        #crm-opportunities .crm-column-head { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: .125rem .5rem; padding: .25rem .25rem .625rem; }
        #crm-opportunities .crm-column-name { font-size: .875rem; font-weight: 600; margin: 0; line-height: 1.3; overflow-wrap: anywhere; }
        #crm-opportunities .crm-column-count { min-width: 1.5rem; padding: .0625rem .5rem; border-radius: 999px; background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); font-size: .75rem; font-weight: 600; text-align: center; font-variant-numeric: tabular-nums; color: var(--color-text-secondary, #4A4944); }
        #crm-opportunities .crm-column-total { grid-column: 1 / -1; font-size: .8125rem; font-weight: 600; color: var(--color-text-secondary, #4A4944); font-variant-numeric: tabular-nums; }
        #crm-opportunities .crm-column-total:empty::before { content: "\00a0"; }
        #crm-opportunities .crm-cards { display: flex; flex-direction: column; gap: .5rem; min-height: 3rem; }
        #crm-opportunities .crm-card { position: relative; margin: 0; padding: .625rem .75rem; background: var(--color-surface, #fff); border: 1px solid var(--color-border-subtle, #e5e7eb); border-radius: 8px; transition: border-color 120ms ease, box-shadow 120ms ease; }
        #crm-opportunities .crm-card[draggable="true"] { cursor: grab; }
        #crm-opportunities .crm-card[draggable="true"]:active { cursor: grabbing; }
        #crm-opportunities .crm-card:hover { border-color: var(--color-border, #d1d5db); box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
        #crm-opportunities .crm-card.is-dragging { opacity: .4; }
        #crm-opportunities .crm-card-title { display: block; font-size: .875rem; font-weight: 600; line-height: 1.35; color: var(--color-text-primary, inherit); overflow-wrap: anywhere; }
        #crm-opportunities .crm-card-contact { display: block; margin-top: .125rem; font-size: .8125rem; color: var(--color-text-muted, #6b7280); overflow-wrap: anywhere; }
        #crm-opportunities .crm-card-foot { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; margin-top: .5rem; font-size: .75rem; }
        #crm-opportunities .crm-card-value { margin-left: auto; font-size: 1rem; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; color: var(--color-text-primary, #1F1E1B); }
        #crm-opportunities .crm-card-age { display: block; margin-top: .375rem; font-size: .6875rem; color: var(--color-text-muted, #6b7280); }
        #crm-opportunities [data-role="crm-column-empty"] { margin: 0; padding: .875rem .5rem; border: 1px dashed var(--color-border, #E5E1DA); border-radius: 8px; text-align: center; font-size: .75rem; color: var(--color-text-muted, #6F6D67); }
        /* "Saving" shows only if a save is slow: a dot that fades in after .6s, so a fast save shows nothing at all. */
        #crm-opportunities .crm-card.is-saving::after { content: ""; position: absolute; top: .5rem; right: .5rem; width: .4375rem; height: .4375rem; border-radius: 50%; background: var(--color-primary, #4f46e5); opacity: 0; animation: crm-saving 1s ease-in-out .6s infinite alternate; }
        #crm-opportunities .crm-drop-indicator { height: 3px; border-radius: 2px; background: var(--color-primary, #4f46e5); flex: 0 0 auto; }
        @keyframes crm-saving { from { opacity: .25; } to { opacity: 1; } }
        @media (max-width: 575.98px) {
            #crm-opportunities .crm-column { flex-basis: 16rem; width: 16rem; }
        }
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
        $boardValueMinor = (int) collect($board['columns'])->sum('value_minor');
        $statusWord = ['open' => ' open', 'won' => ' won', 'lost' => ' lost'][$filters->status] ?? '';
    @endphp

    <section id="crm-opportunities" data-csrf="{{ csrf_token() }}">
        <div data-async-region="crm-board" data-role="crm-board" data-pipeline="{{ $pipeline->uid }}" data-currency="{{ $board['currency'] }}">
            <div class="crm-head">
                <div>
                    <h1 class="h3 mb-25">Opportunities</h1>
                    <p class="crm-summary" data-role="crm-board-summary">
                        <strong>{{ $board['total'] }}{{ $statusWord }}</strong> in {{ $pipeline->name }}
                        · {{ \App\Library\Crm\CrmMoney::format($boardValueMinor, $board['currency']) }} total
                        @if (! $filters->isDefault()) · matching these filters @endif
                    </p>
                </div>

                @if ($canManage)
                    <div class="crm-actions" data-role="crm-actions">
                        <details class="position-relative" data-role="crm-new-pipeline">
                            <summary class="btn btn-outline-secondary d-inline-flex align-items-center gap-1">New pipeline</summary>
                            <form method="POST" action="{{ route('customer.workspaces.businesses.crm.pipelines.store', [$workspaceUid, $businessUid]) }}"
                                  class="card card-body position-absolute end-0 mt-50 shadow crm-new-pipeline-panel">
                                @csrf
                                <label for="crm-new-pipeline-name" class="form-label text-label">Pipeline name</label>
                                <input id="crm-new-pipeline-name" name="name" class="form-control mb-1" maxlength="100" required placeholder="e.g. Weddings">
                                <p class="text-caption mb-1">Starts with the standard stages; adjust them next.</p>
                                <x-button type="submit" variant="primary" size="sm">Create pipeline</x-button>
                            </form>
                        </details>
                        <x-button variant="primary" icon="plus" :href="route('customer.workspaces.businesses.crm.opportunities.create', [$workspaceUid, $businessUid, 'pipeline' => $pipeline->uid])" data-role="crm-add-opportunity">New opportunity</x-button>
                    </div>
                @endif
            </div>

            <div class="crm-board-wrap">
                <span class="visually-hidden" role="status" aria-live="polite" data-role="crm-board-status"></span>

                <div class="crm-columns" data-role="crm-columns">
                    @foreach ($board['columns'] as $column)
                        @php
                            $isStage = $column['stage'] !== null;
                            $columnTotal = $column['value_minor'] > 0 ? \App\Library\Crm\CrmMoney::format($column['value_minor'], $board['currency']) : '';
                        @endphp
                        <div class="crm-column"
                             data-role="crm-column" data-column="{{ $column['key'] }}" data-tone="{{ $loop->index % 5 }}"
                             data-count="{{ $column['count'] }}" data-value-minor="{{ $column['value_minor'] }}"
                             @if ($isStage && $canManage) data-drop-stage="{{ $column['stage']->uid }}" @endif>
                            <div class="crm-column-head">
                                <h2 class="crm-column-name" data-role="crm-column-name">{{ $column['name'] }}</h2>
                                <span class="crm-column-count" data-role="crm-column-count">{{ $column['count'] }}</span>
                                <span class="crm-column-total" data-role="crm-column-total">{{ $columnTotal }}</span>
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
        </div>

        <form method="GET" action="{{ $boardUrl }}" class="crm-filters" role="search" data-role="crm-filters" data-async-form="crm-board">
            <div class="crm-filters-card">
                <div class="crm-filter-grid">
                    <div>
                        <label for="crm-pipeline" class="form-label text-label">Pipeline</label>
                        <select id="crm-pipeline" name="pipeline" class="form-select" data-role="crm-pipeline-select">
                            @foreach ($pipelines as $option)
                                <option value="{{ $option->uid }}" data-settings-url="{{ route('customer.workspaces.businesses.crm.pipelines.settings', [$workspaceUid, $businessUid, $option->uid]) }}" @selected($option->is($pipeline))>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-filter-search">
                        <label for="crm-search" class="form-label text-label">Search</label>
                        <x-search-field id="crm-search" name="q" :value="$filters->search" label="Search opportunities" placeholder="Name, contact or phone" />
                    </div>
                    <div>
                        <label for="crm-status" class="form-label text-label">Status</label>
                        <select id="crm-status" name="status" class="form-select">
                            @foreach (['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'all' => 'All'] as $value => $label)
                                <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="crm-contact-status" class="form-label text-label">Contact status</label>
                        <select id="crm-contact-status" name="contact_status" class="form-select">
                            @foreach (['any' => 'Any', 'no_contact' => 'No contact', 'in_contact' => 'In contact'] as $value => $label)
                                <option value="{{ $value }}" @selected($filters->contactStatus === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="crm-filter-buttons">
                        <x-button type="submit" variant="outline">Apply</x-button>
                        @unless ($filters->isDefault())
                            <x-button variant="ghost" :href="$boardUrl . '?pipeline=' . $pipeline->uid">Clear</x-button>
                        @endunless
                        @if ($canManage)
                            <a class="crm-customize" href="{{ route('customer.workspaces.businesses.crm.pipelines.settings', [$workspaceUid, $businessUid, $pipeline->uid]) }}" data-role="crm-pipeline-settings">Customize stages</a>
                        @endif
                    </div>
                </div>
            </div>
        </form>
    </section>
@endsection

@section('page-script')
    <script>
        // Choosing a pipeline shows it straight away (the Apply button stays for the
        // other filters and for keyboard users).
        document.addEventListener('change', function (event) {
            if (event.target && event.target.matches('[data-role="crm-pipeline-select"]')) {
                syncCustomizeLink();
                event.target.form.requestSubmit ? event.target.form.requestSubmit() : event.target.form.submit();
            }
        });

        // "Customize stages" sits in the filter bar, outside the replaced region: keep it on the chosen pipeline.
        function syncCustomizeLink() {
            var select = document.querySelector('[data-role="crm-pipeline-select"]');
            var link = document.querySelector('[data-role="crm-pipeline-settings"]');
            var option = select && select.options[select.selectedIndex];

            if (link && option && option.getAttribute('data-settings-url')) {
                link.setAttribute('href', option.getAttribute('data-settings-url'));
            }
        }
        document.addEventListener('async-region:updated', syncCustomizeLink);
    </script>
    @foreach (['board-moves', 'board'] as $script)
        <script src="{{ asset('js/crm/' . $script . '.js') }}?v={{ filemtime(public_path('js/crm/' . $script . '.js')) }}"></script>
    @endforeach
@endsection
