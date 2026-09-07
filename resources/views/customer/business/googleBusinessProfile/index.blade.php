{{--
    GBP Slice A contract §25.2/§25.3/§25.5/§25.7/§25.9/§25.10/§25.11 — the
    Business-scoped overview.

    READ-ONLY SURFACE. There is deliberately no edit, post, review-reply,
    verification, photo-upload or any other write control anywhere in this
    view, and no Reviews/Posts/Media/Performance/Q&A tab — not even
    disabled. There is no GBP score, profile-strength percentage, grade,
    chart or AI-generated analysis (contract §25.10, §31).

    Every Google-supplied value is rendered with escaped Blade output. Raw,
    unescaped Blade output is forbidden in every GBP view (contract §14.5,
    test T-XSS-2).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Google Business Profile')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Google Business Profile</h4>
            <span class="text-caption">{{ $business->name }}</span>
        </div>
    </div>

    @if(session('message'))
        <x-alert :variant="session('status') === 'error' ? 'danger' : 'success'" class="mb-2">
            {{ session('message') }}
        </x-alert>
    @endif

    {{-- Contract §25.11 — the safe error state. A normalized
         classification only; never a raw provider message. --}}
    @if($connection && $connection->failure_classification)
        <x-alert variant="warning" class="mb-2">
            The last Google request did not succeed ({{ str_replace('_', ' ', $connection->failure_classification) }}).
        </x-alert>
    @endif

    @if(! $connection || $connection->state->value === 'disconnected' || $connection->state->value === 'pending')
        {{-- Contract §25.2 — not connected. --}}
        <x-card :padded="true">
            <x-empty-state icon="map-pin" title="Not connected to Google"
                           description="Connect a Google account to see how this business appears on Google, side by side with what you store here." />

            <div class="mt-2">
                <p class="text-section-heading mb-1">What this does</p>
                <ul class="mb-2">
                    <li>Reads your Google Business Profile — name, phone, website, categories, verification state and location.</li>
                    <li>Shows a field-by-field comparison against the details stored on this platform.</li>
                    <li><strong>Never changes anything on Google.</strong> This module is read-only.</li>
                    <li>Never changes anything on this platform either — your data is left exactly as it is.</li>
                </ul>

                {{-- Contract §25.9 — REQUIRED consent disclosure, shown
                     before every Connect. This is a factual disclosure of
                     a Google constraint, not marketing copy: it must not
                     be softened or removed. --}}
                <x-alert variant="info" class="mb-2">
                    Google's permission screen will say <strong>&ldquo;Manage your Business Profile on Google&rdquo;</strong>.
                    That is the only permission Google offers for Business Profile &mdash; there is no read-only option.
                    This platform only <strong>reads</strong> your profile; it will not change anything on Google.
                </x-alert>

                @can('manage_google_business_profile')
                    {{-- Item 9: initiation mutates state, so it is a POST. --}}
                    <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.connect', [$workspaceUid, $businessUid]) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Connect Google account</button>
                    </form>
                @else
                    {{-- Contract §25 — permission denial is communicated,
                         not hidden behind a broken control. --}}
                    <p class="text-caption mb-0">You do not have permission to manage this connection.</p>
                @endcan
            </div>
        </x-card>
    @elseif($connection->state->value === 'revoked')
        {{-- Contract §25.7 — revoked / reconnect. The binding is RETAINED
             and still shown, so the user does not lose their mapping. --}}
        <x-card :padded="true">
            <x-empty-state icon="alert-triangle" title="Google access needs to be reconnected"
                           description="Google has revoked or expired this authorization. Your linked location has been kept — reconnect to resume reading the profile." />
            @can('manage_google_business_profile')
                <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.connect', [$workspaceUid, $businessUid]) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Reconnect Google account</button>
                </form>
            @endcan
        </x-card>
    @endif

    @if($connection && $connection->state->value === 'active')
        <x-card :padded="true" class="mb-2">
            <div class="d-flex justify-content-between align-items-start flex-wrap">
                <div>
                    <p class="text-section-heading mb-1">Connected</p>
                    <p class="mb-1">{{ $connection->google_account_email ?? 'Google account connected' }}</p>
                    <p class="text-caption mb-0">
                        Read-only access.
                        @if($connection->connected_at)
                            Connected {{ $connection->connected_at->diffForHumans() }}.
                        @endif
                    </p>
                </div>
                <div class="text-end">
                    <x-badge variant="success">Read-only</x-badge>
                </div>
            </div>
        </x-card>

        @if(count($bindings) === 0)
            {{-- Contract §25.3 — connected but not bound. --}}
            <x-card :padded="true">
                <x-empty-state icon="link" title="No Google location linked yet"
                               description="Choose which Google location corresponds to this business. Nothing is selected for you." />
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
                @php($binding = $item['binding'])
                <x-card :padded="true" class="mb-2">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div>
                            <p class="text-section-heading mb-1">Linked Google location</p>
                            <p class="mb-1"><strong>{{ $binding->bound_title_snapshot ?? $item['providerLocationResourceName'] }}</strong></p>
                            <p class="text-caption mb-1">Linked to platform location: {{ $item['location']?->name ?? '—' }}</p>
                            <p class="text-caption mb-1">
                                {{ $item['providerLocationResourceName'] }} &middot; {{ $item['providerAccountResourceName'] }}
                            </p>
                            <p class="text-caption mb-0">
                                {{-- Contract §25.10 — refresh status, per binding. --}}
                                @if($item['mirrorIsFresh'] && $binding->mirror_fetched_at)
                                    Last refreshed {{ $binding->mirror_fetched_at->diffForHumans() }}.
                                @else
                                    Refresh required &mdash; Google data is not available or has passed its retention window.
                                @endif
                            </p>
                        </div>
                        <div class="text-end">
                            @if($binding->verification_state)
                                <x-badge :variant="$binding->verification_state->value === 'verified' ? 'success' : 'warning'">
                                    {{ $binding->verification_state->label() }}
                                </x-badge>
                            @endif
                        </div>
                    </div>

                    @if($binding->has_pending_edits)
                        <x-alert variant="info" class="mt-2 mb-0">This Google listing has edits pending review.</x-alert>
                    @endif

                    @if($binding->duplicate_of_resource_name)
                        <x-alert variant="warning" class="mt-2 mb-0">
                            Google reports this listing as a duplicate of another location. Resolve it in Google; this platform never merges listings.
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
                            This location is marked as a storefront, but its address is set to stay private.
                            The address is not sent to or read from Google while that is the case.
                        </x-alert>
                    @endif

                    {{-- The comparison action is scoped to THIS binding. --}}
                    @if($item['comparisonAvailable'])
                        <a class="btn btn-primary mt-2" href="{{ $item['comparisonUrl'] }}">
                            View comparison
                        </a>
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
