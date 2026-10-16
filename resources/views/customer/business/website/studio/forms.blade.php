{{--
    Website Studio -> Forms: a light overview of the Business's real WebsiteForm rows. Full management (fields,
    location, "Add to a page", creating a form for another location) lives on the canonical Forms screen, which this
    links to - one implementation, the same data.
--}}
@php
    $formsUrl = route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]);
@endphp
@if ($forms->isEmpty())
    <x-empty-state icon="clipboard-list" title="No forms yet" description="Create your quote request form to start receiving inquiries from your website.">
    <x-slot:action>
        <x-button variant="primary" href="{{ $formsUrl }}">Set up forms</x-button>
    </x-slot:action>
    </x-empty-state>
@else
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <p class="mb-0 text-caption">{{ $forms->count() }} {{ $forms->count() === 1 ? 'form' : 'forms' }}. Edit them, or add one to a page, on the Forms screen.</p>
        <x-button variant="secondary" href="{{ $formsUrl }}">Manage forms</x-button>
    </div>
    <div class="row">
        @foreach ($forms as $form)
            <div class="col-md-6 mb-3">
                <x-card :title="$form->name">
                    <p class="text-caption mb-2">
                        @if ($form->is_active)<x-badge variant="success">Active</x-badge>@else<x-badge variant="neutral">Not accepting submissions</x-badge>@endif
                        {{ count($form->fields) }} {{ count($form->fields) === 1 ? 'field' : 'fields' }}
                        @if ($form->location) &middot; {{ $form->location->name }} @endif
                        &middot; {{ $form->submissions_count }} {{ $form->submissions_count === 1 ? 'inquiry' : 'inquiries' }}
                    </p>
                    <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.forms.submissions', [$workspaceUid, $businessUid, $form->uid]) }}">View submissions</x-button>
                    <x-button variant="ghost" href="{{ route('customer.workspaces.businesses.website.forms.edit', [$workspaceUid, $businessUid, $form->uid]) }}">Edit form</x-button>
                </x-card>
            </div>
        @endforeach
    </div>
@endif
