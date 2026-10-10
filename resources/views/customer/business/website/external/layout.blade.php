{{--
    External Website Audit Mode V1 — the Website module shell for a Business whose
    primary website lives elsewhere: Overview / Audit / Pages / Settings.

    There is deliberately NOTHING here that publishes, edits, rebuilds, re-templates
    or connects a domain: MotionGrove does not own this site's CMS. A page link opens
    the owner's own page in a new tab.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Website')

@section('content')
    <div class="row mb-1 align-items-center">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-1">
            <div>
                <h1 class="h3 mb-0">Website</h1>
                <p class="text-caption text-muted mb-0">
                    <x-badge variant="neutral" data-role="website-source">External website</x-badge>
                    @if($websiteUrl !== '')
                        <a href="{{ $websiteUrl }}" target="_blank" rel="noopener noreferrer" data-role="website-url">{{ preg_replace('#^https?://#', '', rtrim($websiteUrl, '/')) }}</a>
                    @endif
                </p>
            </div>
        </div>
    </div>

    <nav class="nav nav-pills mb-2" aria-label="Website" data-role="external-nav">
        @foreach($tabs as $tab)
            <a class="nav-link @if($active === $tab['key']) active @endif" href="{{ $tab['url'] }}" data-nav="{{ $tab['key'] }}" @if($active === $tab['key']) aria-current="page" @endif>{{ $tab['label'] }}</a>
        @endforeach
    </nav>

    <x-flash-alert class="mb-2" />

    @yield('external-content')
@endsection
