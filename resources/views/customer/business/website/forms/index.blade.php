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

    @foreach ($forms as $form)
        <x-card :title="$form->name" class="mb-3">
            <p class="text-caption mb-2">
                @if ($form->is_active)
                    <x-badge variant="success">Active</x-badge>
                @else
                    <x-badge variant="secondary">Not accepting submissions</x-badge>
                @endif
                @if ($form->location)
                    Inquiries are filed under <strong>{{ $form->location->name }}</strong>.
                @else
                    <x-badge variant="warning">No location</x-badge> Choose a location before this form can accept inquiries.
                @endif
            </p>
            <p class="text-caption mb-2">This form asks for: {{ collect($form->fields)->pluck('label')->implode(', ') }}.</p>
            <p class="text-caption mb-2">To publish it, add a <strong>Form</strong> section to a page in the page editor and it will offer this form automatically. {{ $form->submissions_count }} {{ $form->submissions_count === 1 ? 'inquiry' : 'inquiries' }} received.</p>
            <x-button variant="primary" href="{{ route('customer.workspaces.businesses.website.forms.submissions', [$workspaceUid, $businessUid, $form->uid]) }}">View inquiries</x-button>
            <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.forms.edit', [$workspaceUid, $businessUid, $form->uid]) }}">Edit form</x-button>
        </x-card>
    @endforeach

    @if ($isPhotoBooth && $locations->isNotEmpty())
        <x-card title="Add a quote request form" class="mb-3">
            <p class="text-caption mb-2">Create a Photo Booth quote request form so visitors can ask for a quote directly from your website. It asks for a name, phone number, email, event date, event type, and a message — a real submission always shows up here and, if you already use the CRM, in your pipeline too. Each location has its own form, so its inquiries and leads belong to that location.</p>
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.forms.store', [$workspaceUid, $businessUid]) }}">
                @csrf
                @if ($locations->count() > 1)
                    <div class="mb-3">
                        <label for="location_uid" class="form-label text-label">Location</label>
                        <select name="location_uid" id="location_uid" class="form-select" required>
                            <option value="">Choose a location</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->uid }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                        @error('location_uid')<div class="text-danger text-caption">{{ $message }}</div>@enderror
                    </div>
                @endif
                <x-button type="submit" variant="primary">Create quote request form</x-button>
            </form>
        </x-card>
    @elseif (! $isPhotoBooth && $forms->isEmpty())
        <x-empty-state icon="inbox" title="No forms available yet" description="This slice ships one preset — a Photo Booth quote request — for Photo Booth businesses. A form for your business type isn't available yet." />
    @endif
@endsection
