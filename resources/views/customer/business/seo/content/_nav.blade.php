{{--
    SEO → Content sub-navigation. Content Plan and Opportunities are part of the SeoModule package; a
    Business without it sees Articles only (and the honest note on the Articles page). Escaped output only.

    Expects: $workspaceUid, $businessUid, $active (plan|articles|opportunities), $withModule (bool).
--}}
@php
    $contentRoute = fn (string $name) => route('customer.workspaces.businesses.seo.content.' . $name, [$workspaceUid, $businessUid]);
@endphp
<ul class="nav nav-pills mb-2" data-role="content-tabs">
    @if($withModule)
        <li class="nav-item">
            <a class="nav-link {{ $active === 'plan' ? 'active' : '' }}" href="{{ $contentRoute('plan') }}" @if($active === 'plan') aria-current="page" @endif data-tab="plan">Content Plan</a>
        </li>
    @endif
    <li class="nav-item">
        <a class="nav-link {{ $active === 'articles' ? 'active' : '' }}" href="{{ $contentRoute('articles.index') }}" @if($active === 'articles') aria-current="page" @endif data-tab="articles">Articles</a>
    </li>
    @if($withModule)
        <li class="nav-item">
            <a class="nav-link {{ $active === 'opportunities' ? 'active' : '' }}" href="{{ $contentRoute('opportunities') }}" @if($active === 'opportunities') aria-current="page" @endif data-tab="opportunities">Opportunities</a>
        </li>
    @endif
</ul>
