{{--
    SEO → Content for a Business that has no Website yet. Articles are published through the Website,
    so there is nothing to write into until one exists. A friendly state, never an error.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Content')

@section('content')
    <div class="row mb-1">
        <div class="col-12">
            <h4 class="mb-25">Content</h4>
            <p class="text-caption mb-0">Articles that help customers find {{ $business->name }}.</p>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    <x-card :padded="true" data-role="no-website">
        <x-empty-state icon="globe" title="Create your website first" description="Your articles are published on your website, so it needs to exist before you can write for it. It only takes a few minutes.">
            <x-slot name="action">
                <a class="btn btn-primary" href="{{ route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]) }}" data-role="create-website">Go to your website</a>
            </x-slot>
        </x-empty-state>
    </x-card>
@endsection
