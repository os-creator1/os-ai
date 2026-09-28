@extends('layouts/contentLayoutMaster')

@section('title', 'Pages')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Pages</h4>
            <span>
                <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.photos.index', [$workspaceUid, $businessUid]) }}">Photos</x-button>
                <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]) }}">Forms</x-button>
                <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.domains.index', [$workspaceUid, $businessUid]) }}">Domains</x-button>
                <x-button variant="primary" href="{{ route('customer.workspaces.businesses.website.pages.create', [$workspaceUid, $businessUid]) }}">Add page</x-button>
            </span>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($pages->contains('noindex', true))
        <x-alert variant="accent" class="mb-3">Starter service and package pages are marked hidden from search. Add your own details and photos to each page before removing that setting. All public websites remain hidden from search until the platform's search launch.</x-alert>
    @endif

    @if ($isPhotoBooth)
        <x-card title="Photo Booth checklist" class="mb-3">
            <ul class="mb-2">
                <li>
                    Services: @if ($reusable['services']->isNotEmpty()) reused on your Services page ({{ $reusable['services']->pluck('name')->implode(', ') }}). Edit that page to describe your booth types, backdrops, props, and extras in your own words — that's real detail this page doesn't have yet. @else none saved — describe your booth options in the page editor. @endif
                </li>
                <li>
                    Packages: @if ($reusable['catalog']->isNotEmpty()) reused on your Packages page ({{ $reusable['catalog']->pluck('name')->implode(', ') }}). Edit that page to add what's included in each package beyond the saved price and description. @else none saved. <a href="{{ route('customer.workspaces.businesses.catalog.create', [$workspaceUid, $businessUid]) }}">Add a package</a>. @endif
                </li>
                <li>
                    Location: @if ($reusable['location']) Your public location{{ $reusable['location']->city ? ' in ' . $reusable['location']->city : '' }} is reused in your contact details. @else no active public location is available. <a href="{{ route('customer.workspaces.businesses.locations.index', [$workspaceUid, $businessUid]) }}">Review your locations</a>. @endif
                </li>
                <li>
                    Photos: {{ $photoCount }} {{ $photoCount === 1 ? 'photo' : 'photos' }} uploaded.
                    @if ($galleryPage)
                        <a href="{{ route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $galleryPage->uid]) }}">Edit your Gallery page</a>. Use "Add Gallery photos" below to reuse the same selected photos on your Services or Packages page.
                    @else
                        <a href="{{ route('customer.workspaces.businesses.website.photos.index', [$workspaceUid, $businessUid]) }}">Upload photos and build a Gallery page</a>.
                    @endif
                </li>
                <li>
                    Quote request form:
                    @if ($quoteForm)
                        {{ $quoteForm->submissions()->count() }} {{ $quoteForm->submissions()->count() === 1 ? 'inquiry' : 'inquiries' }} received. <a href="{{ route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]) }}">Manage your form</a>.
                    @else
                        not created yet. <a href="{{ route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]) }}">Create your quote request form</a>.
                    @endif
                </li>
            </ul>
        </x-card>
    @endif

    <x-card :padded="false">
        <div class="list-group list-group-flush">
            @forelse ($pages as $page)
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <span>
                        <strong>{{ $page->title }}</strong>
                        @if ($page->is_home)
                            <x-badge variant="accent">Home</x-badge>
                        @else
                            <span class="text-caption d-block">/{{ $page->slug }}</span>
                        @endif
                        @if ($page->noindex)
                            <x-badge variant="warning">Hidden from search</x-badge>
                        @endif
                    </span>
                    <span>
                        <x-button variant="ghost" size="sm" href="{{ route('customer.workspaces.businesses.website.preview', [$workspaceUid, $businessUid, $page->uid]) }}">Preview</x-button>
                        <x-button variant="secondary" size="sm" href="{{ route('customer.workspaces.businesses.website.pages.edit', [$workspaceUid, $businessUid, $page->uid]) }}">Edit</x-button>
                        @if ($isPhotoBooth && $galleryPage && $galleryPage->id !== $page->id && ! empty(collect($galleryPage->sections ?? [])->firstWhere('type', 'gallery')['data']['items'] ?? []))
                            <form method="POST" action="{{ route('customer.workspaces.businesses.website.pages.reuseGalleryPhotos', [$workspaceUid, $businessUid, $page->uid]) }}" class="d-inline">
                                @csrf
                                <x-button variant="ghost" size="sm" type="submit">Add Gallery photos</x-button>
                            </form>
                        @endif
                    </span>
                </div>
            @empty
                <div class="p-4">
                    <x-empty-state icon="file" title="No pages yet" description="Add your first page to get started." />
                </div>
            @endforelse
        </div>
    </x-card>
@endsection
