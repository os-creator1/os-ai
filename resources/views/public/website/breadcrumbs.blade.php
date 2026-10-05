{{-- SEO V1 final: the visible breadcrumb. Same trail as the BreadcrumbList JSON-LD (WebsiteBreadcrumbStructuredData::trail). --}}
@if (! empty($breadcrumbs) && count($breadcrumbs) > 1)
    <nav class="website-breadcrumbs" aria-label="Breadcrumb">
        <ol>
            @foreach ($breadcrumbs as $crumb)
                @if ($loop->last)
                    <li aria-current="page">{{ $crumb['name'] }}</li>
                @else
                    <li><a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a></li>
                @endif
            @endforeach
        </ol>
    </nav>
@endif
