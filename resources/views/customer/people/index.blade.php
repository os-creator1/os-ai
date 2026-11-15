@extends('layouts/contentLayoutMaster')

{{--
    Contacts → All contacts: every contact of the selected Business, person
    first (ContactDirectory). Groups are the secondary tab.
--}}

@section('title', 'Contacts')

@section('page-style')
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/crm/mgx-contacts.css') }}?v=1">
@endsection

@section('content')
    @php
        $workspaceUid = request()->route('workspaceUid');
        $businessUid = request()->route('businessUid');
        $listUrl = route('customer.workspaces.businesses.people.index', [$workspaceUid, $businessUid]);
        $groups = $groups ?? collect();
        $selectedGroup = $selectedGroup ?? null;
        $filtered = $search !== '' || $selectedGroup !== null;
        $initials = static function (?string $name): string {
            $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts === []) {
                return '#';
            }

            return mb_strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
        };
        $tone = static fn (string $seed): int => abs(crc32($seed)) % 5;
        $todayAdded = $filtered ? 0 : collect($contacts->items())->filter(fn ($c) => $c['added'] !== null && $c['added']->isToday())->count();
        $noun = \Illuminate\Support\Str::plural('person', $contacts->total());
        $noun = $contacts->total() === 1 ? 'person' : 'people';
        $summaryText = $filtered
            ? number_format($contacts->total()).' '.($contacts->total() === 1 ? 'match' : 'matches')
            : number_format($contacts->total()).' '.$noun.($todayAdded > 0 ? ' · '.$todayAdded.' added today' : '');
        $canCall = ! empty($browserCalling);
    @endphp

    <section id="contacts-people" class="mgx-contacts">
        @if (session('flash_success') || session('message'))
            <div class="mgx-flash" role="status">{{ session('flash_success') ?? session('message') }}</div>
        @endif
        <div class="mgx-head">
            <div>
                <h1 class="mgx-title">Contacts</h1>
                <p class="mgx-sub" data-role="contacts-summary">{{ $summaryText }}</p>
            </div>
            @can('create_contact')
                <div data-role="contacts-actions" style="display:contents">
                    <a class="mgx-btn is-secondary" href="{{ route('customer.workspaces.businesses.people.import', [$workspaceUid, $businessUid]) }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>Import</a>
                    <a class="mgx-btn is-primary" data-mgx-open-contact href="{{ route('customer.workspaces.businesses.people.add', [$workspaceUid, $businessUid]) }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add contact</a>
                </div>
            @endcan
        </div>

        <nav class="mgx-tabs" aria-label="Contacts" data-role="contacts-tabs">
            <a class="mgx-tab is-on" aria-current="page" href="{{ $listUrl }}">All contacts @unless ($filtered)<span class="mgx-count">{{ number_format($contacts->total()) }}</span>@endunless</a>
            <a class="mgx-tab" href="{{ route('customer.workspaces.businesses.contacts.index', [$workspaceUid, $businessUid]) }}">Groups</a>
        </nav>

        <div class="mgx-card">
            @if(($added ?? null) !== null)
                <p class="mgx-period" data-role="period-scope">Added {{ $added->startLocal->format('M j') }} – {{ $added->endLocal->format('M j') }} · <a href="{{ $listUrl }}">Show all contacts</a></p>
            @endif
            <form method="GET" action="{{ $listUrl }}" role="search" class="mgx-bar" data-role="contacts-search" data-async-form="contacts-list">
                @if(($added ?? null) !== null)
                    <input type="hidden" name="created_from" value="{{ $added->startLocal->format('Y-m-d') }}">
                    <input type="hidden" name="created_to" value="{{ $added->endLocal->format('Y-m-d') }}">
                @endif
                <label class="mgx-search" for="contacts-search">
                    <svg class="mgx-ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg>
                    <span class="visually-hidden">Search contacts</span>
                    <input type="search" id="contacts-search" name="q" value="{{ $search }}" placeholder="Search name, phone or email" maxlength="100">
                </label>
                @if ($groups->isNotEmpty())
                    <span class="mgx-filter">
                        <label for="contacts-group" class="visually-hidden">Group</label>
                        <select id="contacts-group" name="group" data-role="contacts-group-filter">
                            <option value="">All groups</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->uid }}" @selected($selectedGroup?->uid === $group->uid)>{{ $group->name }}</option>
                            @endforeach
                        </select>
                        <svg class="mgx-ic" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5"/></svg>
                    </span>
                @endif
                <button type="submit" class="visually-hidden">Search</button>
            </form>

            <div data-async-region="contacts-list">
            <span hidden data-contacts-summary-source="{{ $summaryText }}" @unless ($filtered) data-contacts-total="{{ number_format($contacts->total()) }}" @endunless></span>
            @if ($contacts->total() === 0)
                <div class="mgx-empty">
                    @if ($search !== '')
                        <b>No contacts match “{{ $search }}”.</b>Try a name, a phone number or an email.
                    @elseif ($selectedGroup !== null)
                        <b>No contacts in this group.</b>Choose another group, or show all contacts.
                    @else
                        <b>No contacts yet.</b>Add your first contact, or import a list you already have.
                    @endif
                </div>
            @else
                <div class="mgx-row is-head" role="row"><span>Name</span><span>Phone</span><span>Group</span><span>Last activity</span><span>Added</span><span></span></div>
                @foreach ($contacts as $contact)
                    @php
                        $showUrl = route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $contact['uid']]);
                        $label = $contact['name'] ?? $contact['phone'];
                    @endphp
                    <div class="mgx-row" data-role="contact-row">
                        <div class="mgx-who">
                            <span class="mgx-av t{{ $tone($contact['uid']) }}" aria-hidden="true">{{ $initials($contact['name']) }}</span>
                            <div>
                                <a class="mgx-name" href="{{ $showUrl }}">{{ $label }}</a>
                                @if ($contact['email'] !== null)
                                    <small data-role="contact-email">{{ $contact['email'] }}@if ($contact['company'] !== null) · {{ $contact['company'] }}@endif</small>
                                @else
                                    <small class="is-none">No email @if ($contact['company'] !== null)<span style="color:var(--mg-mute)">· {{ $contact['company'] }}</span>@endif</small>
                                @endif
                            </div>
                        </div>
                        <span class="mgx-num" data-label="Phone">{{ \App\Library\Contacts\ContactPhone::pretty($contact['phone']) }}</span>
                        <span data-label="Group">
                            @if ($contact['group'] !== null)
                                <span class="mgx-gp">{{ $contact['group'] }}</span>
                            @else
                                <span class="mgx-gp is-none">No group</span>
                            @endif
                        </span>
                        <span class="{{ $contact['last_activity'] === null ? 'mgx-mu' : '' }}" data-label="Last activity">{{ $contact['last_activity'] !== null ? $contact['last_activity']->diffForHumans() : '—' }}</span>
                        <span class="mgx-mu" data-label="Added">{{ $contact['added']?->format('M j') }}</span>
                        <div class="mgx-ra">
                            @if ($canCall)
                                <button type="button" aria-label="Call {{ $label }}" data-mgx-call data-uid="{{ $contact['uid'] }}" data-label="{{ $label }}" data-phone="{{ $contact['phone'] }}" data-initials="{{ $initials($contact['name']) }}"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1z"/></svg></button>
                            @endif
                            <a href="{{ $showUrl }}" aria-label="Message {{ $label }}" title="Open contact to send an SMS or email"><svg class="mgx-ic" viewBox="0 0 24 24"><path d="M4 5h16v11H9l-5 4z"/></svg></a>
                        </div>
                    </div>
                @endforeach

                <div class="mgx-foot">
                    @if ($contacts->hasPages())
                        <x-pagination :paginator="$contacts" async-region="contacts-list" />
                    @else
                        <span>Showing {{ $contacts->count() }} of {{ number_format($contacts->total()) }}</span>
                    @endif
                </div>
            @endif
            </div>
        </div>
    </section>

    @if (($modal ?? null) !== null)
        @include('customer.people._new-contact-modal', ['modal' => $modal])
    @endif
    @if (! empty($browserCalling))
        @include('customer.people._softphone')
    @endif
