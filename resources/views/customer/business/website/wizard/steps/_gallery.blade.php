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
