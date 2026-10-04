{{--
    One gallery photo. With `$asset === null` this renders the client-side
    template (tokens __UID__/__URL__/__ALT__/__TITLE__ are replaced by the
    upload script). Every control is a plain script-driven element (no
    nested or per-photo forms): edits save themselves, and the owner never
    has to press a second "Upload"/"Save" button.
--}}
@php
    $uid = $asset?->uid ?? '__UID__';
    $url = $asset ? asset($asset->path) : '__URL__';
    $alt = $asset ? ($asset->alt_text_is_custom ? $asset->alt_text : '') : '';
    $title = $asset?->title ?? '';
    $category = $asset?->category_tag ?? '';
    $isCover = (bool) ($asset?->is_cover ?? false);
@endphp
<div class="card card-body mb-2" data-gallery-card data-uid="{{ $uid }}">
    <div class="d-flex gap-3 align-items-start">
        <img src="{{ $url }}" alt="{{ $asset?->alt_text ?? '' }}" style="width:96px;height:96px;object-fit:cover;" class="rounded" data-gallery-image>
        <div class="flex-grow-1">
            <div class="row g-2 mb-2">
                <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" placeholder="Title" value="{{ $title }}" data-gallery-field="title" aria-label="Title">
                </div>
                <div class="col-md-4">
                    @if (! empty($categories))
                        <select class="form-select form-select-sm" data-gallery-field="category_tag" aria-label="Category">
                            <option value="">Category&hellip;</option>
                            @foreach ($categories as $categoryValue => $categoryLabel)
                                <option value="{{ $categoryValue }}" @selected($category === $categoryValue)>{{ $categoryLabel }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" class="form-control form-control-sm" placeholder="Category" value="{{ $category }}" data-gallery-field="category_tag" aria-label="Category">
                    @endif
                </div>
                <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" placeholder="Alt text (we'll suggest one)" value="{{ $alt }}" data-gallery-field="alt_text" aria-label="Alt text">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <button type="button" class="btn btn-sm {{ $isCover ? 'btn-primary' : 'btn-outline-primary' }}" data-gallery-action="cover">{{ $isCover ? 'Cover photo' : 'Make cover' }}</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-gallery-action="up" aria-label="Move up">&uarr;</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-gallery-action="down" aria-label="Move down">&darr;</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-gallery-action="remove">Remove</button>
                <span class="text-caption ms-2 d-none" data-gallery-status></span>
            </div>
        </div>
    </div>
</div>
