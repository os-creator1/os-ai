{{--
    External Website Audit — the Website module shell for a Business whose primary
    website lives elsewhere: Overview / Audit / Improvement plan / Pages / Settings.

    There is deliberately NOTHING here that publishes, edits, rebuilds, re-templates
    or connects a domain: MotionGrove does not own this site's CMS. A page link opens
    the owner's own page in a new tab.

    #external-live is the region a running check refreshes IN PLACE (no full page
    reload): the page header (with its one primary action), the tab bar, the status banner
    and the tab content are swapped with the same page fetched again when the check
    finishes. The flash message sits outside the region so it survives the swap.

    Visual system: `_style` (scoped under `.xw`, ported from the supplied design references).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Website')

@section('content')
    @include('customer.business.website.external._style')

    <div class="xw xw-main">
        <x-flash-alert />

        <div id="external-live" class="xw-live" data-status-url="{{ $statusUrl }}" data-crawl-url="{{ $crawlUrl }}">
            @include('customer.business.website.external._header')

            @include('customer.business.website.external._status')

            @yield('external-content')
        </div>
    </div>

    @include('customer.business.website.external._live-script')
@endsection
