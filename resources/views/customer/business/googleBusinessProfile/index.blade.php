{{--
    GBP Slice A contract §25.2/§25.3/§25.5/§25.7/§25.9/§25.10/§25.11 — the
    Business-scoped overview.

    READ-ONLY SURFACE. There is deliberately no edit, post, review-reply,
    verification, photo-upload or any other write control anywhere in this
    view, and no Reviews/Posts/Media/Performance/Q&A tab — not even
    disabled. There is no GBP score, profile-strength percentage, grade,
    chart or AI-generated analysis (contract §25.10, §31).

    Layout: not connected = one short connect card (the §25.9 permission
    wording sits behind a disclosure, never removed); connected = the
    linked profile first (name, status, key facts), then only what differs.

    Every Google-supplied value is rendered with escaped Blade output. Raw,
    unescaped Blade output is forbidden in every GBP view (contract §14.5,
    test T-XSS-2).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Google Business Profile')

@section('content')
    <style>
        .gbp-connect { max-width: 34rem; margin: 1rem auto; text-align: center; }
        .gbp-connect-icon { width: 4rem; height: 4rem; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: var(--bs-primary-bg-subtle, #f1eefe); color: var(--bs-primary, #7367f0); margin-bottom: 1rem; }
        .gbp-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 1rem 1.5rem; }
        .gbp-fact-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color, #6e6b7b); margin-bottom: .125rem; }
        .gbp-fact-value { font-weight: 600; overflow-wrap: anywhere; }
        .gbp-diff { border: 1px solid var(--bs-warning-border-subtle, #ffe2a8); background: var(--bs-warning-bg-subtle, #fff7e6); border-radius: .5rem; padding: .75rem 1rem; }
        .gbp-diff + .gbp-diff { margin-top: .5rem; }
        .gbp-diff-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        @media (max-width: 575.98px) { .gbp-diff-grid { grid-template-columns: 1fr; gap: .25rem; } }
    </style>

    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Google Business Profile</h4>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    {{-- Contract §25.11 — the safe error state. A normalized
         classification only; never a raw provider message. --}}
    @if($connection && $connection->failure_classification)
        <x-alert variant="warning" class="mb-2">
            The last Google request did not succeed ({{ str_replace('_', ' ', $connection->failure_classification) }}).
        </x-alert>
    @endif

    @if(! $connection || $connection->state->value === 'disconnected' || $connection->state->value === 'pending')
        {{-- Contract §25.2 — not connected. --}}
        <x-card :padded="true" class="gbp-connect">
            <div class="gbp-connect-icon"><x-ds-icon name="map-pin" size="28" /></div>
            <h5 class="mb-50">Connect Google Business Profile</h5>
            <p class="mb-2">See how your business appears on Google and compare it with the information stored here.</p>

            @can('manage_google_business_profile')
                {{-- Item 9: initiation mutates state, so it is a POST. --}}
                <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.connect', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Connect Google</button>
                </form>
            @else
                {{-- Contract §25 — permission denial is communicated,
                     not hidden behind a broken control. --}}
                <p class="text-caption mb-0">You do not have permission to manage this connection.</p>
            @endcan

            <p class="text-caption mt-2 mb-50">We only read your profile. Nothing on Google changes without your action.</p>

            {{-- Contract §25.9 — REQUIRED consent disclosure, available before every Connect. It is a
                 factual statement of a Google constraint, not marketing copy: it must not be softened
                 or removed, only kept out of the way. --}}
            <details class="text-start d-inline-block" data-role="gbp-permission-disclosure">
                <summary class="text-caption">Why does Google ask for this permission?</summary>
                <p class="text-caption mb-0 mt-50">
                    Google's permission screen will say <strong>&ldquo;Manage your Business Profile on Google&rdquo;</strong>.
                    That is the only permission Google offers for Business Profile &mdash; there is no read-only option.
                    This platform only <strong>reads</strong> your profile; it will not change anything on Google.
                </p>
            </details>
        </x-card>
    @elseif($connection->state->value === 'revoked')
        {{-- Contract §25.7 — revoked / reconnect. The binding is RETAINED
             and still shown, so the user does not lose their mapping. --}}
        <x-card :padded="true" class="gbp-connect">
            <div class="gbp-connect-icon"><x-ds-icon name="alert-triangle" size="28" /></div>
            <h5 class="mb-50">Reconnect Google</h5>
            <p class="text-muted mb-2">Google access has expired or was removed. Your linked location is kept.</p>
            @can('manage_google_business_profile')
                <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.connect', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Reconnect Google</button>
                </form>
            @endcan
        </x-card>
    @endif

    @if($connection && $connection->state->value === 'active')
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2" data-role="gbp-connection">
            <span class="text-caption">
                {{ $connection->google_account_email ?? 'Google account connected' }}
                @if($connection->connected_at) &middot; connected {{ $connection->connected_at->diffForHumans() }}@endif
            </span>
            <x-badge variant="success">Connected &middot; read-only</x-badge>
        </div>

        @if(count($bindings) === 0)
            {{-- Contract §25.3 — connected but not bound. --}}
            <x-card :padded="true" class="gbp-connect">
                <div class="gbp-connect-icon"><x-ds-icon name="link" size="28" /></div>
                <h5 class="mb-50">Choose your Google location</h5>
                <p class="text-muted mb-2">Pick the Google listing that matches this business.</p>
                @can('manage_google_business_profile')
                    <a class="btn btn-primary" href="{{ route('customer.workspaces.businesses.gbp.locations', [$workspaceUid, $businessUid]) }}">
                        Choose location
                    </a>
                @endcan
            </x-card>
        @else
            {{-- Contract §25.5 — connected and bound. EVERY binding this
                 Business owns is listed; no "primary" binding is inferred
                 and the collection is never collapsed to one row. --}}
            @foreach($bindings as $item)
                @php
                    $binding = $item['binding'];
                    $mirror = $item['mirror'];
                    $rows = collect($item['rows']);
                    // A difference is a mismatch, or a value present on one side only; 'Not set' on both sides is nothing to act on.
                    $differs = $rows->filter(fn ($r) => $r->status->value === 'mismatch' || (in_array($r->status->value, ['not_set_on_platform', 'not_set_on_google'], true) && ($r->platformValue !== null || $r->googleValue !== null)))->values();
                    $matches = $rows->filter(fn ($r) => $r->status->value === 'match')->count();
                    $category = $mirror['primary_category_name'] ?? null;
                    $place = collect([$mirror['locality'] ?? null, $binding->bound_region_code_snapshot])->filter()->implode(', ');
                @endphp
                <x-card :padded="true" class="mb-2" data-role="gbp-binding">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                        <div>
                            <h5 class="mb-25">{{ $binding->bound_title_snapshot ?? 'Google location' }}</h5>
                            <span class="text-caption">{{ $item['location']?->name ?? '—' }}</span>
                        </div>
                        <div class="d-flex gap-50 flex-wrap">
                            @if($binding->verification_state)
                                <x-badge :variant="$binding->verification_state->value === 'verified' ? 'success' : 'warning'">
                                    {{ $binding->verification_state->label() }}
                                </x-badge>
                            @endif
                            @if($item['mirrorIsFresh'] && $binding->mirror_fetched_at)
                                {{-- Contract §25.10 — refresh status, per binding. --}}
                                <x-badge variant="neutral">Updated {{ $binding->mirror_fetched_at->diffForHumans() }}</x-badge>
                            @else
                                <x-badge variant="warning">Refresh required</x-badge>
                            @endif
                        </div>
                    </div>

                    @if($item['mirrorIsFresh'])
                        <div class="gbp-facts mt-2" data-role="gbp-facts">
                            <div><div class="gbp-fact-label">Phone</div><div class="gbp-fact-value">{{ $mirror['phone_primary'] ?? '—' }}</div></div>
                            <div><div class="gbp-fact-label">Website</div><div class="gbp-fact-value">{{ $mirror['website_uri'] ?? '—' }}</div></div>
                            <div><div class="gbp-fact-label">Category</div><div class="gbp-fact-value">{{ $category ?? '—' }}</div></div>
                            <div><div class="gbp-fact-label">Location</div><div class="gbp-fact-value">{{ $place !== '' ? $place : '—' }}</div></div>
                        </div>
                    @endif

                    <details class="mt-1"><summary class="text-caption">Details</summary><p class="text-caption mb-0 mt-50">{{ $item['providerLocationResourceName'] }} &middot; {{ $item['providerAccountResourceName'] }}</p></details>

                    @if($binding->has_pending_edits)
                        <x-alert variant="info" class="mt-2 mb-0">Google is reviewing edits to this listing.</x-alert>
                    @endif

                    @if($binding->duplicate_of_resource_name)
                        <x-alert variant="warning" class="mt-2 mb-0">
                            Google lists this location as a duplicate. Resolve it in Google; this platform never merges listings.
                        </x-alert>
                    @endif

                    @if($binding->open_status && $binding->open_status !== 'OPEN')
                        <x-alert variant="warning" class="mt-2 mb-0">
                            Google shows this location as
                            {{ $binding->open_status === 'CLOSED_PERMANENTLY' ? 'permanently closed' : 'temporarily closed' }}.
                        </x-alert>
                    @endif

                    {{-- Contract §23.6 — the storefront/consent contradiction is
                         SURFACED, never silently resolved in either direction. --}}
                    @if($item['addressContradiction'])
                        <x-alert variant="warning" class="mt-2 mb-0">
                            This location is marked as a storefront, but its address stays private, so it is not sent to or read from Google.
                        </x-alert>
                    @endif

                    @if($item['mirrorIsFresh'])
                        <div class="mt-2" data-role="gbp-differences">
                            @if($differs->isEmpty())
                                <p class="mb-0 text-success fw-bold">{{ $matches }} {{ $matches === 1 ? 'field matches' : 'fields match' }} Google.</p>
                            @else
                                <p class="mb-1 fw-bold">{{ $differs->count() }} {{ $differs->count() === 1 ? 'field differs' : 'fields differ' }} from Google <span class="text-muted fw-normal">@if($matches > 0)&middot; {{ $matches }} match @endif</span></p>
                                @foreach($differs as $row)
                                    <div class="gbp-diff" data-role="gbp-diff">
                                        <div class="fw-bold mb-25">{{ $row->field }}</div>
                                        <div class="gbp-diff-grid">
                                            <div><span class="gbp-fact-label d-block">Stored here</span>{{ $row->platformValue ?? 'Not set' }}</div>
                                            <div><span class="gbp-fact-label d-block">On Google</span>{{ $row->googleValue ?? 'Not set' }}</div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endif

                    {{-- The comparison action is scoped to THIS binding. --}}
                    @if($item['comparisonAvailable'] && $item['mirrorIsFresh'])
                        <a class="btn btn-outline-primary mt-2" href="{{ $item['comparisonUrl'] }}">View full comparison</a>
                    @elseif($item['comparisonAvailable'])
                        <a class="btn btn-outline-primary mt-2" href="{{ $item['comparisonUrl'] }}">View comparison</a>
                    @endif
                </x-card>
            @endforeach

            <div class="d-flex gap-1 flex-wrap">
                @can('manage_google_business_profile')
                    <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.refresh', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">
                            Refresh {{ count($bindings) === 1 ? 'from Google' : 'all ' . count($bindings) . ' locations from Google' }}
                        </button>
                    </form>
                    <a class="btn btn-outline-primary" href="{{ route('customer.workspaces.businesses.gbp.locations', [$workspaceUid, $businessUid]) }}">
                        Link another location
                    </a>
                @endcan
                <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.gbp.settings', [$workspaceUid, $businessUid]) }}">
                    Connection settings
                </a>
            </div>
        @endif
    @endif
@endsection
