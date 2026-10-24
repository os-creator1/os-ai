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
                    <x-alert variant="warning" data-role="email-connection-revoked">Your email provider ended this connection. Reconnect to send email again.</x-alert>
                @elseif ($state === 'pending')
                    <p class="mb-2">A connection is in progress. If you did not finish it, start again below.</p>
                @endif

                @if ($canManage)
                    <div class="row" data-role="email-provider-options">
                        @foreach ($providerOptions as $option)
                            <div class="col-md-4 mb-1" data-role="email-provider-{{ $option['type'] }}">
                                <h6>{{ $option['title'] }}</h6>
                                <p class="text-muted small">{{ $option['help'] }}</p>
                                @if ($option['usable'])
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.email.connect', [$workspaceUid, $businessUid, $option['type']]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-primary">{{ $account !== null ? 'Reconnect ' . $option['title'] : $option['button'] }}</button>
                                    </form>
                                @else
                                    <x-alert variant="warning" data-role="email-provider-unavailable">{{ $option['unavailable'] }}</x-alert>
                                @endif
                            </div>
                        @endforeach

                        <div class="col-md-4 mb-1" data-role="email-provider-imap">
                            <h6>Other Email (IMAP / SMTP)</h6>
                            <p class="text-muted small">
                                Zoho Mail, Titan, Hostinger, cPanel hosting and other standard mailboxes. We always use an
                                encrypted connection and verify the server's certificate. Gmail / Google Workspace and
                                Outlook / Microsoft 365 do not accept ordinary passwords here, so use their buttons. Some
                                providers need IMAP access switched on, or an app password, in their mail settings.
                            </p>
                            <form method="POST" action="{{ route('customer.workspaces.businesses.email.connect_other', [$workspaceUid, $businessUid]) }}">
                                @csrf
                                <input class="form-control mb-1" type="email" name="email" placeholder="Email address" value="{{ old('email') }}" required>
                                <input class="form-control mb-1" type="text" name="username" placeholder="Username (blank = the email address)" value="{{ old('username') }}" autocomplete="off">
                                <input class="form-control mb-1" type="password" name="password" placeholder="Password or app password" autocomplete="new-password" required>
                                <div class="d-flex gap-1 mb-1">
                                    <input class="form-control" type="text" name="imap_host" placeholder="IMAP server (blank = detect)" value="{{ old('imap_host') }}">
                                    <input class="form-control" style="max-width:5.5rem" type="number" name="imap_port" placeholder="993" value="{{ old('imap_port') }}">
                                </div>
                                <div class="d-flex gap-1 mb-1">
                                    <input class="form-control" type="text" name="smtp_host" placeholder="SMTP server (blank = detect)" value="{{ old('smtp_host') }}">
                                    <input class="form-control" style="max-width:5.5rem" type="number" name="smtp_port" placeholder="465" value="{{ old('smtp_port') }}">
                                </div>
                                <div class="form-text mb-1">IMAP port 993 (or 143) and SMTP port 465 (or 587). The connection is tested before it is saved.</div>
                                <button type="submit" class="btn btn-primary">Connect Other Email</button>
                            </form>
                        </div>
                    </div>
                @else
                    <p class="text-muted mb-0">You do not have permission to connect or change the email account. Ask the account owner to connect it or to grant you access.</p>
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
                @if ($account->provider->value === 'imap' && $imapSummary !== null)
                    <p class="text-muted mb-2" data-role="email-imap-summary">Receiving: {{ $imapSummary['imap'] }} · Sending: {{ $imapSummary['smtp'] }} (encrypted, certificate verified)</p>
                @endif
                <p class="mb-2" data-role="email-health">
                    @if ($account->inbound_failure)
                        <span class="text-warning">Receiving problem:</span> {{ $inboundFailureText }}
                    @elseif ($account->inbound_synced_at)
                        Receiving is working. Last checked {{ $account->inbound_synced_at->diffForHumans() }}.
                    @elseif ($inboundReady)
                        Receiving is set up and will start shortly.
                    @endif
                    @if ((int) $account->inbound_skipped_count > 0)
                        <span class="text-muted">· {{ (int) $account->inbound_skipped_count }} message(s) could not be imported.</span>
                    @endif
                </p>
                @if ($account->failure_classification)
                    <x-alert variant="warning" data-role="email-last-send-failed">The last send had a problem. If it keeps happening, reconnect your account.</x-alert>
                @endif

                @if ($inboundNeedsReauth)
                    <x-alert variant="info" data-role="email-inbound-reauth">
                        Receiving replies from this mailbox needs one more permission from {{ $account->provider->value === 'microsoft' ? 'Microsoft' : 'Google' }}.
                        @if ($canManage)
                            <form method="POST" class="d-inline-block ms-2"
                                  action="{{ route('customer.workspaces.businesses.email.connect', [$workspaceUid, $businessUid, $account->provider->value]) }}">
                                @csrf
                                <input type="hidden" name="reauthorize" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-primary">Reauthorize to receive replies</button>
                            </form>
                        @else
                            Ask the account owner to reauthorize the connection.
                        @endif
                    </x-alert>
                @endif
                @if ($account->inbound_failure === 'reauthorization_required' && $inboundReady)
                    <x-alert variant="warning" data-role="email-inbound-failed">Your email provider no longer allows us to read this mailbox. Reauthorize the connection to keep receiving replies.</x-alert>
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
                            @if ($message->direction === 'inbound')
                                from {{ $message->from_email }} — received
                            @else
                                to {{ $message->to_email }} —
                            @endif
                            @if ($message->direction === 'inbound')
                            @elseif ($message->status->value === 'accepted')
                                accepted by your email provider
                            @elseif ($message->status->value === 'failed')
                                not sent: {{ $message->failure_category?->customerMessage() }}
                            @elseif ($message->status->value === 'unconfirmed')
                                could not be confirmed
                            @else
                                sending
                            @endif
                            <span class="text-muted">· {{ $message->created_at?->diffForHumans() }}</span>
                            @if ($canSend && $message->status->value !== 'failed')
                                <details class="mt-1">
                                    <summary>Reply</summary>
                                    <form method="POST" action="{{ route('customer.workspaces.businesses.email.reply', [$workspaceUid, $businessUid, $message->uid]) }}">
                                        @csrf
                                        <input type="hidden" name="send_token" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        <input class="form-control mb-1" type="text" name="subject" maxlength="200" required
                                               value="{{ \Illuminate\Support\Str::startsWith(strtolower($message->subject), 're:') ? $message->subject : 'Re: ' . $message->subject }}">
                                        <textarea class="form-control mb-1" name="body" rows="4" maxlength="20000" required></textarea>
                                        <button type="submit" class="btn btn-sm btn-primary">Send reply</button>
                                    </form>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
@endsection
