@php
    $galleryAssets = $website->assets()->where('purpose', \App\Enums\Website\WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->get();
    $galleryRoute = fn (string $name, $uid = null) => route('customer.workspaces.businesses.website.setup.' . $name, array_filter([$workspaceUid, $businessUid, $uid], fn ($v) => $v !== null));
    $galleryCategories = $step['categories'] ?? null;
@endphp
<div data-gallery
     data-upload-url="{{ $galleryRoute('gallery.upload') }}"
     data-update-url="{{ $galleryRoute('gallery.update', '__UID__') }}"
     data-move-url="{{ $galleryRoute('gallery.move', '__UID__') }}"
     data-remove-url="{{ $galleryRoute('gallery.remove', '__UID__') }}"
     data-max="{{ \App\Library\Website\Gallery\WebsiteGalleryManager::MAX_GALLERY_ASSETS }}">
    <div class="border rounded p-3 mb-3" data-cover-note>
        <strong>Choose your homepage cover.</strong>
        <span class="text-caption d-block">The photo marked <em>Cover photo</em> is the large picture at the top of your homepage. Press <em>Make cover</em> on the one you want. Add a category or caption to any photo if you like.</span>
        @if ($galleryAssets->isNotEmpty() && ! $galleryAssets->contains('is_cover', true))
            <span class="d-block text-warning small mt-1" data-cover-missing>No cover chosen yet — until you choose one, your first photo is used.</span>
        @endif
    </div>
    <p class="text-caption mb-2"><span data-gallery-count>{{ $galleryAssets->count() }}</span> of {{ \App\Library\Website\Gallery\WebsiteGalleryManager::MAX_GALLERY_ASSETS }} photos</p>

    <div data-gallery-list>
        @foreach ($galleryAssets as $asset)
            @include('customer.business.website.wizard.steps._gallery-card', ['asset' => $asset, 'categories' => $galleryCategories])
        @endforeach
    </div>
    <template data-gallery-card-template>
        @include('customer.business.website.wizard.steps._gallery-card', ['asset' => null, 'categories' => $galleryCategories])
    </template>

    <label class="btn btn-outline-primary btn-sm mb-0">+ Add photos
        <input type="file" hidden multiple accept="image/png,image/jpeg,image/webp" data-gallery-input>
    </label>
    <div class="progress mt-2 d-none" style="height:4px;"><div class="progress-bar" data-upload-progress></div></div>
    <div class="text-danger small mt-1 d-none" data-upload-error></div>
</div>
