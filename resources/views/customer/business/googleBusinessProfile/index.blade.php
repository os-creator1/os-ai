{{--
    GBP Slice A contract §25.2/§25.3/§25.5/§25.7/§25.9/§25.10/§25.11 — the
    Business-scoped overview.

    READ-ONLY SURFACE. There is deliberately no edit, post, review-reply,
    verification, photo-upload or any other write control anywhere in this
    view, and no Reviews/Posts/Media/Performance/Q&A tab — not even
    disabled. There is no GBP score, profile-strength percentage, grade,
    chart or AI-generated analysis (contract §25.10, §31).

    The profile card and the "fields differ" cards are PRESENTATION ONLY:
    every value and every difference comes from
    GoogleBusinessProfileOverviewPresenter, which re-uses the comparator's
    own rows. Nothing is compared in this view.

    Every Google-supplied value is rendered with escaped Blade output. Raw,
    unescaped Blade output is forbidden in every GBP view (contract §14.5,
    test T-XSS-2).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Google Business Profile')

@section('page-style')
    @include('customer.business.googleBusinessProfile._overview-style')
@endsection

@section('content')
    @php
        $isActive = $connection && $connection->state->value === 'active';
    @endphp

    <div class="gbp-header mb-2">
        <div>
            <h1 class="h3 mb-25">Google Business Profile</h1>
            @if($isActive)
                <p class="gbp-connection mb-0">
                    <x-badge variant="success"><span class="gbp-dot" aria-hidden="true"></span>Connected &middot; read-only</x-badge>
                    @if($connection->google_account_email)
                        <span class="text-caption">{{ $connection->google_account_email }}</span>
                    @endif
                    @if($connection->connected_at)
                        <span class="text-caption">&middot; connected {{ $connection->connected_at->diffForHumans() }}</span>
                    @endif
                </p>
            @else
                <p class="text-caption mb-0">{{ $business->name }}</p>
            @endif
        </div>
        @if($isActive)
            <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.gbp.settings', [$workspaceUid, $businessUid]) }}">
                <x-ds-icon name="settings" :size="16" /> Connection settings
            </a>
        @endif
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

    @if($isActive)
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
                @php
                    $binding = $item['binding'];
                    $profile = $item['profile'];
                    $differences = $profile['differences'];
                    $diffCount = count($differences);
                    $verified = $binding->verification_state && $binding->verification_state->value === 'verified';
                    $subtitle = $item['location']?->name;
                @endphp

                <x-card :padded="false" class="gbp-card mb-2">
                    <div class="gbp-card-body">
                        <div class="gbp-profile-head">
                            <div class="gbp-profile-title">
                                <span class="gbp-profile-icon" aria-hidden="true"><x-ds-icon name="map-pin" :size="18" /></span>
                                <div class="min-w-0">
                                    <p class="gbp-eyebrow mb-25">On Google</p>
                                    <h2 class="h5 mb-25">{{ $profile['title'] ?? 'Google Business Profile' }}</h2>
                                    <p class="text-caption mb-0">{{ $subtitle ?? 'Google Business Profile' }}</p>
                                </div>
                            </div>
                            <div class="gbp-chips">
                                @if($binding->verification_state)
                                    <x-badge :variant="$verified ? 'success' : 'warning'">
                                        @if($verified)<x-ds-icon name="check" :size="12" />@endif
                                        {{ $binding->verification_state->label() }}
                                    </x-badge>
                                @endif
                                @if($item['mirrorIsFresh'] && $binding->mirror_fetched_at)
                                    <x-badge>Updated {{ $binding->mirror_fetched_at->diffForHumans() }}</x-badge>
                                @endif
                            </div>
                        </div>

                        @if($profile['fresh'])
                            <dl class="gbp-summary">
                                <div><dt>Phone</dt><dd>{{ $profile['phone'] ?? '—' }}</dd></div>
                                <div><dt>Website</dt><dd class="gbp-break">{{ $profile['website'] ?? '—' }}</dd></div>
                                <div><dt>Category</dt><dd>{{ $profile['category'] ?? '—' }}</dd></div>
                                <div><dt>Location</dt><dd>{{ $profile['location'] ?? '—' }}</dd></div>
                            </dl>

                            <details class="gbp-more">
                                <summary><x-ds-icon name="chevron-down" :size="14" /> <span>Show all details</span></summary>
                                <dl class="gbp-summary gbp-summary-flat">
                                    <div><dt>Platform location</dt><dd>{{ $item['location']?->name ?? '—' }}</dd></div>
                                    <div><dt>Verification</dt><dd>{{ $binding->verification_state?->label() ?? '—' }}</dd></div>
                                    @if($binding->mirror_fetched_at)
                                        <div><dt>Last refreshed</dt><dd>{{ $binding->mirror_fetched_at->diffForHumans() }}</dd></div>
                                    @endif
                                    @foreach($profile['details'] as $detail)
                                        <div><dt>{{ $detail['label'] }}</dt><dd>{{ $detail['value'] }}</dd></div>
                                    @endforeach
                                </dl>
                            </details>
                        @else
                            {{-- Contract §25.10 — refresh status, per binding. --}}
                            <p class="text-caption mb-0">
                                Refresh required &mdash; Google data is not available or has passed its retention window.
                            </p>
                        @endif

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
                    </div>
                </x-card>

                @if($profile['fresh'] && $item['comparisonAvailable'])
                    @if($diffCount > 0)
                        <x-card :padded="false" class="gbp-card mb-2">
                            <div class="gbp-card-body">
                                <div class="gbp-diff-head">
                                    <div>
                                        <h2 class="h5 mb-25">{{ $diffCount }} {{ $diffCount === 1 ? 'field differs' : 'fields differ' }} from Google</h2>
                                        <p class="text-caption mb-0">
                                            {{ $profile['matchCount'] }} {{ $profile['matchCount'] === 1 ? 'field matches' : 'fields match' }}.
                                            Nothing here changes your Google listing.
                                        </p>
                                    </div>
                                    <a class="btn btn-outline-primary" href="{{ $item['comparisonUrl'] }}">
                                        View full comparison <x-ds-icon name="arrow-right" :size="14" />
                                    </a>
                                </div>

                                @foreach($differences as $row)
                                    <div class="gbp-diff">
                                        <p class="gbp-diff-field">{{ $row->field }}</p>
                                        <div class="gbp-diff-pair">
                                            <div class="gbp-value">
                                                <span class="gbp-eyebrow">Stored here</span>
                                                <span class="{{ $row->platformValue === null ? 'gbp-unset' : 'gbp-break' }}">{{ $row->platformValue ?? 'Not set' }}</span>
                                            </div>
                                            <span class="gbp-swap" aria-hidden="true"><x-ds-icon name="arrow-left-right" :size="14" /></span>
                                            <div class="gbp-value">
                                                <span class="gbp-eyebrow">On Google</span>
                                                <span class="{{ $row->googleValue === null ? 'gbp-unset' : 'gbp-break' }}">{{ $row->googleValue ?? 'Not set' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </x-card>
                    @else
                        <x-card :padded="false" class="gbp-card mb-2">
                            <div class="gbp-card-body gbp-diff-head">
                                <div class="gbp-match">
                                    <span class="gbp-match-icon" aria-hidden="true"><x-ds-icon name="check" :size="16" /></span>
                                    <div>
                                        <h2 class="h5 mb-25">Everything matches Google</h2>
                                        <p class="text-caption mb-0">
                                            {{ $profile['matchCount'] }} {{ $profile['matchCount'] === 1 ? 'field matches' : 'fields match' }}.
                                            Nothing here changes your Google listing.
                                        </p>
                                    </div>
                                </div>
                                <a class="btn btn-outline-primary" href="{{ $item['comparisonUrl'] }}">
                                    View full comparison <x-ds-icon name="arrow-right" :size="14" />
                                </a>
                            </div>
                        </x-card>
                    @endif
                @elseif($item['comparisonAvailable'])
                    <a class="btn btn-outline-primary mb-2" href="{{ $item['comparisonUrl'] }}">View comparison</a>
                @endif
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
            </div>
        @endif
    @endif
@endsection
