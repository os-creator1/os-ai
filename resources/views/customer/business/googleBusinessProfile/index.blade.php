{{--
    GBP Slice A contract §25.2/§25.3/§25.5/§25.7/§25.9/§25.10/§25.11 — the
    Business-scoped overview.

    READ-ONLY SURFACE. There is deliberately no edit, post, review-reply,
    verification, photo-upload or any other write control anywhere in this
    view, and no Reviews/Posts/Media/Q&A tab — not even
    disabled. There is no GBP score, profile-strength percentage, grade
    or AI-generated analysis (contract §25.10, §31). The one addition is
    the read-only "Google performance" section (_performance.blade.php): Google's
    own counts and search terms, loaded only on request and never stored.

    Layout: not connected = one short connect card (the §25.9 permission
    wording sits behind a disclosure, never removed); connected = the
    linked profile first (name, status, key facts), then only what differs.
    The profile card and the "fields differ" cards are PRESENTATION ONLY:
    every value and every difference comes from
    GoogleBusinessProfileOverviewPresenter, which re-uses the comparator's
    own rows. Nothing is compared in this view.

    Every Google-supplied value is rendered with escaped Blade output. Raw,
    unescaped Blade output is forbidden in every GBP view (contract §14.5,
    test T-XSS-2).
--

    Section order (connected): Your business on Google, Google performance,
    Customer reviews, Improve your listing, Business photos. Reviews and photos
    are NOT read by any integration yet (the profile mirror deliberately holds
    neither), so those two sections render an honest "not available" state and
    a link to Google; nothing is invented. Improvement items are derived only
    from facts already on the page (comparator rows, binding state, retention).
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Google Business Profile')

@section('page-style')
    @include('customer.business.googleBusinessProfile._overview-style')
@endsection

@section('content')
    @php
        $isActive = $connection && $connection->state->value === 'active';
        $connectUrl = route('customer.workspaces.businesses.gbp.connect', [$workspaceUid, $businessUid]);
        $locationsUrl = route('customer.workspaces.businesses.gbp.locations', [$workspaceUid, $businessUid]);
        $refreshUrl = route('customer.workspaces.businesses.gbp.refresh', [$workspaceUid, $businessUid]);
        $googleManageUrl = 'https://business.google.com/';
        $anyStale = $isActive && collect($bindings)->contains(fn ($item) => ! $item['profile']['fresh']);
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
            <div class="d-flex flex-wrap gap-1">
                @if (! empty($canManageGoogle) && count($bindings) > 0)
                    <form method="POST" action="{{ $refreshUrl }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary">
                            <x-ds-icon name="refresh-cw" :size="16" /> Refresh {{ count($bindings) === 1 ? 'from Google' : 'all ' . count($bindings) . ' locations from Google' }}
                        </button>
                    </form>
                @endif
                <a class="btn btn-outline-secondary" href="{{ route('customer.workspaces.businesses.gbp.settings', [$workspaceUid, $businessUid]) }}">
                    <x-ds-icon name="settings" :size="16" /> Connection settings
                </a>
            </div>
        @endif
    </div>

    <x-flash-alert class="mb-2" />

    {{-- Contract §25.11 — the safe error state. A normalized
         classification only; never a raw provider message. --}}
    @if($connection && $connection->failure_classification)
        <x-alert variant="warning" class="mb-2">
            The last Google request did not succeed ({{ str_replace('_', ' ', $connection->failure_classification) }}).
            @if (! empty($canManageGoogle) && $connection->state->value === 'active')
                Use Refresh to try again.
            @endif
        </x-alert>
    @endif

    @if(! $connection || $connection->state->value === 'disconnected' || $connection->state->value === 'pending')
        {{-- Contract §25.2 — not connected. --}}
        <x-card :padded="true" class="gbp-connect">
            <div class="gbp-hero-icon"><x-ds-icon name="map-pin" size="32" /></div>
            <div>
                <h2 class="h4 mb-50">Connect Google Business Profile</h2>
                <p class="gbp-connect-lead mb-0">See how you look on Google. Connect your profile and we&rsquo;ll compare it with your MotionGrove details.</p>
            </div>

            <div class="gbp-benefits">
                <div class="gbp-benefit">
                    <span class="gbp-tile"><x-ds-icon name="store" :size="18" /></span>
                    <strong>Your listing</strong>
                    <span class="text-caption">Your name, category, phone and website as Google shows them, with Google&rsquo;s own performance figures.</span>
                </div>
                <div class="gbp-benefit">
                    <span class="gbp-tile"><x-ds-icon name="arrow-left-right" :size="18" /></span>
                    <strong>Spot differences</strong>
                    <span class="text-caption">See where Google and MotionGrove don&rsquo;t match.</span>
                </div>
                <div class="gbp-benefit">
                    <span class="gbp-tile"><x-ds-icon name="trending-up" :size="18" /></span>
                    <strong>Get found more</strong>
                    <span class="text-caption">Spot missing information that keeps customers away.</span>
                </div>
            </div>

            @if (! empty($canManageGoogle))
                {{-- Item 9: initiation mutates state, so it is a POST. --}}
                <form method="POST" action="{{ $connectUrl }}" data-gbp-connect-form>
                    @csrf
                    <button type="submit" class="btn btn-primary gbp-connect-cta" data-gbp-connect-button>
                        <x-ds-icon name="plug" :size="18" /> Connect Google
                    </button>
                </form>
                <script>
                    (function () {
                        var form = document.querySelector('[data-gbp-connect-form]');
                        if (!form) { return; }
                        form.addEventListener('submit', function () {
                            var button = form.querySelector('[data-gbp-connect-button]');
                            if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); button.textContent = 'Opening Google…'; }
                        });
                    })();
                </script>
            @else
                {{-- Contract §25 — permission denial is communicated,
                     not hidden behind a broken control. --}}
                <p class="text-caption mb-0">You do not have permission to manage this connection.</p>
            @endif

            <div>
                <p class="gbp-connect-note text-caption mb-50"><x-ds-icon name="shield-check" :size="14" /> We only read your profile. Nothing on Google changes without your action.</p>

                {{-- Contract §25.9 — REQUIRED consent disclosure, available before every Connect. It is a
                     factual statement of a Google constraint, not marketing copy: it must not be softened
                     or removed, only kept out of the way. --}}
                <details class="text-start d-block" data-role="gbp-permission-disclosure">
                    <summary class="text-caption">Why does Google ask for this permission?</summary>
                    <p class="text-caption mb-0 mt-50">
                        Google's permission screen will say <strong>&ldquo;Manage your Business Profile on Google&rdquo;</strong>.
                        That is the only permission Google offers for Business Profile &mdash; there is no read-only option.
                        This platform only <strong>reads</strong> your profile; it will not change anything on Google.
                    </p>
                </details>
            </div>
        </x-card>
    @elseif($connection->state->value === 'revoked')
        {{-- Contract §25.7 — revoked / reconnect. The binding is RETAINED
             and still shown, so the user does not lose their mapping. --}}
        <x-card :padded="true" class="gbp-connect">
            <div class="gbp-hero-icon"><x-ds-icon name="alert-triangle" size="32" /></div>
            <div>
                <h2 class="h4 mb-50">Reconnect Google</h2>
                <p class="gbp-connect-lead mb-0">Google access has expired or was removed. Your linked location is kept.</p>
            </div>
            @if (! empty($canManageGoogle))
                <form method="POST" action="{{ $connectUrl }}">
                    @csrf
                    <button type="submit" class="btn btn-primary gbp-connect-cta">Reconnect Google</button>
                </form>
            @else
                <p class="text-caption mb-0">You do not have permission to manage this connection.</p>
            @endif
        </x-card>
    @endif

    @if($isActive)
        @if(count($bindings) === 0)
            {{-- Contract §25.3 — connected but not bound. --}}
            <x-card :padded="true" class="gbp-connect">
                <div class="gbp-hero-icon"><x-ds-icon name="link" size="32" /></div>
                <div>
                    <h2 class="h4 mb-50">Choose your Google location</h2>
                    <p class="gbp-connect-lead mb-0">Google is connected. Pick the Google listing that matches this business &mdash; nothing is linked until you choose.</p>
                </div>
                @if (! empty($canManageGoogle))
                    <a class="btn btn-primary gbp-connect-cta" href="{{ $locationsUrl }}">Choose location</a>
                @else
                    <p class="text-caption mb-0">You do not have permission to manage this connection.</p>
                @endif
            </x-card>
        @else
            <div class="gbp-layout">
            {{-- Contract §25.5 — connected and bound. EVERY binding this
                 Business owns is listed; no "primary" binding is inferred
                 and the collection is never collapsed to one row. --}}

            {{-- 1. Your business on Google --}}
            @foreach($bindings as $item)
                @php
                    $binding = $item['binding'];
                    $profile = $item['profile'];
                    $differences = $profile['differences'];
                    $diffCount = count($differences);
                    $verified = $binding->verification_state && $binding->verification_state->value === 'verified';
                    $subtitle = $item['location']?->name;
                @endphp

                <x-card :padded="false" class="gbp-card" data-role="gbp-business">
                    <div class="gbp-card-body">
                        <div class="gbp-profile-head mb-0">
                            <div class="gbp-profile-title">
                                <span class="gbp-profile-icon" aria-hidden="true"><x-ds-icon name="map-pin" :size="18" /></span>
                                <div class="min-w-0">
                                    <p class="gbp-eyebrow mb-25">On Google</p>
                                    <h2 class="h5 mb-25 gbp-break">{{ $profile['title'] ?? 'Google Business Profile' }}</h2>
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
                                @if($profile['mapsUri'])
                                    <a class="btn btn-sm btn-outline-primary" href="{{ $profile['mapsUri'] }}" target="_blank" rel="noopener noreferrer">
                                        <x-ds-icon name="map" :size="14" /> View on Google Maps
                                    </a>
                                @endif
                            </div>
                        </div>

                        @if($profile['fresh'])
                            @if($profile['category'] || $profile['additionalCategories'])
                                <div class="gbp-tags mt-2">
                                    @if($profile['category'])<span class="gbp-tag is-primary">{{ $profile['category'] }}</span>@endif
                                    @foreach($profile['additionalCategories'] as $extra)<span class="gbp-tag">{{ $extra }}</span>@endforeach
                                </div>
                            @endif

                            <dl class="gbp-kv">
                                <div><dt>Phone</dt><dd>{{ $profile['phone'] ?? 'No phone listed' }}</dd></div>
                                <div><dt>Website</dt><dd>{{ $profile['website'] ?? 'No website listed' }}</dd></div>
                                <div><dt>Category</dt><dd>{{ $profile['category'] ?? '—' }}</dd></div>
                                <div><dt>Location</dt><dd>{{ $profile['location'] ?? '—' }}</dd></div>
                                <div><dt>Hours</dt><dd class="gbp-unset">Not read from Google yet</dd></div>
                                <div><dt>Rating &amp; reviews</dt><dd class="gbp-unset">Not read from Google yet</dd></div>
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
                            {{-- Contract §25.10 — refresh status, per binding. The mirror is kept only for the
                                 configured retention window (none by default), so an empty mirror is expected,
                                 not an error: the numbers in Google performance below load separately. --}}
                            <div class="gbp-stale mt-3" data-role="gbp-stale">
                                <div>
                                    <p class="mb-25"><strong>Refresh required</strong></p>
                                    <p class="text-caption mb-0">
                                        Google data is not available or has passed its retention window. Refresh to read this listing from Google again;
                                        nothing is shown from memory.
                                        @if($binding->bound_title_snapshot) Linked listing: {{ $binding->bound_title_snapshot }}. @endif
                                    </p>
                                </div>
                                @if (! empty($canManageGoogle))
                                    <form method="POST" action="{{ $refreshUrl }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-primary"><x-ds-icon name="refresh-cw" :size="14" /> Refresh from Google</button>
                                    </form>
                                @endif
                            </div>
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
            @endforeach

            {{-- 2. Google performance (own card; also lists search terms) --}}
            @include('customer.business.googleBusinessProfile._performance')

            {{-- 3. Customer reviews: no reviews integration yet — honest unavailable state. --}}
            <x-card :padded="false" class="gbp-card" data-role="gbp-reviews">
                <div class="gbp-card-body">
                    <div class="gbp-section-head">
                        <div>
                            <p class="gbp-eyebrow mb-25">On Google</p>
                            <h2 class="h5">Customer reviews</h2>
                        </div>
                        <a class="gbp-link" href="https://business.google.com/reviews" target="_blank" rel="noopener noreferrer">Manage reviews on Google <x-ds-icon name="external-link" :size="12" /></a>
                    </div>
                    <div class="gbp-unavail">
                        <span class="gbp-tile is-neutral"><x-ds-icon name="star" :size="16" /></span>
                        <p class="text-caption">
                            <strong>Reviews are not shown here yet</strong>
                            MotionGrove does not read your Google rating, review breakdown or recent reviews today, so none are displayed rather than guessing.
                            Read and answer reviews in Google.
                        </p>
                    </div>
                </div>
            </x-card>

            {{-- 4. Improve your listing: only facts already on this page. --}}
            @php
                $actions = [];
                foreach ($bindings as $item) {
                    $b = $item['binding'];
                    $p = $item['profile'];
                    $where = count($bindings) > 1 ? ($item['location']?->name ?? 'A linked location') . ': ' : '';
                    $cmpUrl = $item['comparisonUrl'];
                    if (! $p['fresh']) {
                        $actions[] = ['icon' => 'refresh-cw', 'tone' => 'warning', 'title' => $where . 'Refresh Google data', 'text' => 'Without a current read of this listing we cannot check it for gaps or mismatches.', 'url' => null, 'cta' => null];
                        continue;
                    }
                    foreach ($p['differences'] as $row) {
                        $actions[] = ['icon' => 'arrow-left-right', 'tone' => 'warning', 'title' => $where . $row->field . ' doesn’t match', 'text' => 'Google: ' . ($row->googleValue ?? 'Not set') . ' · Stored here: ' . ($row->platformValue ?? 'Not set'), 'url' => $cmpUrl, 'cta' => 'Review'];
                    }
                    if ($p['website'] === null && ! collect($p['differences'])->contains(fn ($r) => $r->field === 'Website')) {
                        $actions[] = ['icon' => 'globe', 'tone' => 'warning', 'title' => $where . 'Google shows no website', 'text' => 'Customers cannot reach your site from the listing.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                    }
                    if ($p['phone'] === null && ! collect($p['differences'])->contains(fn ($r) => $r->field === 'Phone')) {
                        $actions[] = ['icon' => 'phone', 'tone' => 'warning', 'title' => $where . 'Google shows no phone number', 'text' => 'Customers cannot call you from the listing.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                    }
                    if ($p['category'] === null) {
                        $actions[] = ['icon' => 'tag', 'tone' => 'warning', 'title' => $where . 'No primary category on Google', 'text' => 'A category helps Google show you for the right searches.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                    }
                    if ($b->verification_state && $b->verification_state->value !== 'verified') {
                        $actions[] = ['icon' => 'shield-alert', 'tone' => 'warning', 'title' => $where . 'Listing is ' . strtolower($b->verification_state->label()), 'text' => 'Google decides what an unverified listing can show.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                    }
                    if ($b->has_pending_edits) {
                        $actions[] = ['icon' => 'clock', 'tone' => 'info', 'title' => $where . 'Edits are pending review', 'text' => 'Google is reviewing a change to this listing.', 'url' => null, 'cta' => null];
                    }
                    if ($b->duplicate_of_resource_name) {
                        $actions[] = ['icon' => 'copy', 'tone' => 'warning', 'title' => $where . 'Possible duplicate listing', 'text' => 'Google reports a duplicate; resolve it in Google.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                    }
                    if ($item['addressContradiction']) {
                        $actions[] = ['icon' => 'map-pin', 'tone' => 'warning', 'title' => $where . 'Address privacy conflicts with storefront', 'text' => 'The address is not sent to Google while it is private.', 'url' => null, 'cta' => null];
                    }
                }
            @endphp
            <x-card :padded="false" class="gbp-card" data-role="gbp-improve">
                <div class="gbp-card-body">
                    <div class="gbp-section-head">
                        <div>
                            <h2 class="h5 mb-25">Improve your listing</h2>
                            <p class="text-caption mb-0">Based on what your listing shows today.</p>
                        </div>
                        <span class="text-caption">{{ count($actions) === 0 ? 'Nothing to do' : count($actions) . ' to do' }}</span>
                    </div>
                    @if(count($actions) === 0)
                        <div class="gbp-match">
                            <span class="gbp-match-icon" aria-hidden="true"><x-ds-icon name="check" :size="16" /></span>
                            <p class="mb-0">No gaps found in the information we can read. Hours, photos and reviews are not checked yet.</p>
                        </div>
                    @else
                        <div class="gbp-actions">
                            @foreach($actions as $action)
                                <div class="gbp-action">
                                    <span class="gbp-tile {{ $action['tone'] === 'warning' ? 'is-warning' : '' }}"><x-ds-icon :name="$action['icon']" :size="16" /></span>
                                    <div>
                                        <b>{{ $action['title'] }}</b>
                                        <p class="text-caption gbp-break">{{ $action['text'] }}</p>
                                    </div>
                                    @if($action['url'])
                                        <a class="gbp-link" href="{{ $action['url'] }}" @if(str_starts_with($action['url'], 'https://')) target="_blank" rel="noopener noreferrer" @endif>{{ $action['cta'] }}</a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </x-card>

            {{-- Differences summary (links to the full comparison). Presentation split of the comparator's own rows. --}}
            @foreach($bindings as $item)
                @php
                    $profile = $item['profile'];
                    $differences = $profile['differences'];
                    $diffCount = count($differences);
                @endphp
                @if($profile['fresh'] && $item['comparisonAvailable'])
                    @if($diffCount > 0)
                        <x-card :padded="false" class="gbp-card">
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
                        <x-card :padded="false" class="gbp-card">
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
                    <div><a class="btn btn-outline-primary" href="{{ $item['comparisonUrl'] }}">View comparison</a></div>
                @endif
            @endforeach

            {{-- 5. Business photos: no media integration yet — honest unavailable state. --}}
            <x-card :padded="false" class="gbp-card" data-role="gbp-photos">
                <div class="gbp-card-body">
                    <div class="gbp-section-head">
                        <h2 class="h5">Business photos</h2>
                        <a class="gbp-link" href="https://business.google.com/" target="_blank" rel="noopener noreferrer">Manage photos on Google <x-ds-icon name="external-link" :size="12" /></a>
                    </div>
                    <div class="gbp-photo-grid mb-2" aria-hidden="true">
                        <div class="gbp-photo-slot">Cover</div>
                        <div class="gbp-photo-slot">Logo</div>
                    </div>
                    <div class="gbp-unavail">
                        <span class="gbp-tile is-neutral"><x-ds-icon name="image" :size="16" /></span>
                        <p class="text-caption">
                            <strong>Photos are not shown here yet</strong>
                            MotionGrove does not read your Google cover, logo or gallery today, so no images are displayed. Add and manage photos in Google.
                        </p>
                    </div>
                </div>
            </x-card>

            <div class="d-flex gap-1 flex-wrap">
                @if (! empty($canManageGoogle))
                    <a class="btn btn-outline-primary" href="{{ $locationsUrl }}">
                        Link another location
                    </a>
                @endif
            </div>
            </div>
        @endif
    @endif
@endsection
