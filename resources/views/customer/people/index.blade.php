@extends('layouts/contentLayoutMaster')

{{--
    Contacts → All contacts: every contact of the selected Business, person
    first (ContactDirectory). Groups are the secondary tab.
--}}

@section('title', 'Contacts')

@section('page-style')
    @include('partials.cx._styles')
    <style>
        .contacts-filters { display: flex; flex-wrap: wrap; gap: .5rem .75rem; align-items: center; width: 100%; }
        .contacts-group { position: relative; display: inline-flex; align-items: center; flex: 0 0 auto; }
        .contacts-group > .ds-icon { position: absolute; left: .625rem; pointer-events: none; color: var(--color-icon-muted, var(--color-text-muted)); }
        .contacts-group select { padding-left: 2rem; min-width: 10rem; max-width: 16rem; }
        .contacts-table { width: 100%; margin: 0; border-collapse: collapse; font-size: .78125rem; }
        .contacts-table th {
            padding: .625rem 1.125rem;
            font-size: .625rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--color-text-muted);
            text-align: left;
            white-space: nowrap;
            border-bottom: 1px solid var(--color-border-subtle, var(--color-border));
        }
        .contacts-table td {
            padding: .75rem 1.125rem;
            color: var(--color-text-primary);
            vertical-align: middle;
            border-bottom: 1px solid var(--color-border-subtle, var(--color-border));
        }
        .contacts-table tbody tr:last-child td { border-bottom: 0; }
        .contacts-table tbody tr:hover { background: var(--color-row-hover, var(--color-surface-secondary)); }
        .contacts-name { display: flex; align-items: center; gap: .625rem; min-width: 11rem; }
        .contacts-name a { font-weight: 600; color: var(--color-text-primary); overflow-wrap: anywhere; }
        .contacts-name a:hover { color: var(--color-primary); }
        .contacts-email { overflow-wrap: anywhere; }
        .contacts-empty { color: var(--color-text-disabled, var(--color-text-muted)); }
        .contacts-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; }
        .contacts-foot .ds-pagination { width: 100%; }

        /* Phones: each contact becomes a compact card; labels come from data-label. */
        @media (max-width: 767.98px) {
            .contacts-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
            .contacts-table, .contacts-table tbody, .contacts-table tr, .contacts-table td { display: block; width: 100%; }
            .contacts-table tr { padding: .75rem .875rem; border-bottom: 1px solid var(--color-border-subtle, var(--color-border)); }
            .contacts-table tbody tr:last-child { border-bottom: 0; }
            .contacts-table td { display: flex; gap: .75rem; padding: .1875rem 0; border: 0; }
            .contacts-table td[data-label]::before {
                content: attr(data-label);
                flex: 0 0 6.5rem;
                font-size: .625rem;
                font-weight: 600;
                letter-spacing: .06em;
                text-transform: uppercase;
                color: var(--color-text-muted);
                padding-top: .125rem;
            }
            .contacts-table td.contacts-name-cell { padding-bottom: .5rem; }
            .contacts-group { flex: 1 1 100%; }
            .contacts-group select { max-width: none; width: 100%; }
        }
    </style>
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
        // Newest first, so on the unfiltered first page the first row is the latest addition.
        $newest = ! $filtered && $contacts->onFirstPage() ? collect($contacts->items())->first()['added'] ?? null : null;
        $noun = \Illuminate\Support\Str::plural('contact', $contacts->total());
        $summaryText = $filtered
            ? number_format($contacts->total()).' '.$noun.' match'
            : number_format($contacts->total()).' '.$noun.($newest ? ' · newest added '.$newest->format('M j, Y') : '');
    @endphp

    <section id="contacts-people">
        <header class="cx-page-head">
            <div>
                <h1 class="cx-page-title">Contacts</h1>
                <p class="cx-page-sub" data-role="contacts-summary">{{ $summaryText }}</p>
            </div>
            @can('create_contact')
                <div class="cx-page-actions" data-role="contacts-actions">
                    <x-button variant="secondary" icon="download" :href="route('customer.workspaces.businesses.people.import', [$workspaceUid, $businessUid])">Import</x-button>
                    <x-button variant="primary" :href="route('customer.workspaces.businesses.people.add', [$workspaceUid, $businessUid])">+ Add contact</x-button>
                </div>
            @endcan
        </header>

        <div class="mb-2">
            @include('customer.people._tabs', $filtered ? [] : ['contactsTotal' => $contacts->total()])
        </div>

        <div class="cx-card">
            {{-- Searching, filtering and paging only change which contacts are listed, so they
                 update the list below in place (window.AsyncRegion) and keep the
                 address bar in step. The form sits outside the list on purpose: what
                 someone is typing is never replaced while the results change. Without
                 JavaScript both are an ordinary GET to the same URL. --}}
            <form method="GET" action="{{ $listUrl }}" role="search" class="cx-toolbar" data-role="contacts-search" data-async-form="contacts-list">
                <div class="contacts-filters">
                    <div class="cx-search">
                        <x-ds-icon name="search" size="15" aria-hidden="true" />
                        <label for="contacts-search" class="visually-hidden">Search contacts</label>
                        <input type="search" class="form-control" id="contacts-search" name="q" value="{{ $search }}" placeholder="Search by name, phone, email or company" maxlength="100">
                    </div>
                    @if ($groups->isNotEmpty())
                        <div class="contacts-group">
                            <x-ds-icon name="filter" size="14" aria-hidden="true" />
                            <label for="contacts-group" class="visually-hidden">Group</label>
                            <select id="contacts-group" name="group" class="form-select form-control" data-role="contacts-group-filter">
                                <option value="">Group: All</option>
                                @foreach ($groups as $group)
                                    <option value="{{ $group->uid }}" @selected($selectedGroup?->uid === $group->uid)>Group: {{ $group->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <button type="submit" class="visually-hidden">Search</button>
                </div>
            </form>

            <div data-async-region="contacts-list">
            {{-- Read by the script below after an in-place update, so the header line stays true. --}}
            <span hidden data-contacts-summary-source="{{ $summaryText }}" @unless ($filtered) data-contacts-total="{{ number_format($contacts->total()) }}" @endunless></span>
            @if ($contacts->total() === 0)
                <div class="cx-card-body">
                    @if ($search !== '')
                        <x-empty-state icon="search" title="No contacts match “{{ $search }}”." description="Try a name, a phone number, an email or a company." />
                    @elseif ($selectedGroup !== null)
                        <x-empty-state icon="users" title="No contacts in this group." description="Choose another group, or show all contacts." />
                    @else
                        <x-empty-state icon="users" title="No contacts yet." description="Add your first contact, or import a list you already have." />
                    @endif
                </div>
            @else
                <table class="contacts-table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Phone</th>
                            <th scope="col">Email</th>
                            <th scope="col">Company</th>
                            <th scope="col">Group</th>
                            <th scope="col">Last activity</th>
                            <th scope="col">Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($contacts as $contact)
                            <tr data-role="contact-row">
                                <td class="contacts-name-cell">
                                    <span class="contacts-name">
                                        <span class="cx-avatar" aria-hidden="true">{{ $initials($contact['name']) }}</span>
                                        <span>
                                            <a href="{{ route('customer.workspaces.businesses.people.show', [$workspaceUid, $businessUid, $contact['uid']]) }}">{{ $contact['name'] ?? $contact['phone'] }}</a>
                                            @unless ($contact['subscribed'])
                                                <span class="cx-pill ms-25">Unsubscribed</span>
                                            @endunless
                                        </span>
                                    </span>
                                </td>
                                <td class="text-numeric cx-nowrap" data-label="Phone">{{ $contact['phone'] }}</td>
                                <td class="contacts-email" data-label="Email">{!! $contact['email'] !== null ? e($contact['email']) : '<span class="contacts-empty">—</span>' !!}</td>
                                <td data-label="Company">{!! $contact['company'] !== null ? e($contact['company']) : '<span class="contacts-empty">—</span>' !!}</td>
                                <td data-label="Group">
                                    @if ($contact['group'] !== null)
                                        <span class="cx-pill is-accent">{{ $contact['group'] }}</span>
                                    @else
                                        <span class="contacts-empty">—</span>
                                    @endif
                                </td>
                                <td class="cx-nowrap" data-label="Last activity">{!! $contact['last_activity'] !== null ? e($contact['last_activity']->diffForHumans()) : '<span class="contacts-empty">—</span>' !!}</td>
                                <td class="cx-nowrap" data-label="Added">{{ $contact['added']?->format('M j, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="cx-card-foot contacts-foot">
                    @if ($contacts->hasPages())
                        <x-pagination :paginator="$contacts" async-region="contacts-list" />
                    @else
                        <span>Showing {{ $contacts->count() }} of {{ number_format($contacts->total()) }} {{ \Illuminate\Support\Str::plural('contact', $contacts->total()) }}</span>
                    @endif
                </div>
            @endif
            </div>
        </div>
    </section>
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
                    var count = tab ? tab.querySelector('.cx-count') : null;
                    var total = source.getAttribute('data-contacts-total');
                    if (tab && total !== null) {
                        if (!count) { count = document.createElement('span'); count.className = 'cx-count'; tab.appendChild(count); }
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
