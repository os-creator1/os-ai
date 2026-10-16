@extends('layouts/contentLayoutMaster')

@section('title', 'Photos')

@section('page-style')
    @include('customer.business.website._manage-styles')
    <style>
        .mg-upload { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1.5rem; padding: 1.25rem; }
        .mg-drop { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .5rem; min-height: 11rem; padding: 1rem; text-align: center; border: 1.5px dashed var(--color-border, #D8D3CA); border-radius: .75rem; background: var(--color-surface-secondary, #FBFAF7); cursor: pointer; }
        .mg-drop.is-over { border-color: var(--color-primary, #B5524C); background: var(--color-primary-soft-bg, #F4E6E4); }
        .mg-drop input[type=file] { position: absolute; width: 1px; height: 1px; opacity: 0; }
        .mg-drop-browse { color: var(--color-primary, #B5524C); text-decoration: underline; }
        .mg-drop:focus-within { outline: 2px solid var(--color-primary, #B5524C); outline-offset: 2px; }
        .mg-gallery-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: 1.25rem 1.25rem 0; }
        .mg-filter { display: inline-flex; padding: .2rem; border-radius: .6rem; background: var(--color-surface-secondary, #F4F1EC); }
        .mg-filter button { border: 0; background: transparent; padding: .3rem .7rem; border-radius: .45rem; font-size: .8125rem; color: var(--color-text-muted, #6F6D67); }
        .mg-filter button.is-active { background: var(--color-surface, #fff); color: var(--color-text-primary, #262522); box-shadow: 0 1px 2px rgba(0,0,0,.08); font-weight: 600; }
        .mg-photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 1rem; padding: 1.25rem; }
        .mg-photo { position: relative; display: block; margin: 0; cursor: pointer; }
        .mg-photo[hidden] { display: none; }
        .mg-photo-frame { position: relative; display: block; aspect-ratio: 4 / 3; border-radius: .6rem; overflow: hidden; background: var(--color-surface-secondary, #F4F1EC); outline: 2px solid transparent; }
        .mg-photo input:checked + .mg-photo-frame { outline-color: var(--color-primary, #B5524C); }
        .mg-photo input:focus-visible + .mg-photo-frame { outline: 2px solid var(--color-text-primary, #262522); }
        .mg-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .mg-photo input { position: absolute; top: .5rem; left: .5rem; z-index: 1; width: 1.1rem; height: 1.1rem; margin: 0; }
        .mg-photo-cap { margin-top: .35rem; font-size: .75rem; line-height: 1.3; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        @media (max-width: 767.98px) { .mg-upload { grid-template-columns: minmax(0, 1fr); } }
    </style>
@endsection

@section('content')
    @include('customer.business.website._manage-header', ['active' => 'photos'])

    @if ($errors->any())
        <x-alert variant="danger" class="mb-3">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <form method="POST" enctype="multipart/form-data" class="mg-card" id="photo-upload-form" action="{{ route('customer.workspaces.businesses.website.assets.store', [$workspaceUid, $businessUid]) }}">
        @csrf
        <div class="mg-upload">
            <label class="mg-drop" id="photo-drop" for="website-image">
                <span class="mg-icon-tile"><x-ds-icon name="upload" size="20" /></span>
                <span><strong>Drop a photo here, or <span class="mg-drop-browse">browse</span></strong></span>
                <span class="mg-muted" id="photo-drop-name">Real event photos only &middot; PNG, JPG or WebP</span>
                <input id="website-image" name="image" type="file" accept="image/png,image/jpeg,image/webp" required>
            </label>
            <div>
                <h2 class="mg-card-title">Upload a photo</h2>
                <p class="mg-muted">Describe what it shows. Screen reader users hear this, and it's how you'll recognise the photo below.</p>
                <label class="form-label" for="website-alt-text">Describe the photo</label>
                <input id="website-alt-text" name="alt_text" type="text" maxlength="160" class="form-control mb-3" placeholder="e.g. Guests using our photo booth at a wedding" required>
                <x-button type="submit" variant="primary">Upload photo</x-button>
            </div>
        </div>
    </form>

    <div class="mg-card">
        <div class="mg-gallery-head">
            <div>
                <h2 class="mg-card-title">Choose photos for your Gallery page</h2>
                <p class="mg-muted mb-0">
                    Up to 24.
                    @if ($galleryPage)
                        <a href="{{ route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $galleryPage->uid]) }}">Edit the Gallery page</a> to change its title, description or search visibility. Selecting different photos and saving updates it.
                    @else
                        Select 1&ndash;24 photos and save to create your Gallery page. It starts hidden from search until you review it.
                    @endif
                </p>
            </div>
            @if ($assets->isNotEmpty())
                <div class="mg-filter" role="group" aria-label="Filter photos">
                    <button type="button" class="is-active" data-filter="all">All {{ $assets->count() }}</button>
                    <button type="button" data-filter="on">On Gallery {{ $assets->whereIn('uid', $selectedUids)->count() }}</button>
                    <button type="button" data-filter="off">Not used {{ $assets->whereNotIn('uid', $selectedUids)->count() }}</button>
                </div>
            @endif
        </div>

        @if ($assets->isEmpty())
            <div class="p-4">
                <x-empty-state icon="image" title="No photos yet" description="Upload at least one real event photo above to build a Gallery page." />
            </div>
        @else
            <form method="POST" id="gallery-form" action="{{ route('customer.workspaces.businesses.website.gallery.store', [$workspaceUid, $businessUid]) }}">
                @csrf
                <div class="mg-photo-grid">
                    @foreach ($assets as $asset)
                        @php($saved = in_array($asset->uid, $selectedUids, true))
                        <label class="mg-photo" data-saved="{{ $saved ? '1' : '0' }}">
                            <input type="checkbox" name="asset_uids[]" value="{{ $asset->uid }}" @checked($saved)>
                            <span class="mg-photo-frame"><img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?: 'Uploaded photo' }}" loading="lazy"></span>
                            <span class="mg-photo-cap" title="{{ $asset->alt_text }}">{{ $asset->alt_text ?: 'Uploaded photo' }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="mg-sticky-bar">
                    <span class="mg-muted" id="gallery-status" role="status" aria-live="polite">{{ count($selectedUids) }} of 24 selected &middot; No unsaved changes</span>
                    <x-button type="submit" variant="primary">{{ $galleryPage ? 'Update Gallery page' : 'Create Gallery page' }}</x-button>
                </div>
            </form>
        @endif
    </div>
@endsection

@section('page-script')
<script>
(function () {
    var MAX = 24;
    var drop = document.getElementById('photo-drop');
    var input = document.getElementById('website-image');
    var nameEl = document.getElementById('photo-drop-name');
    if (input) {
        input.addEventListener('change', function () { if (input.files[0]) nameEl.textContent = input.files[0].name; });
        ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('is-over'); }); });
        drop.addEventListener('drop', function (ev) {
            if (ev.dataTransfer && ev.dataTransfer.files.length) {
                input.files = ev.dataTransfer.files; // same form, same assets.store validation
                nameEl.textContent = input.files[0].name;
            }
        });
    }

    var form = document.getElementById('gallery-form');
    if (!form) { return; }
    var photos = Array.prototype.slice.call(form.querySelectorAll('.mg-photo'));
    var status = document.getElementById('gallery-status');
    var filter = 'all';

    function selected() { return photos.filter(function (p) { return p.querySelector('input').checked; }).length; }
    function refresh(message) {
        var dirty = photos.some(function (p) { return p.querySelector('input').checked !== (p.dataset.saved === '1'); });
        status.textContent = message || (selected() + ' of ' + MAX + ' selected · ' + (dirty ? 'Unsaved changes' : 'No unsaved changes'));
        photos.forEach(function (p) {
            var on = p.dataset.saved === '1';
            p.hidden = (filter === 'on' && !on) || (filter === 'off' && on);
        });
    }
    form.addEventListener('change', function (e) {
        if (e.target.matches('input[type=checkbox]') && e.target.checked && selected() > MAX) {
            e.target.checked = false;
            refresh('You can choose up to ' + MAX + ' photos.');
            return;
        }
        refresh();
    });
    document.querySelectorAll('[data-filter]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            filter = btn.dataset.filter;
            document.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
            refresh();
        });
    });
})();
</script>
@endsection
