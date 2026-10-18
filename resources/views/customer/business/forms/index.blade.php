@extends('layouts/contentLayoutMaster')

@section('title', 'Forms')

@section('page-style')
    @include('partials.cx._styles')
    <style>
        .forms-state { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem .625rem; margin-top: .3125rem; }
        .forms-state .cx-hint { margin: 0; }
    </style>
@endsection

@section('content')
    @php
        // Forms V1 — presentation only. Every authorization answer (tenancy,
        // capability, entitlement) was decided before this view rendered; every
        // control below posts to a route that runs the full chain again. A
        // public link is offered only for an Active form with an enabled direct
        // link at an active Location this actor may reach ($liveLinks).
        $scope = [$workspace->uid, $business->uid];
        $liveLinks = $liveLinks ?? collect();
        $createUrl = route('customer.workspaces.businesses.forms.create', $scope);
        $responsesUrl = route('customer.workspaces.businesses.forms.submissions.index', $scope);
    @endphp

    @include('customer.business.forms._messages')

    <header class="cx-page-head">
        <div>
            <h1 class="cx-page-title">Forms</h1>
            <p class="cx-page-sub">
                Questionnaires and lead forms for <strong>{{ $business->name }}</strong>. Build a form once, then offer it at the
                locations you choose.
            </p>
        </div>
        <div class="cx-page-actions">
            <x-button variant="secondary" icon="message-square" :href="$responsesUrl" data-role="forms-submissions">View all responses</x-button>
            <x-button variant="primary" icon="plus" :href="$createUrl" data-role="forms-add">New form</x-button>
        </div>
    </header>

    <div class="cx-callout mb-2">
        <x-ds-icon name="map-pin" size="15" aria-hidden="true" />
        <span class="cx-callout-text">Each response belongs to the location it came through.</span>
    </div>

    @if ($forms->isEmpty())
        <section class="cx-card">
            <div class="cx-card-body">
                <p class="mb-0 text-caption" data-role="forms-empty">No forms yet. Create your first form.</p>
            </div>
        </section>
    @else
        <h2 class="cx-card-title mb-1">Your forms <span class="cx-count ms-25">{{ $forms->count() }} {{ \Illuminate\Support\Str::plural('form', $forms->count()) }}</span></h2>

        <section class="cx-card">
            <ul class="cx-list" data-role="forms-list">
                @foreach ($forms as $form)
                    @php
                        $state = $form->lifecycle_state;
                        $links = $liveLinks->get($form->id, collect());
                        $editUrl = route('customer.workspaces.businesses.forms.edit', array_merge($scope, [$form->uid]));
                        $pill = match ($state) {
                            \App\Enums\Forms\FormLifecycleState::Active => 'is-success',
                            \App\Enums\Forms\FormLifecycleState::Inactive => 'is-warning',
                            default => '',
                        };
                    @endphp
                    <li class="cx-row" data-form="{{ $form->uid }}">
                        <div class="cx-row-main">
                            <span class="cx-tile {{ $state === \App\Enums\Forms\FormLifecycleState::Active ? '' : 'is-neutral' }}" aria-hidden="true">
                                <x-ds-icon name="clipboard-list" size="17" />
                            </span>
                            <span class="cx-row-text">
                                <a href="{{ $editUrl }}" class="cx-row-title">{{ $form->name }}</a>
                                <span class="forms-state">
                                    <span class="cx-pill has-dot {{ $pill }}" data-role="forms-state">{{ $state->label() }}</span>
                                    <span class="cx-hint" data-role="forms-readiness">
                                        @if ($state === \App\Enums\Forms\FormLifecycleState::Draft)
                                            Not public yet. Open it, choose where to offer it, then activate it.
                                        @elseif ($state === \App\Enums\Forms\FormLifecycleState::Inactive)
                                            Switched off, so it accepts no responses. Past responses stay readable.
                                        @elseif ($links->isEmpty())
                                            Active, but not offered at any of your locations yet.
                                        @else
                                            Live at {{ $links->count() }} {{ \Illuminate\Support\Str::plural('location', $links->count()) }}.
                                        @endif
                                    </span>
                                </span>
                            </span>
                        </div>
                        <div class="cx-row-actions">
                            <x-button variant="secondary" size="sm" :href="$responsesUrl.'?form='.$form->uid">Responses</x-button>
                            @if ($links->count() === 1)
                                <x-button variant="secondary" size="sm" icon="external-link" :href="route('public.forms.show', [$links->first()->uid])"
                                          target="_blank" rel="noopener" data-role="forms-open-live" aria-label="Open the live {{ $form->name }} form">View live</x-button>
                            @endif
                            <a href="{{ $editUrl }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" data-role="forms-open">
                                Open form <x-ds-icon name="chevron-right" size="14" aria-hidden="true" />
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <a href="{{ $createUrl }}" class="cx-add-row is-standalone"><x-ds-icon name="plus" size="15" aria-hidden="true" />New form</a>
@endsection
