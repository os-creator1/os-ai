@extends('layouts/contentLayoutMaster')

@section('title', 'Website')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <x-flash-alert class="mb-3" />

            <x-empty-state icon="globe" title="Build your website" description="Answer a few quick questions and we'll generate a complete, SEO-ready website for you.">
                <x-slot name="action">
                    <x-button variant="primary" href="{{ route('customer.workspaces.businesses.website.setup.start', [$workspaceUid, $businessUid]) }}">Start building</x-button>
                </x-slot>
            </x-empty-state>
        </div>
    </div>
@endsection
