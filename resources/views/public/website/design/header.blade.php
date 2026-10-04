{{--
    Website V1 final — the template-owned header. One markup, four layouts
    (split / centered / sticky / pill — see WebsiteDesigns): the template
    changes the layout through CSS, the structure and accessibility stay the
    same. Compact primary navigation (WebsiteNavigationBuilder), a real
    mobile menu, a skip link, and keyboard-reachable dropdowns.

    Every value renders through escaped {{ }} output only.
--}}
@php
    $announceParts = array_filter([
        $siteContact['phone'] ? 'Call ' . $siteContact['phone'] : null,
    ]);
    $homeUrl = collect($nav['primary'])->firstWhere('title', 'Home')['url'] ?? null;
@endphp
<a class="wd-skip" href="#wd-main">Skip to content</a>

@if ($design->announcementBar && ($siteCta || $announceParts))
    <div class="wd-announce" data-testid="site-announcement">
        <div class="wd-announce-inner">
            @if ($announceParts)
                <a class="wd-announce-phone" href="tel:{{ preg_replace('/[^+0-9]/', '', $siteContact['phone']) }}">{{ implode(' · ', $announceParts) }}</a>
            @endif
            @if ($siteCta)
                <a class="wd-announce-cta" href="{{ $siteCta['url'] }}">{{ $siteCta['label'] }} &rarr;</a>
            @endif
        </div>
    </div>
@endif

<header class="wd-header wd-header-{{ $design->variant('header') }}" data-wd-header data-testid="site-header">
    <div class="wd-header-inner">
        @if ($homeUrl)
            <a class="wd-logo" href="{{ $homeUrl }}" @if (! $logo) aria-label="{{ $websiteMeta['name'] }} home" @endif>
        @else
            <span class="wd-logo">
        @endif
            @if ($logo)
                <img src="{{ $logo['url'] }}" alt="{{ $logo['alt'] }}">
            @else
                <span class="wd-wordmark">{{ $websiteMeta['name'] }}</span>
            @endif
        @if ($homeUrl) </a> @else </span> @endif

        @if (count($nav['primary']) > 0 || $siteCta)
            <button type="button" class="wd-menu-toggle" aria-expanded="false" aria-controls="wd-nav" data-wd-menu-toggle>
                <span class="wd-menu-label">Menu</span>
                <span class="wd-menu-bars" aria-hidden="true"></span>
            </button>

            <nav id="wd-nav" class="wd-nav" aria-label="Main navigation" data-wd-nav>
                <ul class="wd-nav-list">
                    @foreach ($nav['primary'] as $item)
                        @php $hasChildren = count($item['children']) > 0; @endphp
                        <li class="wd-nav-item @if ($hasChildren) wd-has-dd @endif @if ($item['current']) wd-current @endif">
                            @if ($item['url'])
                                <a class="wd-nav-link" href="{{ $item['url'] }}" @if ($item['current']) aria-current="page" @endif>{{ $item['title'] }}</a>
                            @else
                                <button type="button" class="wd-nav-link wd-dd-label" aria-expanded="false" aria-haspopup="true" data-wd-dd-toggle>{{ $item['title'] }}</button>
                            @endif

                            @if ($hasChildren)
                                @if ($item['url'])
                                    <button type="button" class="wd-dd-toggle" aria-expanded="false" aria-label="Show {{ $item['title'] }} pages" data-wd-dd-toggle></button>
                                @endif
                                <ul class="wd-dd">
                                    @foreach ($item['children'] as $child)
                                        <li><a href="{{ $child['url'] }}" @if ($child['current']) aria-current="page" @endif>{{ $child['title'] }}</a></li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                    @if ($siteCta)
                        <li class="wd-nav-cta"><a class="wd-btn wd-btn-primary" href="{{ $siteCta['url'] }}" data-testid="header-cta">{{ $siteCta['label'] }}</a></li>
                    @endif
                </ul>
            </nav>
        @endif
    </div>
</header>
