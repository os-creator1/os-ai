@extends('layouts/contentLayoutMaster')

@section('title', $editMode ? 'Save your updated answers' : 'Review your answers')

@section('page-style')
    @include('partials.section-router._styles')
    @unless ($editMode)
        @include('customer.business.website.wizard.steps._review-styles')
    @endunless
@endsection

@section('content')
    @if ($editMode)
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <x-flash-alert class="mb-3" />

                <div class="text-center">
                    <x-empty-state icon="sparkles" title="Save your updated answers" description="We'll update your business information, packages, services and backdrops from your changes. Your existing website pages will not be regenerated or overwritten.">
                        <x-slot name="action">
                            <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.generate', [$workspaceUid, $businessUid]) }}">
                                @csrf
                                <input type="hidden" name="answers_revision" value="{{ $answersRevision }}">
                                <x-button type="submit" variant="primary">Save changes</x-button>
                            </form>
                        </x-slot>
                    </x-empty-state>
                </div>
            </div>
        </div>
    @else
        @include('customer.business.website.wizard.steps._review-page')
    @endif

    @include('customer.business.website._generation-progress')
@endsection
