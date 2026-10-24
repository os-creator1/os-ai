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

    @php
        $isConnectFlow = $account === null || in_array($state, ['disconnected', 'revoked', 'pending'], true);
        $accountProvider = $account?->provider?->value;
        $pageBadge = match (true) {
            $state === 'pending' => 'Connection in progress',
            $state === 'revoked' => 'Needs reconnecting',
            $state === 'active' && ($inboundNeedsReauth || $account->inbound_failure === 'reauthorization_required') => 'Needs reauthorization',
            $state === 'active' => 'Connected',
            default => 'Not connected',
        };
        $pillFor = function (string $type, bool $usable) use ($state, $accountProvider): array {
            if ($accountProvider === $type && $state === 'pending') {
                return ['In progress', 'warn'];
            }
            if ($accountProvider === $type && $state === 'revoked') {
                return ['Needs reauth', 'warn'];
            }
            if (! $usable) {
                return ['Not available yet', 'muted'];
            }

            return ['Not connected', 'muted'];
        };
    @endphp

    <style>
        .bemail { max-width: 62rem; }
        .bemail-head { display: flex; align-items: center; flex-wrap: wrap; gap: .6rem; margin-bottom: .35rem; }
        .bemail-head h4 { margin: 0; }
        .bemail-pill { display: inline-block; padding: .15rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 600; background: #f1ede6; color: #5c564d; white-space: nowrap; }
        .bemail-pill.warn { background: #fbefd0; color: #7a5600; }
        .bemail-pill.ok { background: #dff3e4; color: #1e6b34; }
        .bemail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        .bemail-card { background: #fff; border: 1px solid #e6e1d8; border-radius: .9rem; padding: 1.25rem; }
        .bemail-card-top { display: flex; align-items: flex-start; gap: .75rem; margin-bottom: .9rem; }
        .bemail-card-top .bemail-icon { flex: none; width: 2.5rem; height: 2.5rem; border-radius: .6rem; background: #f6efe9; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #a8453a; }
        .bemail-card-top .bemail-title { flex: 1; min-width: 0; }
        .bemail-card-top .bemail-title strong { display: block; }
        .bemail-card-top .bemail-title span { font-size: .8rem; color: #6f695f; }
        .bemail-note { font-size: .82rem; color: #6f695f; margin: 0 0 .5rem; }
        .bemail-notice { background: #fdf3df; border: 1px solid #f1dfb4; border-radius: .75rem; padding: .8rem 1rem; margin-bottom: 1rem; }
        .bemail-notice strong { display: block; }
        .bemail-notice span { font-size: .85rem; color: #6f695f; }
        .bemail-chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; }
        .bemail-chips span { font-size: .75rem; padding: .15rem .6rem; border-radius: 999px; border: 1px solid #e6e1d8; background: #f8f6f2; color: #5c564d; }
        .bemail-other { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 1.5rem; }
        .bemail-row { display: flex; gap: .75rem; }
        .bemail-row .bemail-port { flex: none; width: 6rem; }
        .bemail-field-error { color: #b3261e; font-size: .8rem; margin-top: .2rem; }
        .bemail-actions { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; margin-top: .75rem; }
        @media (max-width: 767px) { .bemail-grid, .bemail-other { grid-template-columns: 1fr; } }
    </style>

    <div class="bemail">
        <div class="bemail-head">
            <h4>Business email</h4>
            <span class="bemail-pill {{ $state === 'active' && $pageBadge === 'Connected' ? 'ok' : ($state === 'pending' || $state === 'revoked' || $pageBadge === 'Needs reauthorization' ? 'warn' : '') }}" data-role="email-status-badge">{{ $pageBadge }}</span>
        </div>
        <p class="text-muted mb-2">
            Connect the email account you already use. Emails to your contacts go out from it, so replies land in the inbox you already read.
        </p>

        @if ($isConnectFlow)
            @if ($state === 'revoked')
                <x-alert variant="warning" data-role="email-connection-revoked">Your email provider ended this connection. Reconnect to send email again.</x-alert>
            @elseif ($state === 'pending')
                <div class="bemail-notice" data-role="email-connection-pending">
                    <strong>Your {{ $accountProvider === 'microsoft' ? 'Microsoft' : 'Google' }} connection isn’t finished</strong>
                    <span>If you closed the {{ $accountProvider === 'microsoft' ? 'Microsoft' : 'Google' }} window before approving access, start again with Reconnect {{ $accountProvider === 'microsoft' ? 'Microsoft' : 'Google' }}.</span>
                </div>
            @endif

            @if ($canManage)
                <div class="bemail-grid" data-role="email-provider-options">
                    @foreach ($providerOptions as $option)
                        @php [$pillText, $pillClass] = $pillFor($option['type'], $option['usable']); @endphp
                        <div class="bemail-card" data-role="email-provider-{{ $option['type'] }}">
                            <div class="bemail-card-top">
                                <div class="bemail-icon">{{ substr($option['title'], 0, 1) }}</div>
                                <div class="bemail-title">
                                    <strong>{{ $option['title'] }}</strong>
                                    <span>{{ rtrim($option['help'], '.') }}</span>
                                </div>
                                <span class="bemail-pill {{ $pillClass }}">{{ $pillText }}</span>
                            </div>
                            @if ($option['usable'])
                                <p class="bemail-note">You sign in and approve access on {{ $option['title'] }}’s own page. MotionGrove never sees your {{ $option['title'] }} password.</p>
                                <form method="POST" action="{{ route('customer.workspaces.businesses.email.connect', [$workspaceUid, $businessUid, $option['type']]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary">{{ $account !== null && $accountProvider === $option['type'] ? 'Reconnect ' . $option['title'] : $option['button'] }}</button>
                                </form>
                            @else
                                <p class="bemail-note" data-role="email-provider-unavailable">{{ $option['unavailable'] }}</p>
                                <a class="btn btn-outline-secondary" href="mailto:{{ config('mail.from.address') }}">Contact support</a>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="bemail-card" data-role="email-provider-imap">
                    <div class="bemail-card-top">
                        <div class="bemail-icon">@</div>
                        <div class="bemail-title">
                            <strong>Other email</strong>
                            <span>Any standard mailbox, using IMAP and SMTP</span>
                        </div>
                    </div>
                    <div class="bemail-other">
                        <div>
                            <div class="bemail-chips">
                                <span>Zoho Mail</span><span>Titan</span><span>Hostinger</span><span>cPanel hosting</span><span>+ others</span>
                            </div>
                            <p class="bemail-note">We always use an encrypted connection and check the server’s certificate.</p>
                            <p class="bemail-note">Using Gmail or Outlook? Use the Google or Microsoft option above — they don’t accept ordinary passwords here.</p>
                            <p class="bemail-note">Some providers need IMAP turned on, or an app password, in their mail settings first.</p>
                        </div>
                        <form method="POST" action="{{ route('customer.workspaces.businesses.email.connect_other', [$workspaceUid, $businessUid]) }}">
                            @csrf
                            <div class="mb-1">
                                <label class="form-label" for="other-email">Email address</label>
                                <input class="form-control" id="other-email" type="email" name="email" placeholder="you@yourbusiness.com" value="{{ old('email') }}" required>
                                @error('email')<div class="bemail-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-1">
                                <label class="form-label" for="other-username">Username (optional)</label>
                                <input class="form-control" id="other-username" type="text" name="username" value="{{ old('username') }}" autocomplete="off">
                                <div class="form-text">Leave blank to use the email address.</div>
                                @error('username')<div class="bemail-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-1">
                                <label class="form-label" for="other-password">Password or app password</label>
                                <input class="form-control" id="other-password" type="password" name="password" autocomplete="new-password" required>
                                @error('password')<div class="bemail-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-1">
                                <div class="bemail-row">
                                    <div class="flex-grow-1">
                                        <label class="form-label" for="other-imap-host">IMAP server (incoming)</label>
                                        <input class="form-control" id="other-imap-host" type="text" name="imap_host" placeholder="Detect automatically" value="{{ old('imap_host') }}">
                                    </div>
                                    <div class="bemail-port">
                                        <label class="form-label" for="other-imap-port">Port</label>
                                        <input class="form-control" id="other-imap-port" type="number" name="imap_port" placeholder="993" value="{{ old('imap_port') }}">
                                    </div>
                                </div>
                                @error('imap_host')<div class="bemail-field-error">{{ $message }}</div>@enderror
                                @error('imap_port')<div class="bemail-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-1">
                                <div class="bemail-row">
                                    <div class="flex-grow-1">
                                        <label class="form-label" for="other-smtp-host">SMTP server (outgoing)</label>
                                        <input class="form-control" id="other-smtp-host" type="text" name="smtp_host" placeholder="Detect automatically" value="{{ old('smtp_host') }}">
                                    </div>
                                    <div class="bemail-port">
                                        <label class="form-label" for="other-smtp-port">Port</label>
                                        <input class="form-control" id="other-smtp-port" type="number" name="smtp_port" placeholder="465" value="{{ old('smtp_port') }}">
                                    </div>
                                </div>
                                @error('smtp_host')<div class="bemail-field-error">{{ $message }}</div>@enderror
                                @error('smtp_port')<div class="bemail-field-error">{{ $message }}</div>@enderror
                                <div class="form-text">IMAP usually uses port 993 or 143. SMTP 465 or 587.</div>
                            </div>
                            <div class="bemail-actions">
                                <button type="submit" class="btn btn-primary">Test and connect</button>
                                <span class="bemail-note mb-0">We test the connection before saving it.</span>
                            </div>
                        </form>
                    </div>
                </div>
            @else
                <p class="text-muted mb-0">You do not have permission to connect or change the email account. Ask the account owner to connect it or to grant you access.</p>
            @endif
            @else
                <div class="bemail-card mb-1">
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
                </div>
            @endif
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
