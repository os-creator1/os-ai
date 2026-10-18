@extends('layouts/contentLayoutMaster')

{{--
    Automations — the workflow list, as one calm card per workflow.

    Props (the controller owns every fact shown here; this view never decides one):
      string $workspaceUid, $businessUid
      string $basePath          e.g. "/workspaces/{uid}/businesses/{uid}/automations/workflows"
      LengthAwarePaginator|iterable $workflows
          Each row: object|array with ->uid, ->name, ->status (draft|published|paused|archived),
          ->updated_at, optional ->id and ->scope_location_name (null = whole business).
      array  $counts            optional. status value => count over EVERYTHING the actor may see,
                                plus `total`. Falls back to counting the rows given.
      ?string $activeFilter     the status filter in force (a WorkflowStatus value), or null
      string  $searchTerm       the name search in force
      array   $issueCounts      workflow id => number of things blocking publish, from
                                WorkflowCompiler::validate() (only entries above zero)
      string  $timezone         the Business timezone the "last updated" time is shown in

    Only mechanically true canonical lifecycle states are shown — no conversion rate,
    no leads, no revenue, nothing this list cannot prove from the row itself.
--}}

@section('title', __('automations.v2.list.heading'))

@section('page-style')
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/automations-workflow-builder.css')) }}">
    <style>
        .wf-index__summary { color: var(--bs-secondary-color, #6e6b7b); margin: 0.25rem 0 0; }
        .wf-index__guidance { display: flex; gap: .625rem; align-items: flex-start; padding: .625rem .875rem; margin-bottom: 1rem; border: 1px solid rgba(var(--bs-warning-rgb, 255, 159, 67), .45); background: rgba(var(--bs-warning-rgb, 255, 159, 67), .1); border-radius: .5rem; font-size: .875rem; }
        .wf-index__guidance strong { font-weight: 600; }
        .wf-index__toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
        .wf-index__filters { display: inline-flex; flex-wrap: wrap; gap: .25rem; padding: .25rem; background: rgba(var(--bs-body-color-rgb, 75, 75, 75), .06); border-radius: .5rem; }
        .wf-index__filter { padding: .25rem .75rem; border-radius: .375rem; font-size: .8125rem; color: inherit; text-decoration: none; white-space: nowrap; }
        .wf-index__filter:hover { background: rgba(var(--bs-body-color-rgb, 75, 75, 75), .08); color: inherit; }
        .wf-index__filter.is-active { background: var(--bs-card-bg, #fff); box-shadow: 0 1px 2px rgba(0, 0, 0, .08); font-weight: 600; }
        .wf-index__search { flex: 0 1 18rem; min-width: 12rem; }
        .wf-index__list { display: flex; flex-direction: column; gap: .75rem; }
        .wfl-card { display: grid; grid-template-columns: minmax(0, 1fr) 11rem 13rem auto; align-items: center; gap: 1rem; padding: .875rem 1rem; background: var(--bs-card-bg, #fff); border: 1px solid var(--bs-border-color, #ebe9f1); border-radius: .625rem; }
        .wfl-card__main { display: flex; gap: .875rem; align-items: center; min-width: 0; }
        .wfl-card__icon { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: .5rem; background: rgba(var(--bs-primary-rgb), .1); color: var(--bs-primary); }
        .wfl-card__text { min-width: 0; }
        a.wfl-card__name { display: block; font-weight: 600; font-size: .9375rem; color: var(--bs-heading-color, #2f2b3d); text-decoration: none; overflow-wrap: anywhere; }
        a.wfl-card__name:hover { color: var(--bs-primary); }
        .wfl-card__badges { display: flex; flex-wrap: wrap; gap: .375rem; margin-top: .375rem; }
        .wfl-card__meta-label { display: block; font-size: .6875rem; letter-spacing: .04em; text-transform: uppercase; color: var(--bs-secondary-color, #6e6b7b); }
        .wfl-card__meta-value { display: block; font-size: .8125rem; }
        .wfl-card__dot { display: inline-block; width: .4375rem; height: .4375rem; margin-right: .3125rem; border-radius: 50%; background: currentColor; vertical-align: middle; opacity: .75; }
        .wf-index__new-bottom { display: flex; align-items: center; justify-content: center; gap: .375rem; margin-top: .75rem; padding: .875rem; border: 1px dashed var(--bs-border-color, #d8d6de); border-radius: .625rem; color: var(--bs-primary); text-decoration: none; font-size: .875rem; }
        .wf-index__new-bottom:hover { background: rgba(var(--bs-primary-rgb), .05); color: var(--bs-primary); }
        @media (max-width: 991.98px) {
            .wfl-card { grid-template-columns: minmax(0, 1fr) auto; row-gap: .75rem; }
            .wfl-card__main { grid-column: 1 / -1; }
            .wfl-card__scope, .wfl-card__updated { grid-row: 2; }
            .wfl-card__scope { grid-column: 1; }
            .wfl-card__updated { grid-column: 2; }
            .wfl-card__open { grid-column: 1 / -1; grid-row: 3; }
        }
        @media (max-width: 575.98px) {
            .wfl-card { grid-template-columns: 1fr 1fr; }
            .wfl-card__open .btn { width: 100%; justify-content: center; }
            .wf-index__search { flex: 1 1 100%; }
        }
    </style>
@endsection

@section('content')
    @php
        $rows = is_array($workflows) ? $workflows : (method_exists($workflows, 'items') ? $workflows->items() : iterator_to_array($workflows));
        $statusOf = fn ($w) => is_object($w->status ?? null) ? $w->status->value : ($w->status ?? 'draft');

        // Counts come from the controller (whole visible set). The fallback only serves a
        // caller that supplies rows alone.
        $counts = $counts ?? null;
        if ($counts === null) {
            $counts = ['draft' => 0, 'published' => 0, 'paused' => 0, 'archived' => 0];
            foreach ($rows as $row) {
                $counts[$statusOf($row)] = ($counts[$statusOf($row)] ?? 0) + 1;
            }
            $counts['total'] = array_sum($counts);
        }

        $total = (int) ($counts['total'] ?? 0);
        $live = (int) ($counts['published'] ?? 0);
        $drafts = (int) ($counts['draft'] ?? 0);
        $paused = (int) ($counts['paused'] ?? 0);
        $archived = (int) ($counts['archived'] ?? 0);

        $activeFilter = $activeFilter ?? null;
        $searchTerm = $searchTerm ?? '';
        $issueCounts = $issueCounts ?? [];
        $timezone = $timezone ?? config('app.timezone', 'UTC');
        $narrowed = $activeFilter !== null || $searchTerm !== '';

        $words = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten'];
        $word = fn (int $n) => $words[$n] ?? (string) $n;
        $newUrl = $basePath . '/new';
        $filterUrl = fn (?string $status) => $basePath . '?' . http_build_query(array_filter(['status' => $status, 'q' => $searchTerm !== '' ? $searchTerm : null]));
    @endphp

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3" data-role="wf-list-header">
        <div>
            <h4 class="mb-0">{{ __('automations.v2.list.heading') }}</h4>
            <p class="wf-index__summary" data-role="wf-list-summary">
                {{ __('automations.v2.list.subtitle') }}
                <strong class="text-body">{{ trans_choice('automations.v2.list.summary', $total) }}</strong>
                · {{ __('automations.v2.list.summary_live', ['count' => $live]) }}
                · {{ trans_choice('automations.v2.list.summary_drafts', $drafts) }}
                @if ($paused > 0) · {{ __('automations.v2.list.summary_paused', ['count' => $paused]) }} @endif
                @if ($archived > 0) · {{ __('automations.v2.list.summary_archived', ['count' => $archived]) }} @endif
            </p>
        </div>
        <x-button variant="primary" size="sm" icon="plus" href="{{ $newUrl }}" data-role="wf-new-workflow">
            {{ __('automations.v2.list.new_workflow') }}
        </x-button>
    </div>

    @if ($total === 0)
        <x-card>
            <x-empty-state icon="workflow" :title="__('automations.v2.list.empty_title')" :description="__('automations.v2.list.empty_summary_hint')">
                <x-slot:action>
                    <x-button variant="primary" size="sm" icon="plus" href="{{ $newUrl }}">
                        {{ __('automations.v2.list.new_workflow') }}
                    </x-button>
                </x-slot:action>
            </x-empty-state>
        </x-card>
    @else
        {{-- Guidance, not an error: only when workflows exist and none is running, and only
             while there is a draft to publish. --}}
        @if ($live === 0 && $drafts > 0)
            <div class="wf-index__guidance" role="status" data-role="wf-list-guidance">
                <x-ds-icon name="info" size="16" class="text-warning flex-shrink-0 mt-1" aria-hidden="true" />
                <div>
                    <strong>{{ __('automations.v2.list.guidance_title') }}</strong>
                    {{ trans_choice($drafts === $total ? 'automations.v2.list.guidance_all_drafts' : 'automations.v2.list.guidance_some_drafts', $drafts, ['count' => $word($drafts)]) }}
                    {{ __('automations.v2.list.guidance_action') }}
                </div>
            </div>
        @endif

        <div class="wf-index__toolbar" data-role="wf-list-toolbar">
            <nav class="wf-index__filters" aria-label="{{ __('automations.v2.list.filter_label') }}" data-role="wf-list-filters">
                <a href="{{ $filterUrl(null) }}" class="wf-index__filter @if ($activeFilter === null) is-active @endif" data-filter="all" @if ($activeFilter === null) aria-current="true" @endif>{{ __('automations.v2.list.filter_all') }} {{ $total }}</a>
                <a href="{{ $filterUrl('published') }}" class="wf-index__filter @if ($activeFilter === 'published') is-active @endif" data-filter="published" @if ($activeFilter === 'published') aria-current="true" @endif>{{ __('automations.v2.list.filter_live') }} {{ $live }}</a>
                <a href="{{ $filterUrl('draft') }}" class="wf-index__filter @if ($activeFilter === 'draft') is-active @endif" data-filter="draft" @if ($activeFilter === 'draft') aria-current="true" @endif>{{ __('automations.v2.list.filter_drafts') }} {{ $drafts }}</a>
                @if ($paused > 0 || $activeFilter === 'paused')
                    <a href="{{ $filterUrl('paused') }}" class="wf-index__filter @if ($activeFilter === 'paused') is-active @endif" data-filter="paused" @if ($activeFilter === 'paused') aria-current="true" @endif>{{ __('automations.v2.list.filter_paused') }} {{ $paused }}</a>
                @endif
                @if ($archived > 0 || $activeFilter === 'archived')
                    <a href="{{ $filterUrl('archived') }}" class="wf-index__filter @if ($activeFilter === 'archived') is-active @endif" data-filter="archived" @if ($activeFilter === 'archived') aria-current="true" @endif>{{ __('automations.v2.list.filter_archived') }} {{ $archived }}</a>
                @endif
            </nav>

            <form method="GET" action="{{ $basePath }}" class="wf-index__search" role="search" data-role="wf-list-search-form">
                @if ($activeFilter !== null)
                    <input type="hidden" name="status" value="{{ $activeFilter }}">
                @endif
                <x-search-field id="wf-list-search" name="q" :label="__('automations.v2.list.search_label')" :placeholder="__('automations.v2.list.search_placeholder')" :value="$searchTerm" type="text" />
            </form>
        </div>

        <div class="wf-index__list" data-role="wf-list-table">
            @foreach ($rows as $workflow)
                @php
                    $status = $statusOf($workflow);
                    $uid = is_array($workflow) ? $workflow['uid'] : $workflow->uid;
                    $name = is_array($workflow) ? $workflow['name'] : $workflow->name;
                    $workflowId = is_array($workflow) ? ($workflow['id'] ?? null) : ($workflow->id ?? null);
                    $issues = $workflowId !== null ? (int) ($issueCounts[(int) $workflowId] ?? 0) : 0;
                    $scopeName = is_array($workflow) ? ($workflow['scope_location_name'] ?? null) : ($workflow->scope_location_name ?? null);
                    $updatedAt = is_array($workflow) ? ($workflow['updated_at'] ?? null) : ($workflow->updated_at ?? null);
                    $openUrl = $basePath . '/' . $uid;
                @endphp
                <div class="wfl-card" data-role="wf-list-row" data-workflow-uid="{{ $uid }}" data-name="{{ mb_strtolower($name) }}">
                    <div class="wfl-card__main">
                        <span class="wfl-card__icon" aria-hidden="true"><x-ds-icon name="workflow" size="18" /></span>
                        <div class="wfl-card__text">
                            <a href="{{ $openUrl }}" class="wfl-card__name" data-role="wf-list-open">{{ $name }}</a>
                            <div class="wfl-card__badges">
                                @switch($status)
                                    @case('published')
                                        <x-badge variant="success"><span class="wfl-card__dot"></span>{{ __('automations.v2.list.status_live') }}</x-badge>
                                        @break
                                    @case('paused')
                                        <x-badge variant="warning"><span class="wfl-card__dot"></span>{{ __('automations.v2.list.status_paused') }}</x-badge>
                                        @break
                                    @case('archived')
                                        <x-badge variant="neutral"><span class="wfl-card__dot"></span>{{ __('automations.v2.list.status_archived') }}</x-badge>
                                        @break
                                    @default
                                        <x-badge variant="neutral"><span class="wfl-card__dot"></span>{{ __('automations.v2.list.status_draft') }}</x-badge>
                                @endswitch
                                @if ($issues > 0)
                                    <x-badge variant="warning" data-role="wf-list-issues">{{ trans_choice('automations.v2.list.issues', $issues) }}</x-badge>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="wfl-card__scope" data-role="wf-list-scope">
                        <span class="wfl-card__meta-label">{{ __('automations.v2.list.column_scope') }}</span>
                        <span class="wfl-card__meta-value">{{ $scopeName ?? __('automations.v2.list.scope_business') }}</span>
                    </div>
                    <div class="wfl-card__updated" data-role="wf-list-updated">
                        <span class="wfl-card__meta-label">{{ __('automations.v2.list.column_updated') }}</span>
                        <span class="wfl-card__meta-value">
                            @if ($updatedAt)
                                {{ \Illuminate\Support\Carbon::parse($updatedAt)->setTimezone($timezone)->format('M j, Y, g:i A') }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    <div class="wfl-card__open">
                        <a href="{{ $openUrl }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" data-role="wf-list-open-button">
                            {{ __('automations.v2.list.open') }}
                            <x-ds-icon name="chevron-right" size="14" aria-hidden="true" />
                        </a>
                    </div>
                </div>
            @endforeach

            <div class="text-center py-4 @if (count($rows) > 0) d-none @endif" data-role="wf-list-no-matches">
                <p class="fw-semibold mb-1">{{ __('automations.v2.list.no_matches_title') }}</p>
                <p class="text-muted mb-2">{{ __('automations.v2.list.no_matches_description') }}</p>
                @if ($narrowed)
                    <a href="{{ $basePath }}">{{ __('automations.v2.list.clear_filters') }}</a>
                @endif
            </div>
        </div>

        @if (is_object($workflows) && method_exists($workflows, 'links'))
            <div class="mt-3">
                {{ $workflows->links() }}
            </div>
        @endif

        <a href="{{ $newUrl }}" class="wf-index__new-bottom" data-role="wf-new-workflow-bottom">
            <x-ds-icon name="plus" size="16" aria-hidden="true" />
            {{ __('automations.v2.list.new_workflow') }}
        </a>

        {{-- Narrow the rows already on screen as the person types. Enter still submits the
             form, which asks the server — so a search also reaches workflows on other pages. --}}
        <script>
            (function () {
                var input = document.getElementById('wf-list-search');
                var rows = document.querySelectorAll('[data-role="wf-list-row"]');
                var none = document.querySelector('[data-role="wf-list-no-matches"]');
                if (!input || !rows.length) { return; }
                input.addEventListener('input', function () {
                    var term = input.value.trim().toLowerCase();
                    var shown = 0;
                    rows.forEach(function (row) {
                        var match = term === '' || (row.getAttribute('data-name') || '').indexOf(term) !== -1;
                        row.style.display = match ? '' : 'none';
                        if (match) { shown++; }
                    });
                    if (none) { none.classList.toggle('d-none', shown > 0); }
                });
            })();
        </script>
    @endif
@endsection
