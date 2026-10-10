{{--
    SEO -> Content tab strip: Autopilot, Articles, Content Plan, Opportunities - the ONLY place those four are navigated (the
    sidebar has a single Content entry). Real links to real URLs; `data-content-nav` lets the shared SectionRouter swap just the
    region on a plain click (see _frame). Autopilot, the Content Plan and Opportunities are part of the SeoModule package; a
    Business without it sees Articles only (and the honest note on the Articles page). Escaped output only.

    Expects: $workspaceUid, $businessUid, $active (autopilot|plan|articles|opportunities), $withModule (bool).
--}}
@php
    $contentRoute = fn (string $name) => route('customer.workspaces.businesses.seo.content.' . $name, [$workspaceUid, $businessUid]);
    $tabs = array_filter([
        'autopilot' => $withModule ? ['Autopilot', $contentRoute('autopilot')] : null,
        'articles' => ['Articles', $contentRoute('articles.index')],
        'plan' => $withModule ? ['Content Plan', $contentRoute('plan')] : null,
        'opportunities' => $withModule ? ['Opportunities', $contentRoute('opportunities')] : null,
    ]);
@endphp
<nav class="content-tabs" aria-label="Content" data-role="content-tabs">
    @foreach($tabs as $key => [$label, $url])
        <a class="content-tab {{ $active === $key ? 'is-active' : '' }}" href="{{ $url }}" data-content-nav data-content-key="{{ $key }}" data-tab="{{ $key }}" @if($active === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
