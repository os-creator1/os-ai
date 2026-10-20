@extends('layouts/contentLayoutMaster')

{{--
    A contact's profile: only what is stored (ContactDirectory::profile()) —
    identity and details from the group's fields, the group, when the contact
    was added, campaign messages and the conversation with this number.
    There is no form, source, notes or tags section because none of those is
    recorded for contacts today.
--}}

@section('title', $profile['name'] ?? $profile['phone'])

@section('page-style')
    <style>
        .mg-cp{max-width:68rem}
        .mg-cp-back{display:inline-flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--color-text-muted,#6f6d67);text-decoration:none;margin-bottom:1rem}
        .mg-cp-back:hover{color:var(--color-primary,#b5524c)}
        .mg-cp-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
        .mg-cp-who{display:flex;align-items:center;gap:1rem;min-width:0}
        .mg-cp-avatar{flex:none;display:inline-flex;align-items:center;justify-content:center;width:3.4rem;height:3.4rem;border-radius:999px;background:var(--color-primary-soft-bg,#f4e6e4);color:var(--color-primary,#b5524c);font-weight:600;font-size:1.05rem}
        .mg-cp-name{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin:0;font-size:1.5rem;font-weight:700;overflow-wrap:anywhere}
        .mg-cp-meta{margin:.15rem 0 0;font-size:.85rem;color:var(--color-text-muted,#6f6d67)}
        .mg-cp-actions{display:flex;gap:.5rem;flex-wrap:wrap}
        .mg-cp-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:1.25rem;align-items:start}
        .mg-cp-col{display:grid;gap:1.25rem;min-width:0}
        .mg-cp-card{background:var(--color-surface-primary,#fff);border:1px solid var(--color-border-neutral,#e5e1da);border-radius:.9rem;padding:1.1rem 1.25rem;min-width:0}
        .mg-cp-card-title{margin:0 0 .85rem;font-size:1rem;font-weight:700}
        .mg-cp-card-sub{margin:-.6rem 0 .9rem;font-size:.8rem;color:var(--color-text-muted,#6f6d67)}
        .mg-cp-info{list-style:none;margin:0;padding:0;display:grid;gap:.9rem}
        .mg-cp-info li{display:flex;align-items:center;gap:.8rem;min-width:0}
        .mg-cp-info-icon{flex:none;display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:.55rem;background:#efece7;color:#6f6d67}
        .mg-cp-info-body{flex:1;min-width:0}
        .mg-cp-info-label{display:block;font-size:.65rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--color-text-muted,#6f6d67)}
        .mg-cp-info-value{display:block;font-weight:600;overflow-wrap:anywhere}
        .mg-cp-iconbtn{flex:none;display:inline-flex;align-items:center;justify-content:center;width:1.8rem;height:1.8rem;padding:0;border:0;border-radius:.45rem;background:transparent;color:#8a867d;cursor:pointer}
        .mg-cp-iconbtn:hover{background:#efece7}
        .mg-cp-iconbtn:focus-visible{outline:2px solid var(--color-primary,#b5524c);outline-offset:1px}
        .mg-cp-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem 1rem}
        .mg-cp-field{min-width:0}
        .mg-cp-field.is-wide{grid-column:1 / -1}
        .mg-cp-field label.mg-cp-flabel{display:block;margin-bottom:.25rem;font-size:.8rem;font-weight:500}
        .mg-cp-field .form-control,.mg-cp-field .form-select{max-width:100%;width:100%}
        .mg-cp-save{display:flex;justify-content:flex-end;margin-top:1rem}
        .mg-cp-details{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:.5rem 1rem;margin:0}
        .mg-cp-details dt{font-weight:500;color:var(--color-text-muted,#6f6d67)}
        .mg-cp-details dd{margin:0;overflow-wrap:anywhere}
        .mg-cp-empty{display:flex;flex-direction:column;align-items:center;text-align:center;gap:.5rem;padding:1.25rem 0 .5rem}
        .mg-cp-empty-icon{display:inline-flex;align-items:center;justify-content:center;width:3rem;height:3rem;border-radius:999px;background:var(--color-primary-soft-bg,#f4e6e4);color:var(--color-primary,#b5524c)}
        .mg-cp-empty p{margin:0;color:var(--color-text-muted,#6f6d67);font-size:.85rem}
        @media (max-width:991.98px){
            .mg-cp-grid{grid-template-columns:minmax(0,1fr)}
        }
        @media (max-width:575.98px){
            .mg-cp-fields,.mg-cp-details{grid-template-columns:minmax(0,1fr)}
            .mg-cp-actions{width:100%}
            .mg-cp-actions .btn{flex:1 1 auto;justify-content:center}
        }
    </style>
@endsection

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
        $displayName = $profile['name'] ?? $profile['phone'];
        $initials = collect(preg_split('/\s+/u', trim((string) ($profile['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY))
            ->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
        $initials = $initials !== '' ? $initials : '#';
        $firstName = $profile['name'] !== null ? (preg_split('/\s+/u', trim($profile['name']))[0] ?? $profile['name']) : null;
        $canEditDetails = $profile['group'] !== null && auth()->user()?->can('update_contact');
    @endphp

    <section id="contact-profile">
        <div class="mg-cp">
        <a href="{{ route('customer.workspaces.businesses.people.index', [$workspaceUid, $businessUid]) }}" class="mg-cp-back">
            <x-ds-icon name="arrow-left" size="16" aria-hidden="true" />
            All contacts
        </a>

        <div class="mg-cp-head">
            <div class="mg-cp-who">
                <span class="mg-cp-avatar" aria-hidden="true">{{ $initials }}</span>
                <div class="min-w-0">
                    <h1 class="mg-cp-name">
                        <span data-role="contact-name">{{ $displayName }}</span>
                        @if ($profile['subscribed'])
                            <x-badge variant="success">Subscribed</x-badge>
                        @else
                            <x-badge variant="neutral">Unsubscribed</x-badge>
                        @endif
                    </h1>
                    @if ($profile['added'] !== null || $profile['group'] !== null)
                        <p class="mg-cp-meta">
                            @if ($profile['added'] !== null)Added {{ $profile['added']->format('M j, Y') }}@endif
                            @if ($profile['added'] !== null && $profile['group'] !== null) · @endif
                            @if ($profile['group'] !== null)in {{ $profile['group']['name'] }}@endif
                        </p>
                    @endif
                </div>
            </div>

            <div class="mg-cp-actions" data-role="contact-actions">
                @if ($canEditDetails)
                    <x-button variant="outline" size="sm" icon="pencil" data-role="contact-edit" :href="route('customer.workspaces.businesses.contact.edit', [$workspaceUid, $businessUid, $profile['group']['uid']]) . '?contact_id=' . urlencode($contactUid)">Edit details</x-button>
                @endif
                {{-- Browser calling: enabled only when this contact has a callable number AND an authorized, voice-ready caller ID; otherwise disabled with the reason (softphone.js). --}}
                @if (\App\Library\Voice\VoiceAvailability::mayCall(request()))
                    @if (\App\Library\Voice\VoiceAvailability::mounted(request()))
                        <x-button variant="outline" size="sm" icon="phone" data-role="contact-call" data-mg-call data-mg-show-reason :data-contact-uid="$contactUid" aria-label="Call {{ $displayName }}" disabled>Call</x-button>
                    @else
                        {{-- Calling is not set up for this Business: visible, disabled, and says why — never a dead dialing screen. --}}
                        <span class="d-inline-flex flex-column">
                            <x-button variant="outline" size="sm" icon="phone" data-role="contact-call" data-calling-unavailable :title="\App\Library\Voice\VoiceAvailability::unavailableReason()" aria-label="Call {{ $displayName }} (unavailable)" disabled>Call</x-button>
                            <span class="text-caption" data-role="contact-call-reason">{{ \App\Library\Voice\VoiceAvailability::unavailableReason() }}</span>
                        </span>
                    @endif
                @endif
                @if ($actions['bookUrl'] !== null)
                    <x-button variant="outline" size="sm" icon="calendar" data-role="contact-book" :href="$actions['bookUrl']">Book appointment</x-button>
                @endif
                @if ($actions['messageUrl'] !== null)
                    <x-button variant="primary" size="sm" icon="message-square" data-role="contact-message" :href="$actions['messageUrl']">Send message</x-button>
                @endif
            </div>
        </div>

        <div class="mg-cp-grid">
            <div class="mg-cp-col">
                <div class="mg-cp-card" data-role="contact-info">
                    <h2 class="mg-cp-card-title">Contact info</h2>
                    <ul class="mg-cp-info" data-role="contact-identity">
                        <li>
                            <span class="mg-cp-info-icon"><x-ds-icon name="smartphone" size="16" aria-hidden="true" /></span>
                            <span class="mg-cp-info-body"><span class="mg-cp-info-label">Phone</span><span class="mg-cp-info-value text-numeric">{{ $profile['phone'] }}</span></span>
                            <button type="button" class="mg-cp-iconbtn" data-copy="{{ $profile['phone'] }}" data-role="contact-copy-phone" aria-label="Copy phone number" title="Copy phone number"><x-ds-icon name="copy" size="14" aria-hidden="true" /></button>
                        </li>
                        @if ($profile['email'] !== null)
                            <li>
                                <span class="mg-cp-info-icon"><x-ds-icon name="mail" size="16" aria-hidden="true" /></span>
                                <span class="mg-cp-info-body"><span class="mg-cp-info-label">Email</span><span class="mg-cp-info-value">{{ $profile['email'] }}</span></span>
                                <button type="button" class="mg-cp-iconbtn" data-copy="{{ $profile['email'] }}" data-role="contact-copy-email" aria-label="Copy email address" title="Copy email address"><x-ds-icon name="copy" size="14" aria-hidden="true" /></button>
                            </li>
                        @endif
                        @if ($profile['company'] !== null)
                            <li>
                                <span class="mg-cp-info-icon"><x-ds-icon name="layout-grid" size="16" aria-hidden="true" /></span>
                                <span class="mg-cp-info-body"><span class="mg-cp-info-label">Company</span><span class="mg-cp-info-value">{{ $profile['company'] }}</span></span>
                            </li>
                        @endif
                        @if ($profile['group'] !== null)
                            <li>
                                <span class="mg-cp-info-icon"><x-ds-icon name="users" size="16" aria-hidden="true" /></span>
                                <span class="mg-cp-info-body"><span class="mg-cp-info-label">Group</span><span class="mg-cp-info-value"><a href="{{ route('customer.workspaces.businesses.contacts.show', [$workspaceUid, $businessUid, $profile['group']['uid']]) }}">{{ $profile['group']['name'] }}</a></span></span>
                            </li>
                        @endif
                        @if ($profile['added'] !== null)
                            <li>
                                <span class="mg-cp-info-icon"><x-ds-icon name="calendar" size="16" aria-hidden="true" /></span>
                                <span class="mg-cp-info-body"><span class="mg-cp-info-label">Added</span><span class="mg-cp-info-value">{{ $profile['added']->format('M j, Y') }}</span></span>
                            </li>
                        @endif
                    </ul>
                </div>
            </div>

            <div class="mg-cp-col">
                @if (! empty($profile['details']))
                    <div class="mg-cp-card" data-role="contact-details-card">
                        <h2 class="mg-cp-card-title">Details</h2>
                        <dl class="mg-cp-details" data-role="contact-details">
                            @foreach ($profile['details'] as $detail)
                                <dt>{{ $detail['label'] }}</dt>
                                <dd>{{ $detail['value'] }}</dd>
                            @endforeach
                        </dl>
                    </div>
                @endif

                @if ($customFields !== null)
                    @include('customer.people._custom-fields')
                @endif

                <div class="mg-cp-card" data-role="contact-activity">
                    <h2 class="mg-cp-card-title">Activity</h2>
                    @if ($profile['conversation'] === null && empty($profile['campaigns']) && ($calls ?? collect())->isEmpty())
                        <div class="mg-cp-empty" data-role="contact-no-activity">
                            <span class="mg-cp-empty-icon"><x-ds-icon name="message-square" size="20" aria-hidden="true" /></span>
                            <strong>No activity yet</strong>
                            <p>Messages and campaigns with {{ $firstName ?? 'this contact' }} will appear here.</p>
                            @if ($actions['messageUrl'] !== null)
                                <x-button variant="outline" size="sm" data-role="contact-first-message" :href="$actions['messageUrl']">Send the first message</x-button>
                            @endif
                        </div>
                    @endif

                    @if ($profile['conversation'] !== null)
                        <div class="mb-2" data-role="contact-conversation">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <h3 class="h5 mb-0">Conversation</h3>
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

                    @if (($calls ?? collect())->isNotEmpty())
                        <div class="mb-2" data-role="contact-calls">
                            <h3 class="h5 mb-1">Calls</h3>
                            <ul class="list-unstyled mb-0">
                                @foreach (($calls ?? collect()) as $call)
                                    @php($described = \App\Library\Voice\VoiceCallHistory::describe($call))
                                    <li class="mb-50" data-role="contact-call-row" data-call-status="{{ $call->status }}">
                                        <span class="text-caption">{{ $call->initiated_at?->format('M j, Y H:i') }}</span>
                                        <div>{{ $described['title'] }}@if ($described['detail'] !== null) <span class="text-caption">· {{ $described['detail'] }}</span>@endif</div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (! empty($profile['campaigns']))
                        <div data-role="contact-campaigns">
                            <h3 class="h5 mb-1">Campaign messages</h3>
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
                </div>
            </div>
        </div>
        </div>
    </section>

    <script>
        (function () {
            document.querySelectorAll('#contact-profile [data-copy]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var text = button.getAttribute('data-copy');
                    var original = button.getAttribute('title');
                    var done = function () {
                        button.setAttribute('title', 'Copied');
                        setTimeout(function () { button.setAttribute('title', original || ''); }, 1500);
                    };
                    var fallback = function () {
                        var area = document.createElement('textarea');
                        area.value = text;
                        area.setAttribute('readonly', '');
                        area.style.position = 'fixed';
                        area.style.opacity = '0';
                        document.body.appendChild(area);
                        area.select();
                        try { document.execCommand('copy'); } catch (e) {}
                        document.body.removeChild(area);
                        done();
                    };

                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(done, fallback);
                    } else {
                        fallback();
                    }
                });
            });
        })();
    </script>
@endsection
