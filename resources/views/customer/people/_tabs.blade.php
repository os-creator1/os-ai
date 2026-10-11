{{--
    Contacts has two views of the selected Business's contacts: people first
    ("All contacts", the default) and groups second. Only on the
    Business-scoped pages — the legacy flat Contacts routes have no Business.
    `contactsTotal` (optional) is the unfiltered count, shown on its tab only
    when the caller actually knows it.
--}}
@php
    $contactsWorkspaceUid = request()->route('workspaceUid');
    $contactsBusinessUid = request()->route('businessUid');
    $onAllContacts = request()->routeIs('customer.workspaces.businesses.people.*');
@endphp

@if ($contactsWorkspaceUid !== null && $contactsBusinessUid !== null)
    @include('partials.cx._styles')
    <nav class="cx-tabs mb-0" aria-label="Contacts" data-role="contacts-tabs">
        <a class="transition-fast @if ($onAllContacts) is-active @endif" @if ($onAllContacts) aria-current="page" @endif href="{{ route('customer.workspaces.businesses.people.index', [$contactsWorkspaceUid, $contactsBusinessUid]) }}">
            All contacts
            @isset($contactsTotal)<span class="cx-count">{{ number_format($contactsTotal) }}</span>@endisset
        </a>
        <a class="transition-fast @unless ($onAllContacts) is-active @endunless" @unless ($onAllContacts) aria-current="page" @endunless href="{{ route('customer.workspaces.businesses.contacts.index', [$contactsWorkspaceUid, $contactsBusinessUid]) }}">Groups</a>
    </nav>
@endif
