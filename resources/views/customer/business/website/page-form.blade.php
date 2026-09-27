@extends('layouts/contentLayoutMaster')

@section('title', $page ? 'Edit page' : 'Add page')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">{{ $page ? 'Edit page' : 'Add page' }}</h4>
        </div>
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

    @if ($page)
        <x-card title="Photos for this site" class="mb-3">
            <p class="text-caption">Upload your own photos, then choose them in a section below. Describe what each photo shows for accessibility. Building a multi-photo Gallery page? <a href="{{ route('customer.workspaces.businesses.website.photos.index', [$workspaceUid, $businessUid]) }}">Use the Photos screen</a> instead.</p>
            <form method="POST" enctype="multipart/form-data" action="{{ route('customer.workspaces.businesses.website.assets.store', [$workspaceUid, $businessUid]) }}">
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
        </x-card>
    @endif

    <form method="POST"
          action="{{ $page ? route('customer.workspaces.businesses.website.pages.update', [$workspaceUid, $businessUid, $page->uid]) : route('customer.workspaces.businesses.website.pages.store', [$workspaceUid, $businessUid]) }}"
          id="website-page-form">
        @csrf
        @if ($page)
            @method('PUT')
        @endif

        <x-card title="Page details" class="mb-3">
            <x-input name="title" label="Title" value="{{ old('title', $page->title ?? '') }}" required />

            <div class="ds-field mb-3">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="is_home" name="is_home" value="1" @checked(old('is_home', $page->is_home ?? false))>
                    <label class="form-check-label" for="is_home">This is the homepage</label>
                </div>
            </div>

            <div id="slug-field" style="{{ old('is_home', $page->is_home ?? false) ? 'display:none' : '' }}">
                <x-input name="slug" label="Slug" value="{{ old('slug', $page->slug ?? '') }}" help="Lowercase letters, numbers, and hyphens only." />
            </div>

            <x-input name="seo_title" label="SEO title" value="{{ old('seo_title', $page->seo_title ?? '') }}" help="Falls back to the page title when empty." />
            <x-input name="meta_description" label="Meta description" value="{{ old('meta_description', $page->meta_description ?? '') }}" />

            <div class="ds-field mb-3">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="noindex" name="noindex" value="1" @checked(old('noindex', $page->noindex ?? false))>
                    <label class="form-check-label" for="noindex">Hide from search engines (in addition to the platform-wide default)</label>
                </div>
            </div>
        </x-card>

        <x-card title="Sections">
            <div id="sections-list"></div>

            <div class="d-flex gap-2 mt-3">
                <select class="form-select w-auto" id="add-section-type">
                    <option value="hero">Hero</option>
                    <option value="text">Text</option>
                    <option value="image_text">Image + Text</option>
                    <option value="services">Services</option>
                    <option value="testimonials">Testimonials</option>
                    <option value="faq">FAQ</option>
                    <option value="cta">Call to action</option>
                    <option value="contact_details">Contact details</option>
                    <option value="gallery">Gallery</option>
                    <option value="form">Form</option>
                </select>
                <x-button type="button" variant="secondary" id="add-section-btn">Add section</x-button>
            </div>
        </x-card>

        <input type="hidden" name="sections" id="sections-json">

        <div class="mt-3">
            <x-button type="submit" variant="primary">Save page</x-button>
            <x-button type="button" variant="ghost" onclick="window.history.back()">Cancel</x-button>
        </div>
    </form>
@endsection

@section('page-script')
<script>
(function () {
    var initialSections = @json(old('sections_decoded', $page->sections ?? []));
    var availableAssets = @json(isset($assets) ? $assets->map(fn ($asset) => ['uid' => $asset->uid, 'label' => $asset->alt_text ?: 'Uploaded photo', 'url' => $asset->url()])->values()->all() : []);
    var availableForms = @json(isset($forms) ? $forms->map(fn ($form) => ['uid' => $form->uid, 'name' => $form->name])->values()->all() : []);
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
            out.push({type: type, data: data});
        });
        return out;
    }

    initialSections.forEach(function (section) {
        listEl.appendChild(renderSection(section.type, section.data));
    });

    document.getElementById('add-section-btn').addEventListener('click', function () {
        var type = document.getElementById('add-section-type').value;
        listEl.appendChild(renderSection(type, {}));
    });

    document.getElementById('is_home').addEventListener('change', function () {
        document.getElementById('slug-field').style.display = this.checked ? 'none' : '';
    });

    document.getElementById('website-page-form').addEventListener('submit', function () {
        document.getElementById('sections-json').value = JSON.stringify(collectSections());
    });
})();
</script>
@endsection
