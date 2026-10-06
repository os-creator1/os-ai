{{-- Visible breadcrumb trail; the BreadcrumbList structured data is built from the same trail (WebsiteArticleStructuredData). --}}
<nav class="blog-breadcrumbs" aria-label="Breadcrumb">
    <ol>
        @foreach ($trail as $crumb)
            @if ($loop->last)
                <li aria-current="page">{{ $crumb['name'] }}</li>
            @else
                <li><a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a></li>
            @endif
        @endforeach
    </ol>
</nav>
