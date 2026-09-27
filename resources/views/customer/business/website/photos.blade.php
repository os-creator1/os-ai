@extends('layouts/contentLayoutMaster')

@section('title', 'Photos')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Photos</h4>
            <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid]) }}">Back to pages</x-button>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($errors->any())
        <x-alert variant="danger" class="mb-3">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <x-card title="Upload a photo" class="mb-3">
        <p class="text-caption">Real event photos only. Describe what each one shows — that description is what visitors using a screen reader will hear, and it's how you will recognize the photo below.</p>
        <form method="POST" enctype="multipart/form-data" action="{{ route('customer.workspaces.businesses.website.assets.store', [$workspaceUid, $businessUid]) }}">
            @csrf
            <div class="row align-items-end">
                <div class="col-md-5 mb-2"><label class="form-label" for="website-image">Photo</label><input id="website-image" name="image" type="file" accept="image/png,image/jpeg,image/webp" class="form-control" required></div>
                <div class="col-md-5 mb-2"><label class="form-label" for="website-alt-text">Describe the photo</label><input id="website-alt-text" name="alt_text" type="text" maxlength="160" class="form-control" placeholder="e.g. Guests using our photo booth" required></div>
                <div class="col-md-2 mb-2"><x-button type="submit" variant="secondary">Upload photo</x-button></div>
            </div>
        </form>
    </x-card>

    <x-card title="Build your Gallery page" class="mb-3">
        @if ($galleryPage)
            <p class="text-caption">
                Your Gallery page already shows {{ count($selectedUids) }} {{ count($selectedUids) === 1 ? 'photo' : 'photos' }}.
                <a href="{{ route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $galleryPage->uid]) }}">Edit the Gallery page</a>
                to change its title, description, or search visibility. Selecting different photos below and saving updates it.
            </p>
        @else
            <p class="text-caption">Select 1–24 photos below and save to create your Gallery page. It starts hidden from search until you review it.</p>
        @endif

        @if ($assets->isEmpty())
            <x-empty-state icon="image" title="No photos yet" description="Upload at least one real event photo above to build a Gallery page." />
        @else
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.gallery.store', [$workspaceUid, $businessUid]) }}">
                @csrf
                <div class="row">
                    @foreach ($assets as $asset)
                        <div class="col-6 col-md-3 mb-3">
                            <label class="d-block position-relative">
                                <input type="checkbox" name="asset_uids[]" value="{{ $asset->uid }}" class="form-check-input position-absolute m-2" style="top:0;left:0;z-index:1" @checked(in_array($asset->uid, $selectedUids, true))>
                                <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?: 'Uploaded photo' }}" width="100%" height="120" style="object-fit:cover;border-radius:8px;display:block">
                            </label>
                            <span class="text-caption d-block text-truncate" title="{{ $asset->alt_text }}">{{ $asset->alt_text ?: 'Uploaded photo' }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="text-caption">Choose up to 24 photos.</p>
                <x-button type="submit" variant="primary">{{ $galleryPage ? 'Update Gallery page' : 'Create Gallery page' }}</x-button>
            </form>
        @endif
    </x-card>
@endsection
