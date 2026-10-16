@extends('layouts/contentLayoutMaster')

@section('title', 'Pages')

@section('page-style')
    @include('customer.business.website._manage-styles')
    <style>
        .mg-check-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: 1.1rem 1.25rem .85rem; }
        .mg-progress-meta { display: flex; align-items: center; gap: .6rem; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); white-space: nowrap; }
        .mg-segs { display: flex; gap: .2rem; }
        .mg-segs i { width: 1.1rem; height: .4rem; border-radius: 999px; background: var(--color-border, #E5E1DA); }
        .mg-segs i.is-done { background: var(--color-success, #3C8D5A); }
        .mg-row { display: flex; align-items: center; gap: .85rem; padding: .8rem 1.25rem; border-top: 1px solid var(--color-border, #E5E1DA); }
        .mg-row-main { flex: 1 1 auto; min-width: 0; }
        .mg-row-title { font-size: .875rem; font-weight: 600; overflow-wrap: anywhere; }
        .mg-row-sub { font-size: .75rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
        .mg-row-action { flex: none; max-width: 14rem; text-align: right; font-size: .75rem; font-weight: 600; }
        .mg-dot { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 1.25rem; height: 1.25rem; border-radius: 50%; border: 1.5px solid var(--color-border, #D8D3CA); color: transparent; }
        .mg-dot.is-done { background: var(--color-success-soft-bg, #EAF5EE); border-color: var(--color-success, #3C8D5A); color: var(--color-success, #3C8D5A); }
        .mg-group { padding: .5rem 1.25rem; border-top: 1px solid var(--color-border, #E5E1DA); background: var(--color-surface-secondary, #FBFAF7); font-size: .6875rem; letter-spacing: .05em; text-transform: uppercase; color: var(--color-text-muted, #6F6D67); }
        .mg-page-icon { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 1.9rem; height: 1.9rem; border-radius: .5rem; background: var(--color-surface-secondary, #F4F1EC); color: var(--color-text-muted, #6F6D67); }
        .mg-page-icon.is-home { background: var(--color-primary-soft-bg, #F4E6E4); color: var(--color-primary, #B5524C); }
        .mg-slug-text { font-size: .6875rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
        .mg-row-actions { flex: none; display: flex; align-items: center; gap: .5rem; }
        .mg-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border: 0; border-radius: .5rem; background: transparent; color: var(--color-text-muted, #6F6D67); text-decoration: none; }
        .mg-icon-btn:hover, .mg-icon-btn:focus-visible { background: var(--color-surface-secondary, #F4F1EC); color: var(--color-primary, #B5524C); }
        .mg-badge-sm { display: inline-block; padding: .05rem .5rem; border-radius: 999px; font-size: .6875rem; background: var(--color-primary-soft-bg, #F4E6E4); color: var(--color-primary, #B5524C); vertical-align: middle; }
        .mg-badge-sm.is-warn { background: var(--color-warning-soft-bg, #FCF1DC); color: #9A6A12; }
        .mg-menu { position: relative; }
        .mg-menu summary { list-style: none; cursor: pointer; }
        .mg-menu summary::-webkit-details-marker { display: none; }
        .mg-menu-pop { position: absolute; right: 0; top: calc(100% + .25rem); z-index: 6; min-width: 12rem; padding: .35rem; background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .6rem; box-shadow: 0 8px 24px rgba(0,0,0,.12); }
        .mg-menu-pop button { width: 100%; text-align: left; border: 0; background: transparent; padding: .45rem .6rem; border-radius: .4rem; font-size: .875rem; }
        .mg-menu-pop button:hover, .mg-menu-pop button:focus-visible { background: var(--color-surface-secondary, #F4F1EC); }
        @media (max-width: 767.98px) {
            .mg-row { flex-wrap: wrap; }
            .mg-row-action { flex: 1 0 100%; max-width: none; text-align: left; padding-left: 2.1rem; }
            .mg-row-actions { margin-left: auto; }
        }
    </style>
@endsection

@section('content')
    @include('customer.business.website._manage-header', ['active' => 'pages'])

    @if ($pages->contains('noindex', true))
        <x-alert variant="accent" class="mb-3" data-testid="hidden-from-search">
            {{ $pages->where('noindex', true)->count() }} of {{ $pages->count() }} pages are hidden from search engines. Generated pages start hidden so you can read them first. When you are happy with them, let search engines find them, then publish. (Search engines only list a website on its own domain; a platform address is never listed.)
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.pages.allowIndexing', [$workspaceUid, $businessUid]) }}" class="mt-2">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" data-testid="allow-indexing">Let search engines find these pages</button>
            </form>
        </x-alert>
    @endif

    @php
        $ids = [$workspaceUid, $businessUid];
        $doneCount = collect($checklist)->where('done', true)->count();
        $needsAnswers = ($aboutFaqMissingCount ?? 0) > 0 ? ['photo-booth-about', 'photo-booth-faq'] : [];
        $canReuseGallery = $isPhotoBooth && $galleryPage && ! empty(collect($galleryPage->sections ?? [])->firstWhere('type', 'gallery')['data']['items'] ?? []);
        // No page-type column exists: generated pages follow fixed slug conventions (service-*, serving-*).
        $groups = [
            'Main pages' => $pages->reject(fn ($p) => str_starts_with($p->slug, 'service-') || str_starts_with($p->slug, 'serving-')),
            'Service pages' => $pages->filter(fn ($p) => str_starts_with($p->slug, 'service-')),
            'Location pages' => $pages->filter(fn ($p) => str_starts_with($p->slug, 'serving-')),
        ];
        $iconFor = fn ($p) => $p->is_home ? 'house' : match (true) {
            $p->slug === 'gallery' => 'images',
            str_ends_with($p->slug, 'faq') => 'circle-help',
            str_starts_with($p->slug, 'service-') => 'camera',
            str_starts_with($p->slug, 'serving-') => 'map-pin',
            default => 'file-text',
        };
    @endphp

    @if (count($checklist) > 0)
        <div class="mg-card" data-testid="site-checklist">
            <div class="mg-check-head">
                <div>
                    <h2 class="mg-card-title mb-0">Make your site stronger</h2>
                    <p class="mg-muted mb-0">We reuse what you've already saved. Real detail only you can add makes it better.</p>
                </div>
                <div class="mg-progress-meta" aria-label="{{ $doneCount }} of {{ count($checklist) }} done">
                    <span data-testid="checklist-count">{{ $doneCount }} of {{ count($checklist) }} done</span>
                    <span class="mg-segs" aria-hidden="true">
                        @foreach ($checklist as $item)<i @class(['is-done' => $item['done']])></i>@endforeach
                    </span>
                </div>
            </div>
            @foreach ($checklist as $item)
                <div class="mg-row" data-checklist-key="{{ $item['key'] }}" data-done="{{ $item['done'] ? '1' : '0' }}">
                    <span @class(['mg-dot', 'is-done' => $item['done']])>@if ($item['done'])<x-ds-icon name="check" size="12" />@endif</span>
                    <div class="mg-row-main">
                        <div class="mg-row-title">{{ $item['title'] }}</div>
                        <div class="mg-row-sub">{{ $item['sub'] }}</div>
                    </div>
                    @if ($item['url'])
                        <a class="mg-row-action" href="{{ $item['url'] }}">{{ $item['action'] }}</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="mg-card" data-testid="pages-list">
        <div class="mg-check-head" style="padding-bottom:.75rem">
            <h2 class="mg-card-title mb-0">Pages</h2>
            <span class="mg-muted">{{ $pages->count() }} {{ $pages->count() === 1 ? 'page' : 'pages' }}</span>
        </div>

        @foreach ($groups as $groupLabel => $groupPages)
            @continue($groupPages->isEmpty())
            <div class="mg-group">{{ $groupLabel }}@if ($groupLabel !== 'Main pages') &middot; {{ $groupPages->count() }}@endif</div>
            @foreach ($groupPages as $page)
                <div class="mg-row" data-testid="page-row">
                    <span @class(['mg-page-icon', 'is-home' => $page->is_home])><x-ds-icon :name="$iconFor($page)" size="16" /></span>
                    <div class="mg-row-main">
                        <div class="mg-row-title">
                            {{ $page->title }}
                            @if ($page->is_home)<span class="mg-badge-sm">Homepage</span>@endif
                            @if ($page->noindex)<span class="mg-badge-sm is-warn">Hidden from search</span>@endif
                            @if (in_array($page->slug, $needsAnswers, true))<span class="mg-badge-sm is-warn" data-testid="needs-answers">Needs answers</span>@endif
                        </div>
                        <div class="mg-slug-text mg-mono">/{{ $page->is_home ? '' : $page->slug }}</div>
                    </div>
                    <div class="mg-row-actions">
                        <a class="mg-icon-btn" href="{{ route('customer.workspaces.businesses.website.preview', [...$ids, $page->uid]) }}" aria-label="Preview {{ $page->title }}" title="Preview"><x-ds-icon name="eye" size="16" /></a>
                        <x-button variant="secondary" size="sm" href="{{ route('customer.workspaces.businesses.website.pages.edit', [...$ids, $page->uid]) }}" aria-label="Edit {{ $page->title }}">Edit</x-button>
                        @if ($canReuseGallery && $galleryPage->id !== $page->id)
                            <details class="mg-menu">
                                <summary class="mg-icon-btn" aria-label="More actions for {{ $page->title }}"><x-ds-icon name="ellipsis" size="16" /></summary>
                                <div class="mg-menu-pop">
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.pages.reuseGalleryPhotos', [...$ids, $page->uid]) }}">
                                        @csrf
                                        <button type="submit">Add Gallery photos</button>
                                    </form>
                                </div>
                            </details>
                        @endif
                    </div>
                </div>
            @endforeach
        @endforeach

        @if ($pages->isEmpty())
            <div class="p-4">
                <x-empty-state icon="file" title="No pages yet" description="Add your first page to get started." />
            </div>
        @endif
    </div>
@endsection
