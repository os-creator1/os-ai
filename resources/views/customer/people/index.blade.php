@extends('layouts/contentLayoutMaster')

{{--
    Contacts → All contacts: every contact of the selected Business, person
    first (ContactDirectory). Groups are the secondary tab.
--}}

@section('title', 'Contacts')

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
    @endphp

    <section id="contacts-people">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-1 mb-2">
            @include('customer.people._tabs')

            @can('create_contact')
                <div class="d-flex gap-1" data-role="contacts-actions">
                    <x-button variant="outline" :href="route('customer.workspaces.businesses.people.import', [$workspaceUid, $businessUid])">Import</x-button>
                    <x-button variant="primary" :href="route('customer.workspaces.businesses.people.add', [$workspaceUid, $businessUid])">+ Add contact</x-button>
                </div>
            @endcan
        </div>

        <form method="GET" action="{{ route('customer.workspaces.businesses.people.index', [$workspaceUid, $businessUid]) }}" role="search" class="mb-2" data-role="contacts-search">
            <label for="contacts-search" class="visually-hidden">Search contacts</label>
            <input type="search" class="form-control" id="contacts-search" name="q" value="{{ $search }}" placeholder="Search by name, phone, email or company" maxlength="100">
        </form>

        @if ($contacts->total() === 0)
            @if ($search !== '')
                <x-empty-state icon="search" title="No contacts match “{{ $search }}”." description="Try a name, a phone number, an email or a company." />
            @else
                <x-empty-state icon="users" title="No contacts yet." description="Add your first contact, or import a list you already have." />
            @endif
        @else
            <x-card :padded="false">
                <x-table :headers="['Name', 'Phone', 'Email', 'Company', 'Group', 'Last activity', 'Added']">
                    @foreach ($contacts as $contact)
                        <tr data-role="contact-row">
                            <td>
                                <a class="fw-bolder" href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $contact['uid']]) }}">{{ $contact['name'] ?? $contact['phone'] }}</a>
                                @unless ($contact['subscribed'])
                                    <x-badge variant="neutral">Unsubscribed</x-badge>
                                @endunless
                            </td>
                            <td class="text-numeric">{{ $contact['phone'] }}</td>
                            <td>{{ $contact['email'] ?? '—' }}</td>
                            <td>{{ $contact['company'] ?? '—' }}</td>
                            <td>{{ $contact['group'] ?? '—' }}</td>
                            <td>{{ $contact['last_activity'] !== null ? $contact['last_activity']->diffForHumans() : '—' }}</td>
                            <td>{{ $contact['added']?->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>

            <x-pagination :paginator="$contacts" />
        @endif
    </section>
@endsection
