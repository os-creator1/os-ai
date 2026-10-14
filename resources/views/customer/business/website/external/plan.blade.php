@extends('customer.business.website.external.layout')

@section('external-content')
    @php
        use App\Library\ExternalSite\ExternalAuditPresenter as P;
        use App\Models\ExternalSitePlanItem as Tracked;

        $detailRoute = fn (int $id): string => route('customer.workspaces.businesses.website.external.page', [$workspaceUid, $businessUid, $id]);
        $sectionTitles = [
            'problem' => ['Confirmed technical problems', 'Things the crawler observed that are broken, contradictory or blocking. Fix these first.'],
            'improvement' => ['SEO improvements', 'Worthwhile changes to what search engines read on your pages. These improve how clearly your pages are described; they do not promise rankings.'],
            'opportunity' => ['Growth opportunities', 'Suggestions that could help customers find and contact you. They are ideas, not defects, and are labelled where they are an interpretation.'],
        ];
    @endphp

    @if($report === null || $plan === null)
        <x-card :padded="true" data-role="plan-empty">
            <p class="mb-0 text-muted">
                @if($earlier !== null) Your last check used an earlier version of the audit, so there is no improvement plan from it. Check again to get one.
                @else Your improvement plan is built from your first audit. Start an audit to see it. @endif
            </p>
        </x-card>
    @else
        <x-card :padded="true" class="mb-2" data-role="plan-intro">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                <div>
                    <h2 class="h4 mb-50">Website improvement plan</h2>
                    <p class="text-muted mb-0">{{ $plan['counts']['problems'] }} confirmed problem(s) &middot; {{ $plan['counts']['improvements'] }} SEO improvement(s) &middot; {{ $plan['counts']['opportunities'] }} growth opportunit{{ $plan['counts']['opportunities'] === 1 ? 'y' : 'ies' }} &middot; from the audit of {{ $report->finished_at?->toFormattedDateString() }}.</p>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" data-role="copy-plan" data-copy="{{ $plan['full_text'] }}" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy); this.textContent='Plan copied';">Copy the whole plan</button>
            </div>
            <p class="text-caption text-muted mt-1 mb-0">MotionGrove cannot change your website: every item says what to change and how, for your own editor or developer. Priorities come from what was observed and how important the page is (home, contact or booking pages first). No traffic, ranking or revenue is predicted. Mark an item <em>done</em> once you change it, then check again: it is confirmed only when the next complete check no longer finds it.</p>
        </x-card>

        @if($report->status === 'partial')
            <x-alert variant="warning" icon="alert-triangle" role="status" class="mb-2">This plan covers only what was checked. {{ P::finishReason($report->finish_reason, $report->coverage) }}</x-alert>
        @endif

        @if($plan['start_here'] !== [])
            <x-card :padded="true" class="mb-2" data-role="start-here">
                <p class="text-label mb-1">Start here</p>
                <ol class="mb-0">
                    @foreach($plan['start_here'] as $item)
                        <li data-role="start-item"><a href="#plan-{{ $item['rule_key'] }}">{{ $item['title'] }}</a> <x-badge :variant="P::priorityVariant($item['priority'])">{{ $item['priority_label'] }}</x-badge> <span class="text-caption text-muted">{{ $item['site_level'] ? 'whole site' : $item['page_count'].' '.($item['page_count'] === 1 ? 'page' : 'pages') }}</span></li>
                    @endforeach
                </ol>
            </x-card>
        @endif

        @foreach($sectionTitles as $kind => [$title, $blurb])
            @if($plan['sections'][$kind] !== [])
                <h2 class="h5 mt-2 mb-50" data-role="plan-section" data-kind="{{ $kind }}">{{ $title }}</h2>
                <p class="text-caption text-muted mb-1">{{ $blurb }}</p>

                @foreach($plan['sections'][$kind] as $item)
                    <x-card :padded="true" class="mb-2" id="plan-{{ $item['rule_key'] }}" data-role="plan-item" data-rule="{{ $item['rule_key'] }}" data-priority="{{ $item['priority'] }}" data-status="{{ $item['tracking']['status'] }}">
                        <div class="d-flex align-items-center flex-wrap gap-1 mb-1">
                            <x-badge :variant="P::priorityVariant($item['priority'])">{{ $item['priority_label'] }}</x-badge>
                            <x-badge variant="neutral">{{ $item['category_label'] }}</x-badge>
                            @if($item['confidence'] === 'heuristic')<x-badge variant="neutral" title="An interpretation of what was observed">Interpretation</x-badge>@endif
                            <h3 class="h5 mb-0" data-role="plan-title">{{ $item['title'] }}</h3>
                            <span class="text-caption text-muted">{{ $item['site_level'] ? 'whole site' : $item['page_count'].' '.($item['page_count'] === 1 ? 'page' : 'pages') }} &middot; typically {{ ['quick' => 'a quick change', 'moderate' => 'a moderate change', 'technical' => 'a technical change'][$item['effort']] ?? 'a change' }}</span>
                        </div>

                        <p class="mb-1" data-role="proposed-change"><strong>Proposed change.</strong> {{ $item['proposal'] }}</p>
                        <p class="mb-1" data-role="business-relevance"><strong>Why it matters for your business.</strong> {{ $item['relevance'] }}</p>

                        <p class="text-label mb-50">Affected URLs</p>
                        <ul class="mb-1" data-role="plan-urls">
                            @foreach(array_slice($item['urls'], 0, 12) as $u)
                                <li>
                                    @if($u['page_id'])<a href="{{ $detailRoute($u['page_id']) }}">{{ $u['path'] }}</a> <a class="text-caption" href="{{ $u['url'] }}" target="_blank" rel="noopener noreferrer">View page</a>@else <span class="fw-semibold">Whole website</span> @endif
                                    <span class="text-caption text-muted">&mdash; {{ $u['observed'] }}</span>
                                </li>
                            @endforeach
                            @if(count($item['urls']) > 12)<li class="text-muted">…and {{ count($item['urls']) - 12 }} more (see the <a href="{{ $auditUrl }}?rule={{ $item['rule_key'] }}#rule-{{ $item['rule_key'] }}">audit</a>).</li>@endif
                        </ul>

                        <details class="mb-1">
                            <summary class="fw-semibold" style="cursor:pointer">How to implement it</summary>
                            <ol class="mt-1 mb-1" data-role="plan-steps">@foreach($item['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                        </details>
                        <p class="mb-1" data-role="plan-verify"><strong>How we will verify it.</strong> {{ $item['verify'] }}</p>

                        {{-- the owner's own tracking --}}
                        <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.plan.update', [$workspaceUid, $businessUid, $item['item_key']]) }}" class="row g-1 align-items-end border-top pt-1" data-role="tracking-form">
                            @csrf
                            <input type="hidden" name="rule_key" value="{{ $item['rule_key'] }}">
                            <div class="col-md-4">
                                <label class="form-label" for="st-{{ $item['rule_key'] }}">Your progress</label>
                                <select class="form-select form-select-sm" id="st-{{ $item['rule_key'] }}" name="status">
                                    @foreach($statuses as $value => $label)<option value="{{ $value }}" @selected($item['tracking']['status'] === $value)>{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="nt-{{ $item['rule_key'] }}">Note (optional)</label>
                                <input class="form-control form-control-sm" id="nt-{{ $item['rule_key'] }}" name="note" maxlength="500" value="{{ $item['tracking']['note'] }}" placeholder="e.g. sent to our web designer on Friday">
                            </div>
                            <div class="col-md-3"><button type="submit" class="btn btn-sm btn-outline-primary w-100" data-role="save-tracking">Save progress</button></div>
                        </form>
                        @if($item['tracking']['still_present_after_done'])
                            <p class="text-caption text-warning mt-50 mb-0" data-role="still-present">You marked this done, but the latest check still found it. If you changed the site recently, check again.</p>
                        @endif

                        {{-- asking for help, without pretending MotionGrove controls the site --}}
                        <div class="d-flex flex-wrap gap-1 mt-1" data-role="help">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-role="copy-brief" data-copy="{{ $item['brief'] }}" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy); this.textContent='Copied for your developer';">Copy a brief for my web developer</button>
                            @if($item['mailto'])<a class="btn btn-sm btn-outline-secondary" data-role="email-help" href="{{ $item['mailto'] }}">Email this to my support contact</a>@endif
                        </div>
                    </x-card>
                @endforeach
            @endif
        @endforeach

        @if($plan['observations'] !== [])
            <h2 class="h5 mt-2 mb-50" data-role="plan-section" data-kind="info">Observations (nothing to fix on your site)</h2>
            @foreach($plan['observations'] as $obs)
                <x-card :padded="true" class="mb-2" data-role="observation" data-rule="{{ $obs['rule_key'] }}">
                    <h3 class="h6 mb-50">{{ $obs['title'] }} <span class="text-caption text-muted">&middot; {{ $obs['page_count'] }} {{ $obs['page_count'] === 1 ? 'page' : 'pages' }}</span></h3>
                    <p class="mb-50">{{ $obs['what'] }}</p>
                    <p class="text-caption text-muted mb-0">{{ $obs['why'] }} <a href="{{ $auditUrl }}?rule={{ $obs['rule_key'] }}#rule-{{ $obs['rule_key'] }}">See the pages</a>.</p>
                </x-card>
            @endforeach
        @endif

        @if($plan['verified'] !== [])
            <x-card :padded="true" class="mb-2" data-role="verified-fixes">
                <p class="text-label mb-1">Changes you made that the latest check confirms</p>
                <ul class="mb-0">@foreach($plan['verified'] as $v)<li data-role="verified">{{ $v['title'] }} @if($v['verified'])<x-badge variant="success">Confirmed fixed</x-badge>@else <x-badge variant="neutral">Not found; waiting for a complete check to confirm</x-badge>@endif</li>@endforeach</ul>
            </x-card>
        @endif

        @if($plan['counts']['items'] === 0)
            <x-card :padded="true" data-role="plan-clean"><p class="mb-0">There is nothing to change in this audit.</p></x-card>
        @endif
    @endif
@endsection
