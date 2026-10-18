@extends('layouts/contentLayoutMaster')

@section('title', 'Website')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <x-flash-alert class="mb-3" />

            <x-empty-state icon="globe" title="Create your website" description="Answer a few questions and we'll build the first draft for you.">
                <x-slot name="action">
                    <x-button variant="primary" href="{{ route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]) }}">Create my website</x-button>
                </x-slot>
            </x-empty-state>
        </div>
    </div>
@endsection
