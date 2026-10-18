{{--
    SEO → Content → Opportunities. Topics worth an article, computed from the Business's own services, packages,
    locations and niche strategy, grouped by the search intent each one already carries. There is NO search-volume,
    cost-per-click or competition figure anywhere: we do not have that data, so we do not show it. "AI draft" is a POST
    the owner submits; opening this page never calls the AI. The page filter is a real `?page=` link the tab router
    swaps (no inline scripts here). Escaped Blade output only.
--}}
@extends(request()->query('fragment') === '1' ? 'customer.business.seo.content._fragment' : 'customer.business.seo.content._frame')

@section('title', 'Content opportunities')
@section('content-active', 'opportunities')
@section('content-subtitle', 'Answer what customers ask before they book.')

@section('content-section')
    @php
        $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.seo.content.' . $name, array_merge([$workspaceUid, $businessUid], $extra));

        $all = collect($opportunities);
        $pageFilters = $all->filter(fn ($o) => ! empty($o['supports_page_uid']))
            ->groupBy('supports_page_uid')
            ->map(fn ($g) => ['title' => $g->first()['supports_page_title'], 'count' => $g->count()]);
        $current = (string) request()->query('page', '');
        $current = $pageFilters->has($current) ? $current : '';
        $shown = $current === '' ? $all : $all->where('supports_page_uid', $current);

        // One group per search intent the opportunities actually carry, in a fixed reading order.
        $groups = [
            'cost' => ['Cost & pricing', 'People ask what it costs before they enquire.', 'banknote', 'is-green'],
            'how_to' => ['How to choose', 'Practical advice builds trust before they pick a provider.', 'list-checks', 'is-blue'],
            'comparison' => ['Comparisons', 'Customers weigh their options before they book.', 'arrow-left-right', 'is-blue'],
            'planning' => ['Planning checklists', 'Planning questions come up long before booking.', 'clipboard-list', 'is-amber'],
            'ideas' => ['Ideas & inspiration', 'Customers look for ideas while they plan their event.', 'lightbulb', 'is-amber'],
            'guide' => ['Guides', 'Straightforward answers to questions customers already ask.', 'file-text', 'is-neutral'],
        ];
    @endphp

    @if($all->isEmpty())
        <x-card :padded="true" data-role="opportunities-empty">
            <h5 class="mb-50">Publish your website to see topic ideas</h5>
            <p class="text-caption mb-0">Topic ideas are built from your published pages, packages and service areas. Once your website is published they appear here.</p>
        </x-card>
    @else
        <div class="opp-toolbar">
            <nav class="opp-filters" aria-label="Filter opportunities" data-role="opportunity-filters">
                <a class="opp-filter {{ $current === '' ? 'is-active' : '' }}" href="{{ $route('opportunities') }}" data-content-local data-filter="all" @if($current === '') aria-current="true" @endif>All <span>{{ $all->count() }}</span></a>
                @foreach($pageFilters as $uid => $filter)
                    <a class="opp-filter {{ $current === $uid ? 'is-active' : '' }}" href="{{ $route('opportunities', ['page' => $uid]) }}" data-content-local data-filter="{{ $uid }}" @if($current === $uid) aria-current="true" @endif>{{ $filter['title'] }} <span>{{ $filter['count'] }}</span></a>
                @endforeach
            </nav>
            <p class="opp-trust" data-role="opportunities-note"><x-ds-icon name="lock" size="13" aria-hidden="true" /> AI drafts use your real packages and prices, and are never published without you.</p>
        </div>

        <div data-role="opportunities">
            @foreach($groups as $intent => [$groupTitle, $groupSub, $icon, $tone])
                @php($rows = $shown->where('search_intent', $intent))
                @continue($rows->isEmpty())
                <section class="opp-group" data-role="opportunity-group" data-intent="{{ $intent }}" aria-labelledby="opp-group-{{ $intent }}">
                    <header class="opp-group-head">
                        <span class="opp-group-icon {{ $tone }}" aria-hidden="true"><x-ds-icon name="{{ $icon }}" size="16" /></span>
                        <div>
                            <h5 id="opp-group-{{ $intent }}" class="opp-group-title">{{ $groupTitle }}</h5>
                            <p class="opp-group-sub">{{ $groupSub }}</p>
                        </div>
                    </header>
                    @foreach($rows as $opp)
                        <div class="opp-row" data-opportunity="{{ $opp['key'] }}">
                            <div class="opp-row-main">
                                <div class="opp-row-title">{{ $opp['title'] }}
                                    @if(in_array($opp['status'], ['draft', 'published'], true))
                                        @include('customer.business.seo.content._status_badge', ['state' => $opp['status']])
                                        @if($opp['status'] === 'draft')<span class="visually-hidden">Draft exists</span>@endif
                                    @endif
                                </div>
                                <div class="opp-row-meta">
                                    @if(! empty($opp['supports_page_title']))
                                        <span class="opp-meta-label">Supports</span><span class="opp-badge">{{ $opp['supports_page_title'] }}</span>
                                    @endif
                                    @if(count($opp['internal_links']) > 0)
                                        <span class="opp-meta-label">· links to</span>
                                        @foreach($opp['internal_links'] as $link)<span class="opp-link-chip">{{ $link['title'] }}</span>@endforeach
                                    @endif
                                </div>
                            </div>
                            <div class="opp-row-actions">@include('customer.business.seo.content._opportunity_actions', ['opp' => $opp, 'short' => true, 'small' => true])</div>
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>
    @endif
@endsection
