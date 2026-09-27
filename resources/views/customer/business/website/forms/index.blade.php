@extends('layouts/contentLayoutMaster')

@section('title', 'Forms')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Forms</h4>
            <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.pages.index', [$workspaceUid, $businessUid]) }}">Back to pages</x-button>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($form)
        <x-card title="Quote Request form" class="mb-3">
            <p class="text-caption mb-2">This form asks for: {{ collect($form->fields)->pluck('label')->implode(', ') }}.</p>
            <p class="text-caption mb-2">To publish it, add a <strong>Form</strong> section to a page in the page editor and it will offer this form automatically.</p>
            <x-button variant="primary" href="{{ route('customer.workspaces.businesses.website.forms.submissions', [$workspaceUid, $businessUid, $form->uid]) }}">View inquiries</x-button>
        </x-card>
    @else
        <x-card title="Quote Request form" class="mb-3">
            <p class="text-caption mb-2">Create a Photo Booth quote request form so visitors can ask for a quote directly from your website. It asks for a name, phone number, email, event date, event type, and a message — a real submission always shows up here and, if you already use the CRM, in your pipeline too.</p>
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.forms.store', [$workspaceUid, $businessUid]) }}">
                @csrf
                <x-button type="submit" variant="primary">Create quote request form</x-button>
            </form>
        </x-card>
    @endif
@endsection
