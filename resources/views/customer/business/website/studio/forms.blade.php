{{--
    Website Studio -> Forms: a light overview of the Business's real WebsiteForm rows. Full management (fields,
    location, "Add to a page", creating a form for another location) lives on the canonical Forms screen, which this
    links to - one implementation, the same data.
--}}
@php
    $formsUrl = route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]);
@endphp
@if ($forms->isEmpty())
    <div class="wb-empty" data-testid="forms-empty">
        <span class="wb-tile" aria-hidden="true"><x-ds-icon name="clipboard-list" size="28" /></span>
        <h2>No quote form yet</h2>
        <p>Add a quote request form so visitors can ask about dates and prices. Answers land in Contacts.</p>
        <div class="wb-empty-actions">
            <x-button variant="primary" href="{{ $formsUrl }}">Create quote form</x-button>
        </div>
    </div>
@else
    <div class="mg-pkg-head">
        <p class="mg-pkg-sub">{{ $forms->count() }} {{ $forms->count() === 1 ? 'form' : 'forms' }}. Edit them, or add one to a page, on the Forms screen.</p>
        <x-button variant="secondary" href="{{ $formsUrl }}">Manage forms</x-button>
    </div>
    <div class="wb-list">
        @foreach ($forms as $form)
            <article class="wb-list-card">
                <h2>{{ $form->name }}</h2>
                <p>
                    @if ($form->is_active)<x-badge variant="success">Active</x-badge>@else<x-badge variant="neutral">Not accepting submissions</x-badge>@endif
                    {{ count($form->fields) }} {{ count($form->fields) === 1 ? 'field' : 'fields' }}
                    @if ($form->location) &middot; {{ $form->location->name }} @endif
                    &middot; {{ $form->submissions_count }} {{ $form->submissions_count === 1 ? 'inquiry' : 'inquiries' }}
                </p>
                <div class="wb-list-actions">
                    <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.forms.submissions', [$workspaceUid, $businessUid, $form->uid]) }}">View submissions</x-button>
                    <x-button variant="ghost" href="{{ route('customer.workspaces.businesses.website.forms.edit', [$workspaceUid, $businessUid, $form->uid]) }}">Edit form</x-button>
                </div>
            </article>
        @endforeach
    </div>
@endif
