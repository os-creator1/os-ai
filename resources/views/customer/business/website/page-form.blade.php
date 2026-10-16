@extends('layouts/contentLayoutMaster')

@section('title', $page ? 'Edit page' : 'New page')

@section('page-style')
    @include('customer.business.website._manage-styles')
    <style>
        .mg-pf-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 1.25rem; align-items: start; }
        .mg-slug { display: flex; align-items: stretch; border: 1px solid var(--color-border, #D8D3CA); border-radius: .5rem; overflow: hidden; background: var(--color-surface, #fff); }
        .mg-slug > span { display: flex; align-items: center; padding: 0 .75rem; border-right: 1px solid var(--color-border, #D8D3CA); color: var(--color-text-muted, #6F6D67); background: var(--color-surface-secondary, #FBFAF7); }
        .mg-slug input { border: 0; border-radius: 0; box-shadow: none; flex: 1 1 auto; min-width: 0; }
        .mg-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .75rem 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .6rem; background: var(--color-surface-secondary, #FBFAF7); }
        .mg-toggle-row label { margin: 0; cursor: pointer; }
        .mg-toggle-row input { flex: none; }
        .mg-serp { padding: .9rem 1rem; border: 1px solid var(--color-border, #E5E1DA); border-radius: .6rem; background: var(--color-surface-secondary, #FBFAF7); }
        .mg-serp-url { font-size: .75rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
        .mg-serp-title { margin: .1rem 0; font-size: 1.05rem; color: #1a4fa0; overflow-wrap: anywhere; }
        .mg-serp-desc { font-size: .8125rem; color: var(--color-text-muted, #6F6D67); overflow-wrap: anywhere; }
        .mg-count { text-align: right; }
        .mg-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(8rem, 1fr)); gap: .6rem; }
        .mg-tile { display: flex; flex-direction: column; align-items: center; gap: .4rem; padding: .85rem .5rem; font-size: .8125rem; color: var(--color-text-primary, #262522); background: var(--color-surface, #fff); border: 1px solid var(--color-border, #E5E1DA); border-radius: .6rem; }
        .mg-tile:hover, .mg-tile:focus-visible { border-color: var(--color-primary, #B5524C); }
        .mg-tile .mg-icon-tile { width: 2rem; height: 2rem; border-radius: .5rem; background: var(--color-surface-secondary, #F4F1EC); color: var(--color-text-muted, #6F6D67); }
        .mg-add-zone { padding: 1rem; border: 1.5px dashed var(--color-border, #D8D3CA); border-radius: .75rem; }
        .mg-add-zone h3 { margin: 0 0 .75rem; font-size: .875rem; text-align: center; font-weight: 600; }
        #more-sections { margin-top: .6rem; }
        #more-sections[hidden] { display: none; }
        .mg-page-form .mg-card { margin-bottom: 1.25rem; }
        @media (max-width: 991.98px) { .mg-pf-grid { grid-template-columns: minmax(0, 1fr); } }
    </style>
@endsection

@section('content')
    @php
        $siteIds = [$workspaceUid, $businessUid];
        $siteHost = $website->activePrimaryDomain()?->domain ?? parse_url(route('public.website.home', $website->public_id), PHP_URL_HOST);
        $addFormUid = (string) request()->query('add_form', '');
        $addFormUid = isset($forms) && $forms->contains('uid', $addFormUid) ? $addFormUid : '';
        $isHomeNow = (bool) old('is_home', $page->is_home ?? false);
    @endphp

    <a class="mg-back-link" href="{{ route('customer.workspaces.businesses.website.show', $siteIds) }}" data-testid="back-to-website">
        <x-ds-icon name="arrow-left" size="16" /> Back to Website
    </a>
    <div class="mb-3">
        <h1 class="mg-manage-h1">{{ $page ? 'Edit page' : 'New page' }}</h1>
        <p class="mg-manage-sub">{{ $page ? 'Update this page, how it shows up in search, and its sections.' : 'Name it, set how it shows up in search, then add sections.' }}</p>
    </div>

    @if ($errors->any())
        <x-alert variant="danger" class="mb-3">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if ($page && in_array($page->slug, ['photo-booth-services', 'photo-booth-packages'], true))
        <x-alert variant="accent" class="mb-3">
            This page shows your saved {{ $page->slug === 'photo-booth-services' ? 'services' : 'packages' }} list. Edit an item's Description in the sections below, or add a Text section, for page-specific booth types, backdrops, props, and extras{{ $page->slug === 'photo-booth-packages' ? ", or what's included in each package" : '' }}. Edits here apply only to this page — they never change Home or your saved Business service/catalog records.
        </x-alert>
    @endif

    @if ($addFormUid !== '')
        <x-alert variant="accent" class="mb-3" data-testid="add-form-notice">
            A Form section for your quote form has been added below. Nothing is saved or published until you press <strong>Save page</strong>.
        </x-alert>
    @endif

    @if ($page)
        <div class="mg-card mg-card-pad">
            <h2 class="mg-card-title">Photos for this site</h2>
            <p class="mg-muted">Upload your own photos, then choose them in a section below. Describe what each photo shows for accessibility. Building a multi-photo Gallery page? <a href="{{ route('customer.workspaces.businesses.website.photos.index', $siteIds) }}">Use the Photos screen</a> instead.</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('customer.workspaces.businesses.website.assets.store', $siteIds) }}">
                @csrf
                <div class="row align-items-end">
                    <div class="col-md-5 mb-2"><label class="form-label" for="website-image">Photo</label><input id="website-image" name="image" type="file" accept="image/png,image/jpeg,image/webp" class="form-control" required></div>
                    <div class="col-md-5 mb-2"><label class="form-label" for="website-alt-text">Describe the photo</label><input id="website-alt-text" name="alt_text" type="text" maxlength="160" class="form-control" placeholder="e.g. Guests using our photo booth" required></div>
                    <div class="col-md-2 mb-2"><x-button type="submit" variant="secondary">Upload photo</x-button></div>
                </div>
            </form>
            @if (isset($assets) && $assets->isNotEmpty())
                <div class="d-flex flex-wrap gap-2 mt-2">
                    @foreach ($assets as $asset)
                        <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?: 'Uploaded photo' }}" title="{{ $asset->alt_text ?: 'Uploaded photo' }}" width="100" height="75" style="object-fit:cover;border-radius:8px">
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <form method="POST" class="mg-page-form"
          action="{{ $page ? route('customer.workspaces.businesses.website.pages.update', [$workspaceUid, $businessUid, $page->uid]) : route('customer.workspaces.businesses.website.pages.store', $siteIds) }}"
          id="website-page-form">
        @csrf
        @if ($page)
            @method('PUT')
        @endif

        <div class="mg-pf-grid">
            <div>
                <div class="mg-card mg-card-pad">
                    <h2 class="mg-card-title mb-3">Page details</h2>
                    <div class="mb-3">
                        <label class="form-label" for="title">Title</label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ old('title', $page->title ?? '') }}" placeholder="e.g. Wedding photo booths" required>
                    </div>

                    <div id="slug-field" class="mb-3" style="{{ $isHomeNow ? 'display:none' : '' }}">
                        <label class="form-label" for="slug">Address</label>
                        <div class="mg-slug"><span>/</span><input type="text" class="form-control" id="slug" name="slug" value="{{ old('slug', $page->slug ?? '') }}" placeholder="wedding-photo-booths" autocomplete="off"></div>
                        <div class="mg-muted mt-1">Filled in from the title. Lowercase letters, numbers and hyphens only.</div>
                    </div>

                    <div class="mg-toggle-row">
                        <div>
                            <label for="is_home" class="fw-semibold">Make this the homepage</label>
                            <div class="mg-muted">It replaces Home as the first page visitors see.</div>
                        </div>
                        <div class="form-check form-switch m-0"><input type="checkbox" role="switch" class="form-check-input" id="is_home" name="is_home" value="1" @checked($isHomeNow)></div>
                    </div>
                </div>

                <div class="mg-card mg-card-pad">
                    <h2 class="mg-card-title">Sections</h2>
                    <p class="mg-muted">Build the page from blocks. You can reorder them later.</p>
                    <div id="sections-list"></div>

                    <div class="mg-add-zone">
                        <h3 id="add-zone-title">Add your first section</h3>
                        <div class="mg-tiles" id="section-tiles">
                            <button type="button" class="mg-tile" data-add-type="hero"><span class="mg-icon-tile"><x-ds-icon name="layout-template" size="16" /></span>Hero</button>
                            <button type="button" class="mg-tile" data-add-type="form"><span class="mg-icon-tile"><x-ds-icon name="file-text" size="16" /></span>Form</button>
                            <button type="button" class="mg-tile" data-add-type="image_text"><span class="mg-icon-tile"><x-ds-icon name="image" size="16" /></span>Image + Text</button>
                            <button type="button" class="mg-tile" id="more-sections-btn" aria-expanded="false" aria-controls="more-sections"><span class="mg-icon-tile"><x-ds-icon name="ellipsis" size="16" /></span>More sections</button>
                        </div>
                        <div class="mg-tiles" id="more-sections" hidden>
                            <button type="button" class="mg-tile" data-add-type="text"><span class="mg-icon-tile"><x-ds-icon name="type" size="16" /></span>Text</button>
                            <button type="button" class="mg-tile" data-add-type="services"><span class="mg-icon-tile"><x-ds-icon name="list" size="16" /></span>Services</button>
                            <button type="button" class="mg-tile" data-add-type="testimonials"><span class="mg-icon-tile"><x-ds-icon name="quote" size="16" /></span>Testimonials</button>
                            <button type="button" class="mg-tile" data-add-type="faq"><span class="mg-icon-tile"><x-ds-icon name="circle-help" size="16" /></span>FAQ</button>
                            <button type="button" class="mg-tile" data-add-type="cta"><span class="mg-icon-tile"><x-ds-icon name="megaphone" size="16" /></span>Call to action</button>
                            <button type="button" class="mg-tile" data-add-type="contact_details"><span class="mg-icon-tile"><x-ds-icon name="phone" size="16" /></span>Contact details</button>
                            <button type="button" class="mg-tile" data-add-type="gallery"><span class="mg-icon-tile"><x-ds-icon name="images" size="16" /></span>Gallery</button>
                            <button type="button" class="mg-tile" data-add-type="forms_module_form"><span class="mg-icon-tile"><x-ds-icon name="clipboard-list" size="16" /></span>Form from the Forms module</button>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="mg-card mg-card-pad">
                    <h2 class="mg-card-title">How it shows up in search</h2>
                    <p class="mg-muted">Optional. Leave blank to use the page title.</p>
                    <div class="mg-serp mb-3" aria-live="polite" data-testid="serp-preview">
                        <div class="mg-serp-url" id="serp-url" data-host="{{ $siteHost }}"></div>
                        <div class="mg-serp-title" id="serp-title"></div>
                        <div class="mg-serp-desc" id="serp-desc"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="seo_title">SEO title</label>
                        <input type="text" class="form-control" id="seo_title" name="seo_title" value="{{ old('seo_title', $page->seo_title ?? '') }}">
                        <div class="d-flex justify-content-between mg-muted mt-1"><span>Uses the page title when empty</span><span id="seo-title-count">0 / 60</span></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="meta_description">Meta description</label>
                        <textarea class="form-control" rows="3" id="meta_description" name="meta_description">{{ old('meta_description', $page->meta_description ?? '') }}</textarea>
                        <div class="d-flex justify-content-between mg-muted mt-1"><span>Shown under the title in results</span><span id="meta-count">0 / 160</span></div>
                    </div>
                    <div class="mg-toggle-row">
                        <div>
                            <label for="noindex" class="fw-semibold">Hide from search engines</label>
                            <div class="mg-muted">Stays hidden even if your other pages are visible.</div>
                        </div>
                        <div class="form-check form-switch m-0"><input type="checkbox" role="switch" class="form-check-input" id="noindex" name="noindex" value="1" @checked(old('noindex', $page->noindex ?? false))></div>
                    </div>
                </div>
            </div>
        </div>

        <input type="hidden" name="sections" id="sections-json">

        <div class="mg-sticky-bar">
            <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.website.show', $siteIds) }}">Cancel</a>
            <x-button type="submit" variant="primary">Save page</x-button>
        </div>
    </form>
@endsection

@section('page-script')
<script>
(function () {
    var initialSections = @json(old('sections_decoded', $page->sections ?? []));
    var availableAssets = @json(isset($assets) ? $assets->map(fn ($asset) => ['uid' => $asset->uid, 'label' => $asset->alt_text ?: 'Uploaded photo', 'url' => $asset->url()])->values()->all() : []);
    var availableForms = @json(isset($forms) ? $forms->map(fn ($form) => ['uid' => $form->uid, 'name' => $form->name])->values()->all() : []);
    var availableFormsModule = @json($formsModule ?? []);
    var listEl = document.getElementById('sections-list');
    var counter = 0;

    var FIELD_TEMPLATES = {
        hero: [
            {key: 'heading', label: 'Heading', type: 'text'},
            {key: 'subheading', label: 'Subheading', type: 'text'},
            {key: 'background_image', label: 'Background photo', type: 'asset'},
            {key: 'primary_cta.label', label: 'Primary button label', type: 'text'},
            {key: 'primary_cta.url', label: 'Primary button URL', type: 'text'},
            {key: 'secondary_cta.label', label: 'Secondary button label', type: 'text'},
            {key: 'secondary_cta.url', label: 'Secondary button URL', type: 'text'}
        ],
        text: [
            {key: 'heading', label: 'Heading', type: 'text'},
            {key: 'body', label: 'Body', type: 'textarea'}
        ],
        image_text: [
            {key: 'heading', label: 'Heading', type: 'text'},
            {key: 'body', label: 'Body', type: 'textarea'},
            {key: 'image', label: 'Photo', type: 'asset'},
            {key: 'image_position', label: 'Image position (left/right)', type: 'text'}
        ],
        cta: [
            {key: 'heading', label: 'Heading', type: 'text'},
            {key: 'body', label: 'Body', type: 'textarea'},
            {key: 'buttons.0.label', label: 'Button 1 label', type: 'text'},
            {key: 'buttons.0.url', label: 'Button 1 URL', type: 'text'},
            {key: 'buttons.1.label', label: 'Button 2 label (optional)', type: 'text'},
            {key: 'buttons.1.url', label: 'Button 2 URL (optional)', type: 'text'}
        ],
        contact_details: [
            {key: 'show_phone', label: 'Show phone', type: 'checkbox'},
            {key: 'show_email', label: 'Show email', type: 'checkbox'},
            {key: 'show_address', label: 'Show address', type: 'checkbox'}
        ],
        services: [{key: 'heading', label: 'Heading', type: 'text'}],
        testimonials: [{key: 'heading', label: 'Heading', type: 'text'}],
        faq: [{key: 'heading', label: 'Heading', type: 'text'}],
        gallery: [{key: 'heading', label: 'Heading', type: 'text'}],
        form: [
            {key: 'heading', label: 'Heading', type: 'text'},
            {key: 'form_uid', label: 'Form', type: 'form_select'}
        ],
        forms_module_form: [
            {key: 'heading', label: 'Heading', type: 'text'},
            {key: 'forms_module_deployment_uid', label: 'Form', type: 'forms_module_select'}
        ]
    };

    var ITEM_TEMPLATES = {
        services: [
            {key: 'name', label: 'Name'}, {key: 'description', label: 'Description'},
            {key: 'price_label', label: 'Price label'}, {key: 'image', label: 'Photo', type: 'asset'}
        ],
        testimonials: [
            {key: 'quote', label: 'Quote'}, {key: 'author_name', label: 'Author name'}, {key: 'author_title', label: 'Author title'}
        ],
        faq: [{key: 'question', label: 'Question'}, {key: 'answer', label: 'Answer'}],
        gallery: [{key: 'image', label: 'Photo', type: 'asset'}]
    };

    function get(obj, path) {
        return path.split('.').reduce(function (o, k) { return (o || {})[k]; }, obj) || '';
    }

    function fieldHtml(type, key, value) {
        if (type === 'asset') {
            return '<div class="mb-2"><select class="form-select" data-field="' + key + '">' + assetOptions(value) + '</select></div>';
        }
        if (type === 'form_select') {
            if (availableForms.length === 0) {
                return '<div class="mb-2 text-caption">No forms yet. Create one from the Forms screen first.</div>';
            }
            return '<div class="mb-2"><select class="form-select" data-field="' + key + '">' + formOptions(value) + '</select></div>';
        }
        if (type === 'forms_module_select') {
            if (availableFormsModule.length === 0 && !value) {
                return '<div class="mb-2 text-caption">No Forms-module form is offered to Website pages yet. In Forms, open a form and switch on "Website pages" for a location.</div>';
            }
            return '<div class="mb-2"><select class="form-select" data-field="' + key + '">' + formsModuleOptions(value) + '</select></div>';
        }
        if (type === 'textarea') {
            return '<div class="mb-2"><textarea class="form-control" data-field="' + key + '" rows="3">' + escapeHtml(value) + '</textarea></div>';
        }
        if (type === 'checkbox') {
            return '<div class="form-check mb-2"><input type="checkbox" class="form-check-input" data-field="' + key + '" ' + (value ? 'checked' : '') + '></div>';
        }
        return '<div class="mb-2"><input type="text" class="form-control" data-field="' + key + '" value="' + escapeHtml(value) + '"></div>';
    }

    function escapeHtml(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function assetOptions(selected) {
        var options = '<option value="">Choose a photo</option>';
        availableAssets.forEach(function (asset, index) {
            options += '<option value="' + escapeHtml(asset.uid) + '"' + (asset.uid === selected ? ' selected' : '') + '>' + escapeHtml(asset.label) + ' #' + (index + 1) + '</option>';
        });
        return options;
    }

    function formsModuleOptions(selected) {
        var options = '<option value="">Choose a form</option>';
        var found = false;
        availableFormsModule.forEach(function (form) {
            if (form.uid === selected) { found = true; }
            options += '<option value="' + escapeHtml(form.uid) + '"' + (form.uid === selected ? ' selected' : '') + '>' + escapeHtml(form.label) + '</option>';
        });
        if (selected && !found) {
            options += '<option value="' + escapeHtml(selected) + '" selected>Unavailable — this form is switched off or its location is closed</option>';
        }
        return options;
    }

    function formOptions(selected) {
        var options = '<option value="">Choose a form</option>';
        availableForms.forEach(function (form) {
            options += '<option value="' + escapeHtml(form.uid) + '"' + (form.uid === selected ? ' selected' : '') + '>' + escapeHtml(form.name) + '</option>';
        });
        return options;
    }

    function renderSection(type, data) {
        data = data || {};
        var idx = counter++;
        var wrap = document.createElement('div');
        wrap.className = 'card mb-3';
        wrap.dataset.type = type;
        wrap.dataset.index = idx;

        var fields = FIELD_TEMPLATES[type] || [];
        var html = '<div class="card-body">';
        html += '<div class="d-flex justify-content-between align-items-center mb-2">';
        html += '<strong>' + type.replace('_', ' ') + '</strong>';
        html += '<span><button type="button" class="btn btn-sm btn-outline-secondary move-up">Up</button> ';
        html += '<button type="button" class="btn btn-sm btn-outline-secondary move-down">Down</button> ';
        html += '<button type="button" class="btn btn-sm btn-outline-danger remove-section">Remove</button></span></div>';

        fields.forEach(function (f) {
            html += '<label class="form-label text-caption">' + f.label + '</label>';
            html += fieldHtml(f.type, f.key, get(data, f.key));
        });

        if (ITEM_TEMPLATES[type]) {
            html += '<div class="items-list" data-items></div>';
            html += '<button type="button" class="btn btn-sm btn-outline-secondary add-item">Add item</button>';
        }

        html += '</div>';
        wrap.innerHTML = html;

        if (ITEM_TEMPLATES[type]) {
            var itemsList = wrap.querySelector('[data-items]');
            (data.items || []).forEach(function (item) { itemsList.appendChild(renderItem(type, item)); });
            wrap.querySelector('.add-item').addEventListener('click', function () {
                itemsList.appendChild(renderItem(type, {}));
            });
        }

        wrap.querySelector('.remove-section').addEventListener('click', function () { wrap.remove(); });
        wrap.querySelector('.move-up').addEventListener('click', function () {
            if (wrap.previousElementSibling) listEl.insertBefore(wrap, wrap.previousElementSibling);
        });
        wrap.querySelector('.move-down').addEventListener('click', function () {
            if (wrap.nextElementSibling) listEl.insertBefore(wrap.nextElementSibling, wrap);
        });

        return wrap;
    }

    function renderItem(type, item) {
        item = item || {};
        var row = document.createElement('div');
        row.className = 'border rounded p-2 mb-2';
        var html = '';
        (ITEM_TEMPLATES[type] || []).forEach(function (f) {
            if (f.type === 'asset') {
                html += '<select class="form-select form-select-sm mb-1" aria-label="Photo" data-item-field="' + f.key + '">' + assetOptions(item[f.key] || '') + '</select>';
            } else {
                html += '<input type="text" class="form-control form-control-sm mb-1" placeholder="' + f.label + '" data-item-field="' + f.key + '" value="' + escapeHtml(item[f.key] || '') + '">';
            }
        });
        html += '<button type="button" class="btn btn-sm btn-link text-danger p-0 remove-item">Remove item</button>';
        row.innerHTML = html;
        row.querySelector('.remove-item').addEventListener('click', function () { row.remove(); });
        return row;
    }

    function setDeep(obj, path, value) {
        var keys = path.split('.');
        var cur = obj;
        for (var i = 0; i < keys.length - 1; i++) {
            var k = keys[i];
            if (!cur[k] || typeof cur[k] !== 'object') cur[k] = {};
            cur = cur[k];
        }
        cur[keys[keys.length - 1]] = value;
    }

    function collectSections() {
        var out = [];
        listEl.querySelectorAll(':scope > div').forEach(function (block) {
            var type = block.dataset.type;
            var data = {};
            block.querySelectorAll('[data-field]').forEach(function (el) {
                var key = el.dataset.field;
                var value = el.type === 'checkbox' ? el.checked : el.value;
                setDeep(data, key, value);
            });
            var itemsList = block.querySelector('[data-items]');
            if (itemsList) {
                var items = [];
                itemsList.querySelectorAll(':scope > div').forEach(function (row) {
                    var item = {};
                    row.querySelectorAll('[data-item-field]').forEach(function (el) {
                        item[el.dataset.itemField] = el.value;
                    });
                    items.push(item);
                });
                data.items = items;
            }
            if (data.buttons) {
                data.buttons = Object.keys(data.buttons).map(function (k) { return data.buttons[k]; }).filter(function (b) { return b && b.url; });
            }
            // A hero's Primary/Secondary button fields are always rendered
            // (see FIELD_TEMPLATES.hero above), so setDeep() always creates
            // an empty {label: '', url: ''} object even when the owner
            // never filled them in — the common case for a generated
            // page's own hero. The server's required_with validation
            // requires BOTH once the key is present at all, so an
            // untouched, fully-blank cta object must never be sent —
            // only a genuinely started one (matching how `buttons` above
            // already drops entries with no url).
            ['primary_cta', 'secondary_cta'].forEach(function (ctaKey) {
                if (data[ctaKey] && !data[ctaKey].label && !data[ctaKey].url) {
                    delete data[ctaKey];
                }
            });
            out.push({type: type, data: data});
        });
        return out;
    }

    initialSections.forEach(function (section) {
        listEl.appendChild(renderSection(section.type, section.data));
    });

    // "Add to a page" from the Forms screen: append a Form section bound to that form. Nothing is saved until Save page.
    var addFormUid = @json($addFormUid ?? '');
    if (addFormUid) {
        var already = initialSections.some(function (s) { return s.type === 'form' && s.data && s.data.form_uid === addFormUid; });
        if (!already) {
            listEl.appendChild(renderSection('form', {heading: 'Request a quote', form_uid: addFormUid}));
        }
    }

    var zoneTitle = document.getElementById('add-zone-title');
    function syncZoneTitle() { zoneTitle.textContent = listEl.children.length ? 'Add another section' : 'Add your first section'; }
    syncZoneTitle();
    new MutationObserver(syncZoneTitle).observe(listEl, {childList: true});

    document.querySelectorAll('[data-add-type]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var block = renderSection(btn.dataset.addType, {});
            listEl.appendChild(block);
            block.scrollIntoView({behavior: 'smooth', block: 'center'});
        });
    });

    var moreBtn = document.getElementById('more-sections-btn');
    var moreEl = document.getElementById('more-sections');
    moreBtn.addEventListener('click', function () {
        var show = moreEl.hidden;
        moreEl.hidden = !show;
        moreBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
    });

    var titleEl = document.getElementById('title');
    var slugEl = document.getElementById('slug');
    var homeEl = document.getElementById('is_home');
    var seoTitleEl = document.getElementById('seo_title');
    var metaEl = document.getElementById('meta_description');
    var urlEl = document.getElementById('serp-url');

    function slugify(v) {
        return v.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
    }
    // The address follows the title until the owner edits it by hand (an existing page's address is never rewritten).
    var slugTouched = slugEl.value !== '';
    slugEl.addEventListener('input', function () { slugTouched = slugEl.value !== ''; renderPreview(); });
    titleEl.addEventListener('input', function () {
        if (!slugTouched) { slugEl.value = slugify(titleEl.value); }
        renderPreview();
    });

    function renderPreview() {
        var path = homeEl.checked ? '' : (slugEl.value || slugify(titleEl.value) || 'your-page');
        urlEl.textContent = urlEl.dataset.host + (path ? ' › ' + path : '');
        document.getElementById('serp-title').textContent = seoTitleEl.value || titleEl.value || 'Your page title';
        document.getElementById('serp-desc').textContent = metaEl.value || 'Add a short description so people know what this page is about.';
        document.getElementById('seo-title-count').textContent = seoTitleEl.value.length + ' / 60';
        document.getElementById('meta-count').textContent = metaEl.value.length + ' / 160';
    }
    [seoTitleEl, metaEl].forEach(function (el) { el.addEventListener('input', renderPreview); });

    homeEl.addEventListener('change', function () {
        document.getElementById('slug-field').style.display = this.checked ? 'none' : '';
        renderPreview();
    });
    renderPreview();

    document.getElementById('website-page-form').addEventListener('submit', function () {
        document.getElementById('sections-json').value = JSON.stringify(collectSections());
    });
})();
</script>
@endsection
