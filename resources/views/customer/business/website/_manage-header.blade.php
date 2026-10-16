{{--
    Shared header for the Website management screens (Pages / Photos / Forms / Domains): the single "Back to Website"
    link (the main Website screen, never the legacy Pages list), the title with a real page count, Preview site / Add
    page, and the tab strip. Expects $workspaceUid, $businessUid, $website and $active (pages|photos|forms|domains).
--}}
@php
    $manageIds = [$workspaceUid, $businessUid];
    $manageTabs = [
        'pages' => ['Pages', route('customer.workspaces.businesses.website.pages.index', $manageIds)],
        'photos' => ['Photos', route('customer.workspaces.businesses.website.photos.index', $manageIds)],
        'forms' => ['Forms', route('customer.workspaces.businesses.website.forms.index', $manageIds)],
        'domains' => ['Domains', route('customer.workspaces.businesses.website.domains.index', $manageIds)],
    ];
    $managePageCount = $website->pages()->count();
@endphp

<a class="mg-back-link" href="{{ route('customer.workspaces.businesses.website.show', $manageIds) }}" data-testid="back-to-website">
    <x-ds-icon name="arrow-left" size="16" /> Back to Website
</a>

<div class="mg-manage-head">
    <div class="mg-manage-title">
        <h1 class="mg-manage-h1">Website</h1>
        <p class="mg-manage-sub">{{ $managePageCount }} {{ $managePageCount === 1 ? 'page' : 'pages' }}@if ($website->name) &middot; {{ $website->name }}@endif</p>
    </div>
    <div class="mg-manage-actions">
        <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.preview', $manageIds) }}">Preview site</x-button>
        <x-button variant="primary" icon="plus" href="{{ route('customer.workspaces.businesses.website.pages.create', $manageIds) }}">Add page</x-button>
    </div>
</div>

<nav class="mg-manage-tabs" aria-label="Manage website">
    @foreach ($manageTabs as $key => [$label, $url])
        <a href="{{ $url }}" @class(['is-active' => $active === $key]) @if ($active === $key) aria-current="page" @endif>
            {{ $label }}
            @if ($key === 'photos' && isset($assets) && $assets->isNotEmpty())<span class="mg-tab-count">{{ $assets->count() }}</span>@endif
        </a>
    @endforeach
</nav>

<x-flash-alert class="mb-3" />
