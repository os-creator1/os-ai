{{--
    SEO → Content sub-navigation. Autopilot and Articles are the two places an owner works; the Content Plan and the
    Opportunities (topic ideas) are the manual, secondary path, shown after a divider and in a quieter style. Autopilot, the
    Content Plan and Opportunities are part of the SeoModule package; a Business without it sees Articles only (and the honest
    note on the Articles page). Escaped output only.

    Expects: $workspaceUid, $businessUid, $active (autopilot|plan|articles|opportunities), $withModule (bool).
--}}
@php
    $contentRoute = fn (string $name) => route('customer.workspaces.businesses.seo.content.' . $name, [$workspaceUid, $businessUid]);
@endphp
<ul class="nav nav-pills mb-2" data-role="content-tabs">
    @if($withModule)
        <li class="nav-item">
            <a class="nav-link {{ $active === 'autopilot' ? 'active' : '' }}" href="{{ $contentRoute('autopilot') }}" @if($active === 'autopilot') aria-current="page" @endif data-tab="autopilot">Autopilot</a>
        </li>
    @endif
    <li class="nav-item">
        <a class="nav-link {{ $active === 'articles' ? 'active' : '' }}" href="{{ $contentRoute('articles.index') }}" @if($active === 'articles') aria-current="page" @endif data-tab="articles">Articles</a>
    </li>
    @if($withModule)
        <li class="nav-item ms-1 ps-1 border-start" data-role="manual-topics">
            <a class="nav-link {{ $active === 'plan' ? 'active' : 'text-muted' }}" href="{{ $contentRoute('plan') }}" @if($active === 'plan') aria-current="page" @endif data-tab="plan">Content Plan</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $active === 'opportunities' ? 'active' : 'text-muted' }}" href="{{ $contentRoute('opportunities') }}" @if($active === 'opportunities') aria-current="page" @endif data-tab="opportunities">Opportunities</a>
        </li>
    @endif
</ul>
