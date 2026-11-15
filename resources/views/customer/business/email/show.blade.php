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
        .bemail-pill { display: inline-block; padding: .15rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 600; background: var(--color-surface-secondary); color: var(--color-text-secondary); border: 1px solid var(--color-border); white-space: nowrap; }
        .bemail-pill.warn { background: var(--color-status-warning-soft-bg); color: var(--color-status-warning-text); border-color: var(--color-status-warning-border); }
        .bemail-pill.ok { background: var(--color-status-success-soft-bg); color: var(--color-status-success-text); border-color: var(--color-status-success-border); }
        .bemail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        .bemail-card { background: var(--color-surface); color: var(--color-text-primary); border: 1px solid var(--color-border); border-radius: .9rem; padding: 1.25rem; }
        .bemail-card-top { display: flex; align-items: flex-start; gap: .75rem; margin-bottom: .9rem; }
        .bemail-card-top .bemail-icon { flex: none; width: 2.5rem; height: 2.5rem; border-radius: .6rem; background: var(--color-primary-soft-bg); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--color-primary); }
        .bemail-card-top .bemail-title { flex: 1; min-width: 0; }
        .bemail-card-top .bemail-title strong { display: block; color: var(--color-text-primary); }
        .bemail-card-top .bemail-title span { font-size: .8rem; color: var(--color-text-muted); }
        .bemail-note { font-size: .82rem; color: var(--color-text-muted); margin: 0 0 .5rem; }
        .bemail-notice { background: var(--color-status-warning-soft-bg); color: var(--color-status-warning-text); border: 1px solid var(--color-status-warning-border); border-radius: .75rem; padding: .8rem 1rem; margin-bottom: 1rem; }
        .bemail-notice strong { display: block; }
        .bemail-notice span { font-size: .85rem; color: var(--color-text-secondary); }
        .bemail-chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; }
        .bemail-chips span { font-size: .75rem; padding: .15rem .6rem; border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-surface-secondary); color: var(--color-text-secondary); }
        .bemail-other { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 1.5rem; }
        .bemail-row { display: flex; gap: .75rem; }
        .bemail-row .bemail-port { flex: none; width: 6rem; }
        .bemail-field-error { color: var(--color-status-danger-text); font-size: .8rem; margin-top: .2rem; }
        .bemail-actions { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem; margin-top: .75rem; }
        .bemail-card .form-label { color: var(--color-text-primary); }
        .bemail-card .form-text { color: var(--color-text-muted); }
        .bemail-card .form-control { background-color: var(--color-surface); color: var(--color-text-primary); border-color: var(--color-border); }
        .bemail-card .form-control::placeholder { color: var(--color-text-muted); opacity: 1; }
        .bemail-card .form-control:focus { border-color: var(--color-primary); }
        .bemail-card .btn-outline-secondary { color: var(--color-text-primary); border-color: var(--color-border-strong); }
        .bemail-card .btn-outline-secondary:hover { background: var(--color-surface-secondary); color: var(--color-text-primary); }
        .bemail-card .btn-primary { background-color: var(--color-primary); border-color: var(--color-primary); color: var(--color-text-inverse); }
        .bemail-card .btn-primary:hover { background-color: var(--color-primary-hover); border-color: var(--color-primary-hover); }
        @media (max-width: 767px) { .bemail-grid, .bemail-other { grid-template-columns: 1fr; } }
    </style>

    <link rel="stylesheet" href="{{ asset('css/email/settings-redesign.css') }}?v={{ filemtime(public_path('css/email/settings-redesign.css')) }}">

    <div class="bemail mg-email">
        <header class="me-head">
            <a class="me-back" href="{{ route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]) }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
                Settings
            </a>
            <h1 class="me-heading">Email</h1>
            <p class="me-subheading">Emails to contacts go out from your own inbox, so replies land where you already read.</p>
            @if ($isConnectFlow)
                <span class="bemail-pill {{ in_array($state, ['pending', 'revoked'], true) ? 'warn' : '' }}" data-role="email-status-badge" style="align-self:flex-start">{{ $pageBadge }}</span>
            @endif
        </header>        @if ($isConnectFlow)
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
                                <span>Zoho Mail</span><span>Namecheap Private Email</span><span>Titan</span><span>Hostinger</span><span>cPanel hosting</span><span>+ others</span>
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
                @php
                    // "Connected" means the account is active, not necessarily that mail has been delivered or synced.
                    $sendFailed = (bool) $account->failure_classification;
                    $hasAcceptedSend = $messages->contains(fn ($message) => $message->direction === 'outbound' && $message->status->value === 'accepted');
                    $receivingHealthy = $inboundReady && $account->inbound_synced_at !== null && ! $account->inbound_failure;
                @endphp
                <section class="me-card me-account" aria-label="Connected email account" data-role="email-account">
                    <span class="me-mail-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>
                        </svg>
                    </span>
                    <div class="me-account-info">
                        <div class="me-account-line">
                            <strong>{{ $account->mailbox_email ?: 'Connected email account' }}</strong>
                            <span class="me-provider">
                                Connected to <strong>{{ $connectedLabel }}</strong>
                                @if ($account->connected_at)
                                    · connected {{ $account->connected_at->diffForHumans() }}
                                @endif
                            </span>
                            <span class="sr-only" data-role="email-status-badge">{{ $pageBadge }}</span>
                        </div>
                        <div class="me-states" data-role="email-health">
                            @if ($sendFailed)
                                <span class="me-state err" title="The last send had a problem."><span aria-hidden="true">!</span> Sending needs attention</span>
                            @elseif ($hasAcceptedSend)
                                <span class="me-state"><span aria-hidden="true">✓</span> Sending · provider accepted</span>
                            @else
                                <span class="me-state muted"><span aria-hidden="true">✓</span> Sending ready</span>
                            @endif
                            @if ($receivingHealthy)
                                <span class="me-state" title="Receiving is working. Last checked {{ $account->inbound_synced_at->diffForHumans() }}.">
                                    <span aria-hidden="true">✓</span> Receiving · checked {{ $account->inbound_synced_at->diffForHumans() }}
                                </span>
                            @elseif ($account->inbound_failure)
                                <span class="me-state err">Receiving needs attention</span>
                            @elseif ($inboundNeedsReauth)
                                <span class="me-state warn">Receiving needs authorization</span>
                            @elseif ($inboundReady)
                                <span class="me-state warn">Receiving · awaiting first check</span>
                            @else
                                <span class="me-state muted">Receiving not available</span>
                            @endif
                            @if ((int) $account->inbound_skipped_count > 0)
                                <span class="me-state warn">{{ (int) $account->inbound_skipped_count }} messages not imported</span>
                            @endif
                        </div>
                    </div>
                    @if ($canManage)
                        <details class="me-account-more" data-role="email-account-menu">
                            <summary aria-label="More email account options" title="Account options">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>
                                </svg>
                            </summary>
                            <div class="me-account-menu">
                                @if ($inboundNeedsReauth || $account->inbound_failure === 'reauthorization_required')
                                    @if (in_array($account->provider->value, ['google', 'microsoft'], true))
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.email.connect', [$workspaceUid, $businessUid, $account->provider->value]) }}">
                                            @csrf
                                            <input type="hidden" name="reauthorize" value="1">
                                            <button type="submit">Reauthorize to receive replies</button>
                                        </form>
                                    @endif
                                @endif
                                <form method="POST" action="{{ route('customer.workspaces.businesses.email.disconnect', [$workspaceUid, $businessUid]) }}">
                                    @csrf
                                    <button type="submit">Disconnect</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </section>

                @if ($account->provider->value === 'imap' && $imapSummary !== null)
                    <div class="me-alert" data-role="email-imap-summary">
                        Receiving: {{ $imapSummary['imap'] }} · Sending: {{ $imapSummary['smtp'] }} (encrypted, certificate verified)
                    </div>
                @endif
                @if ($sendFailed)
                    <div class="me-alert" data-role="email-last-send-failed">The last send had a problem. If it keeps happening, reconnect your account.</div>
                @endif
                @if ($inboundNeedsReauth)
                    <div class="me-alert" data-role="email-inbound-reauth">
                        <strong>Receiving replies needs another permission</strong>
                        {{ $account->provider->value === 'microsoft' ? 'Microsoft' : 'Google' }} needs you to approve reading this inbox.
                        @if ($canManage)
                            @if (in_array($account->provider->value, ['google', 'microsoft'], true))
                                <form method="POST" action="{{ route('customer.workspaces.businesses.email.connect', [$workspaceUid, $businessUid, $account->provider->value]) }}" style="margin-top:8px">
                                    @csrf
                                    <input type="hidden" name="reauthorize" value="1">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Reauthorize to receive replies</button>
                                </form>
                            @endif
                        @else
                            Ask the account owner to reauthorize.
                        @endif
                    </div>
                @elseif ($account->inbound_failure)
                    <div class="me-alert" data-role="email-inbound-failed">
                        <strong>Receiving problem</strong> {{ $inboundFailureText }}
                    </div>
                @endif
            @endif
        @if ($canSend && count($contacts) === 0)
            <div class="me-contacts-tip" data-role="email-no-contacts">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5M17 8v6M14 11h6"/>
                </svg>
                <div class="me-contacts-copy">
                    <b>No contacts have an email yet</b>
                    <span>Add one to a contact and you can email them from Conversations.</span>
                    <span class="sr-only">None of your contacts has an email address yet.</span>
                </div>
                <a href="{{ route('customer.workspaces.businesses.people.index', [$workspaceUid, $businessUid]) }}" class="me-outline-btn">Go to contacts</a>
            </div>
        @endif

        @if ($messages->isNotEmpty() && ($canManage || $canSend))
            <section class="me-card me-recent" aria-label="Recent emails" data-role="email-recent">
                <div class="me-recent-head">
                    <strong>Recent emails</strong>
                    <a class="me-link" href="{{ route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) }}">Open in Conversations</a>
                </div>
                @foreach ($messages as $message)
                    @php
                        // Messages do not store a verified sender display name; use only the actual address.
                        $otherAddress = $message->direction === 'inbound' ? (string) $message->from_email : (string) $message->to_email;
                        $avatarLetters = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', explode('@', $otherAddress)[0] ?? ''), 0, 2));
                        $avatarLetters = $avatarLetters ?: 'EM';
                        $statusValue = $message->status->value;
                    @endphp
                    <div class="me-email-row">
                        <div class="me-email-main">
                            <span class="me-avatar {{ $message->direction === 'outbound' ? 'out' : '' }}" aria-hidden="true">{{ $avatarLetters }}</span>
                            <div class="me-email-content">
                                <span class="me-email-subject" title="{{ $message->subject }}">{{ $message->subject ?: 'No subject' }}</span>
                                <span class="me-email-from">{{ $message->direction === 'inbound' ? 'From' : 'To' }} · {{ $otherAddress }}</span>
                            </div>
                            @if ($message->direction === 'outbound')
                                @if ($statusValue === 'accepted')
                                    <span class="me-msg-status accepted" title="accepted by your email provider">Accepted</span>
                                @elseif ($statusValue === 'failed')
                                    <span class="me-msg-status failed" title="{{ $message->failure_category?->customerMessage() }}">Not sent</span>
                                @elseif ($statusValue === 'unconfirmed')
                                    <span class="me-msg-status">Unconfirmed</span>
                                @else
                                    <span class="me-msg-status">Sending</span>
                                @endif
                            @endif
                            <time class="me-email-time" datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->diffForHumans() }}</time>
                        </div>
                        @if ($canSend && $statusValue !== 'failed')
                            <details class="me-email-reply">
                                <summary>Reply</summary>
                                <form method="POST" action="{{ route('customer.workspaces.businesses.email.reply', [$workspaceUid, $businessUid, $message->uid]) }}">
                                    @csrf
                                    <input type="hidden" name="send_token" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <label class="sr-only" for="reply-subject-{{ $message->uid }}">Reply subject</label>
                                    <input id="reply-subject-{{ $message->uid }}" class="form-control mb-1" type="text" name="subject" maxlength="200" required
                                           value="{{ \Illuminate\Support\Str::startsWith(strtolower($message->subject), 're:') ? $message->subject : 'Re: ' . $message->subject }}">
                                    <label class="sr-only" for="reply-body-{{ $message->uid }}">Reply message</label>
                                    <textarea id="reply-body-{{ $message->uid }}" class="form-control mb-1" name="body" rows="4" maxlength="20000" required></textarea>
                                    <button type="submit" class="btn btn-sm btn-primary">Send reply</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endforeach
            </section>
        @elseif (! $isConnectFlow && ($canManage || $canSend))
            <section class="me-card me-recent" data-role="email-recent-empty">
                <div class="me-recent-head">
                    <strong>Recent emails</strong>
                    <a class="me-link" href="{{ route('customer.workspaces.businesses.conversations.index', [$workspaceUid, $businessUid]) }}">Open in Conversations</a>
                </div>
                <p class="me-empty-recent">No recent emails to show yet.</p>
            </section>
        @endif

    @if ($canSend && count($contacts) > 0)
        <details class="me-compose" @if ($errors->any()) open @endif>
            <summary>Send an email to a contact</summary>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title sr-only">Send an email to a contact</h5>

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
        </details>
    @endif

    </div>
@endsection
