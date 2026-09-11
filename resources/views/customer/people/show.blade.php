@extends('layouts/contentLayoutMaster')

{{--
    A contact's profile: only what is stored (ContactDirectory::profile()) —
    identity and details from the group's fields, the group, when the contact
    was added, campaign messages and the conversation with this number.
    There is no form, source, notes or tags section because none of those is
    recorded for contacts today.
--}}

@section('title', $profile['name'] ?? $profile['phone'])

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
    @endphp

    <section id="contact-profile">
        <div class="mb-1">
            <a href="{{ route('customer.workspaces.businesses.people.index', [$workspaceUid, $businessUid]) }}">&larr; All contacts</a>
        </div>

        <div class="row">
            <div class="col-12 col-lg-4">
                <x-card>
                    <h3 class="mb-50" data-role="contact-name">{{ $profile['name'] ?? $profile['phone'] }}</h3>
                    @if ($profile['subscribed'])
                        <x-badge variant="success">Subscribed</x-badge>
                    @else
                        <x-badge variant="neutral">Unsubscribed</x-badge>
                    @endif

                    <dl class="row mt-2 mb-0" data-role="contact-identity">
                        <dt class="col-5">Phone</dt>
                        <dd class="col-7 text-numeric">{{ $profile['phone'] }}</dd>

                        @if ($profile['email'] !== null)
                            <dt class="col-5">Email</dt>
                            <dd class="col-7">{{ $profile['email'] }}</dd>
                        @endif

                        @if ($profile['company'] !== null)
                            <dt class="col-5">Company</dt>
                            <dd class="col-7">{{ $profile['company'] }}</dd>
                        @endif

                        @if ($profile['group'] !== null)
                            <dt class="col-5">Group</dt>
                            <dd class="col-7"><a href="{{ route('customer.workspaces.businesses.contacts.show', [$workspaceUid, $businessUid, $profile['group']['uid']]) }}">{{ $profile['group']['name'] }}</a></dd>
                        @endif

                        @if ($profile['added'] !== null)
                            <dt class="col-5">Added</dt>
                            <dd class="col-7">{{ $profile['added']->format('M j, Y') }}</dd>
                        @endif
                    </dl>

                    @if ($profile['group'] !== null)
                        @can('update_contact')
                            <div class="mt-2">
                                <x-button variant="outline" size="sm" :href="route('customer.workspaces.businesses.contact.edit', [$workspaceUid, $businessUid, $profile['group']['uid']]) . '?contact_id=' . urlencode($contactUid)">Edit details</x-button>
                            </div>
                        @endcan
                    @endif
                </x-card>
            </div>

            <div class="col-12 col-lg-8">
                @if (! empty($profile['details']))
                    <x-card title="Details">
                        <dl class="row mb-0" data-role="contact-details">
                            @foreach ($profile['details'] as $detail)
                                <dt class="col-sm-4">{{ $detail['label'] }}</dt>
                                <dd class="col-sm-8">{{ $detail['value'] }}</dd>
                            @endforeach
                        </dl>
                    </x-card>
                @endif

                <x-card title="Activity">
                    @if ($profile['conversation'] === null && empty($profile['campaigns']))
                        <p class="text-caption mb-0" data-role="contact-no-activity">No messages with this contact yet.</p>
                    @endif

                    @if ($profile['conversation'] !== null)
                        <div class="mb-2" data-role="contact-conversation">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <h5 class="mb-0">Conversation</h5>
                                <a href="{{ route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) }}">Open Inbox</a>
                            </div>
                            <ul class="list-unstyled mb-0">
                                @foreach ($profile['messages'] as $message)
                                    <li class="mb-50">
                                        <span class="text-caption">{{ $message['incoming'] ? 'Received' : 'Sent' }}{{ $message['at'] !== null ? ' · ' . $message['at']->format('M j, Y H:i') : '' }}</span>
                                        <div>{{ \Illuminate\Support\Str::limit($message['text'], 240) }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (! empty($profile['campaigns']))
                        <div data-role="contact-campaigns">
                            <h5 class="mb-1">Campaign messages</h5>
                            <x-table :headers="['Campaign', 'Status', 'Sent']">
                                @foreach ($profile['campaigns'] as $campaign)
                                    <tr>
                                        <td>{{ $campaign['name'] }}</td>
                                        <td>{{ $campaign['status'] ?? '—' }}</td>
                                        <td>{{ $campaign['at']?->format('M j, Y H:i') }}</td>
                                    </tr>
                                @endforeach
                            </x-table>
                        </div>
                    @endif
                </x-card>
            </div>
        </div>
    </section>
@endsection
