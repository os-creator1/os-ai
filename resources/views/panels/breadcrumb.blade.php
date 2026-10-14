
{{--For Logged as customer option--}}
@include('auth.loggedAs')
{{--For Logged as customer option end here--}}

{{-- The View-as-client banner is rendered by the layout masters, not here: it must show even when this title bar is switched off. --}}


@php
    // One primary heading per page: when the page already renders its own, the shell prints
    // only the breadcrumbs (see PageHeadingResolver). The browser <title> is untouched.
    $sharedTitle = trim(strip_tags($__env->yieldContent('title')));
    $pageOwnsHeading = \App\Library\Navigation\PageHeadingResolver::ownsHeading(
        (string) $__env->yieldContent('content'),
        $sharedTitle,
        $__env->hasSection('page-heading') ? $__env->yieldContent('page-heading') : null
    );
    $hasBreadcrumbs = ! empty($breadcrumbs ?? null);
@endphp
@if (! $pageOwnsHeading || $hasBreadcrumbs)
<div class="content-header row" @if($pageOwnsHeading) data-role="shell-breadcrumbs-only" @endif>
    <div class="content-header-left col-md-9 col-12 mb-2">
        <div class="row breadcrumbs-top">
            <div class="col-12">
                @unless ($pageOwnsHeading)
                <h2 class="content-header-title text-page-title float-start mb-0">@yield('title')</h2>
                @endunless
                <div class="breadcrumb-wrapper">
                    @if(@isset($breadcrumbs))
                        <ol class="breadcrumb">
                            {{-- this will load breadcrumbs dynamically from controller --}}
                            @foreach ($breadcrumbs as $breadcrumb)
                                <li class="breadcrumb-item">
                                    @if(isset($breadcrumb['link']))
                                        <a href="{{ $breadcrumb['link'] == 'javascript:void(0)' ? $breadcrumb['link']:url($breadcrumb['link']) }}">
                                            @endif
                                            {{$breadcrumb['name']}}
                                            @if(isset($breadcrumb['link']))
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endisset
                </div>
            </div>
        </div>
    </div>
</div>
@endif
