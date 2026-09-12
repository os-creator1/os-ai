{{--
    Contacts has two views of the selected Business's contacts: people first
    ("All contacts", the default) and groups second. Only on the
    Business-scoped pages — the legacy flat Contacts routes have no Business.
--}}
@php
    $contactsWorkspaceUid = request()->route('workspaceUid');
    $contactsBusinessUid = request()->route('businessUid');
    $onAllContacts = request()->routeIs('customer.workspaces.businesses.people.*');
@endphp

@if ($contactsWorkspaceUid !== null && $contactsBusinessUid !== null)
    <ul class="nav nav-tabs ds-tabs mb-0" data-role="contacts-tabs">
        <li class="nav-item">
            <a class="nav-link transition-fast @if ($onAllContacts) active @endif" @if ($onAllContacts) aria-current="page" @endif href="{{ route('customer.workspaces.businesses.people.index', [$contactsWorkspaceUid, $contactsBusinessUid]) }}">All contacts</a>
        </li>
        <li class="nav-item">
            <a class="nav-link transition-fast @unless ($onAllContacts) active @endunless" @unless ($onAllContacts) aria-current="page" @endunless href="{{ route('customer.workspaces.businesses.contacts.index', [$contactsWorkspaceUid, $contactsBusinessUid]) }}">Groups</a>
        </li>
    </ul>
@endif
