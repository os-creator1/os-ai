{{--
    Growth Center — Opportunities. The full, filterable list. Each card is the
    same card the Overview uses. Filters are plain GET links/selects (no JS);
    counts and rows are computed ONLY over what the actor may see.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Growth Center — Opportunities')

@section('page-style')
    @include('customer.business.growth._styles')
@endsection

@php
    $route = fn (array $extra = []) => route('customer.workspaces.businesses.growth.opportunities.index', [$workspaceUid, $businessUid] + []) . '?' . http_build_query(array_filter(array_merge($filters, ['state' => $state], $extra), fn ($v) => $v !== null && $v !== ''));
    $moduleLabels = ['crm' => 'CRM', 'conversations' => 'Conversations', 'documents' => 'Proposals & documents', 'payments' => 'Payments', 'calendar' => 'Calendar', 'website' => 'Website', 'seo' => 'SEO', 'citations' => 'Citations', 'reviews' => 'Reviews', 'automations' => 'Automations'];
    $chips = [
        'open' => 'All open',
        'high_impact' => 'High impact',
        'new' => 'New',
        'in_progress' => 'In progress',
        'snoozed' => 'Snoozed',
        'resolved' => 'Resolved',
        'dismissed' => 'Dismissed',
    ];
@endphp

@section('content')
    @include('customer.business.growth._header')

    <div class="gc">
        <div class="gc-chips" role="tablist" aria-label="Opportunity status" data-role="state-chips">
            @foreach($chips as $key => $label)
                <a class="gc-chip @if($state === $key) is-active @endif" href="{{ $route(['state' => $key, 'page' => null]) }}" data-state-filter="{{ $key }}" @if($state === $key) aria-current="true" @endif>
                    {{ $label }} <small>{{ $stateCounts[$key] ?? 0 }}</small>
                </a>
            @endforeach
        </div>

        <form method="GET" class="gc-filters" data-role="filters">
            <input type="hidden" name="state" value="{{ $state }}">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Search opportunities" aria-label="Search opportunities" data-role="search">
            <select name="category" class="form-select form-select-sm" aria-label="Category" data-role="filter-category">
                <option value="">All categories</option>
                @foreach($categories as $c)
                    <option value="{{ $c->value }}" @selected(($filters['category'] ?? '') === $c->value)>{{ $c->label() }}</option>
                @endforeach
            </select>
            <select name="module" class="form-select form-select-sm" aria-label="Source" data-role="filter-module">
                <option value="">All sources</option>
                @foreach($modules as $m)
                    <option value="{{ $m }}" @selected(($filters['module'] ?? '') === $m)>{{ $moduleLabels[$m] ?? ucfirst($m) }}</option>
                @endforeach
            </select>
            @if(count($locationOptions) > 1)
                <select name="location" class="form-select form-select-sm" aria-label="Location" data-role="filter-location">
                    <option value="">All locations</option>
                    @foreach($locationOptions as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($filters['location'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
            <x-button type="submit" variant="secondary" size="sm" data-role="apply-filters">Filter</x-button>
        </form>

        @if($items !== [])
            <div class="gc-list" data-role="opportunity-list">
                @foreach($items as $card)
                    @include('customer.business.growth._card', ['card' => $card, 'compact' => false])
                @endforeach
            </div>

            <div class="mt-2">{{ $paginator->links() }}</div>
        @else
            <div class="gc-panel text-center" data-role="empty-list">
                @if($state === 'open' && ($filters['q'] ?? '') === '' && ($filters['category'] ?? '') === '' && ($filters['module'] ?? '') === '')
                    <x-ds-icon name="check-circle" size="28" />
                    <p class="gc-section-title mt-1">You're in good shape.</p>
                    <p class="gc-empty-line">There is nothing open right now. Resolved and snoozed items are one tab away.</p>
                @else
                    <p class="gc-section-title">Nothing matches.</p>
                    <p class="gc-empty-line">Try a different status or clear the filters.</p>
                @endif
            </div>
        @endif
    </div>
@endsection
