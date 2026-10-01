@extends('layouts/contentLayoutMaster')

@section('title', 'Email')

@section('content')
    @php
        // Presentation only. Tenancy, permission and which account this is
        // were all decided before this view rendered; the account is always
        // the one belonging to the resolved Business.
        $state = $account?->state?->value;
        $connectedLabel = $account?->provider?->label();
    @endphp

    <div class="card">
        <div class="card-body">
            <h4 class="card-title">Your business email</h4>
            <p class="card-text text-muted">
                Connect the email account you already use for your business. Emails you send to your contacts
                go out from that account, so replies reach the inbox you already read.
            </p>

            @if ($account === null || in_array($state, ['disconnected', 'revoked', 'pending'], true))
                @if ($state === 'revoked')
                    <div class="alert alert-warning">
                        Your email provider ended this connection. Reconnect to send email again.
                    </div>
                @elseif ($state === 'pending')
                    <p class="mb-2">A connection is in progress. If you did not finish it, start again below.</p>
                @endif

                @if ($canManage)
                    @forelse ($providers as $provider)
                        <form method="POST" class="d-inline-block me-2"
                              action="{{ route('customer.workspaces.businesses.email.connect', [$workspaceUid, $businessUid, $provider->value]) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">
                                {{ $account !== null ? 'Reconnect' : 'Connect' }} {{ $provider->label() }}
                            </button>
                        </form>
                    @empty
                        <p class="text-muted mb-0">Email connection is not available right now. Please contact support.</p>
                    @endforelse
                @else
                    <p class="text-muted mb-0">Ask the account owner to connect an email account.</p>
                @endif
            @else
                <p class="mb-2">
                    Connected to <strong>{{ $connectedLabel }}</strong>
                    @if ($account->mailbox_email)
                        as <strong>{{ $account->mailbox_email }}</strong>
                    @endif
                    .
                </p>
                @if ($account->connected_at)
                    <p class="text-muted mb-2">Connected {{ $account->connected_at->diffForHumans() }}.</p>
                @endif
                @if ($account->failure_classification)
                    <div class="alert alert-warning">
                        The last send had a problem. If it keeps happening, reconnect your account.
                    </div>
                @endif

                @if ($canManage)
                    <form method="POST" action="{{ route('customer.workspaces.businesses.email.disconnect', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger">Disconnect</button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    @if ($canSend)
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Send an email to a contact</h5>

                @if (count($contacts) === 0)
                    <p class="text-muted mb-0">None of your contacts has an email address yet.</p>
                @else
                    <form method="POST" action="{{ route('customer.workspaces.businesses.email.send', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <input type="hidden" name="send_token" value="{{ $sendToken }}">

                        <div class="mb-1">
                            <label class="form-label" for="email-contact">Contact</label>
                            <select class="form-select" id="email-contact" name="contact_uid" required>
                                @foreach ($contacts as $contact)
                                    <option value="{{ $contact['uid'] }}">{{ $contact['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($locationChoices->isNotEmpty())
                            <div class="mb-1">
                                <label class="form-label" for="email-location">Send from location</label>
                                <select class="form-select" id="email-location" name="location_uid">
                                    <option value="">The contact's own location</option>
                                    @foreach ($locationChoices as $choice)
                                        <option value="{{ $choice->uid }}">{{ $choice->name }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">Needed when the contact has no location of its own and your business has more than one.</div>
                            </div>
                        @endif
                        <div class="mb-1">
                            <label class="form-label" for="email-subject">Subject</label>
                            <input class="form-control" id="email-subject" type="text" name="subject" maxlength="200" required>
                        </div>
                        <div class="mb-1">
                            <label class="form-label" for="email-body">Message</label>
                            <textarea class="form-control" id="email-body" name="body" rows="6" maxlength="20000" required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Send email</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    @if ($messages->isNotEmpty() && ($canManage || $canSend))
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Recent emails</h5>
                <ul class="list-unstyled mb-0">
                    @foreach ($messages as $message)
                        <li class="mb-1">
                            <strong>{{ $message->subject }}</strong>
                            to {{ $message->to_email }} —
                            @if ($message->status->value === 'accepted')
                                accepted by your email provider
                            @elseif ($message->status->value === 'failed')
                                not sent: {{ $message->failure_category?->customerMessage() }}
                            @elseif ($message->status->value === 'unconfirmed')
                                could not be confirmed
                            @else
                                sending
                            @endif
                            <span class="text-muted">· {{ $message->created_at?->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
@endsection