@endsection

@section('page-script')
    <script>
        (function () {
            if (window.AsyncRegion && typeof window.AsyncRegion.onUpdate === 'function') {
                window.AsyncRegion.onUpdate('contacts-list', function (region) {
                    var source = region.querySelector('[data-contacts-summary-source]');
                    var summary = document.querySelector('[data-role="contacts-summary"]');
                    if (!source || !summary) { return; }
                    summary.textContent = source.getAttribute('data-contacts-summary-source');
                    var tab = document.querySelector('[data-role="contacts-tabs"] a[aria-current="page"]');
                    var count = tab ? tab.querySelector('.mgx-count') : null;
                    var total = source.getAttribute('data-contacts-total');
                    if (tab && total !== null) {
                        if (!count) { count = document.createElement('span'); count.className = 'mgx-count'; tab.appendChild(count); }
                        count.textContent = total;
                        count.hidden = false;
                    } else if (count) {
                        count.hidden = true;
                    }
                });
            }
        })();
        (function () {
            // Choosing a group submits the same search form, so it combines with the query and resets paging.
            var select = document.getElementById('contacts-group');
            if (!select || !select.form) { return; }
            select.addEventListener('change', function () {
                if (typeof select.form.requestSubmit === 'function') { select.form.requestSubmit(); } else { select.form.submit(); }
            });
        })();
    </script>
@endsection
