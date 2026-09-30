@extends('layouts/contentLayoutMaster')

@section('title', 'Generate your website')

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-6 text-center">
            <x-flash-alert class="mb-3" />

            <x-empty-state icon="sparkles" title="Ready to generate your website" description="We'll build your complete, SEO-ready website from your answers. This takes a few moments.">
                <x-slot name="action">
                    <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.generate', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <x-button type="submit" variant="primary">Generate my website</x-button>
                    </form>
                </x-slot>
            </x-empty-state>
        </div>
    </div>
@endsection
