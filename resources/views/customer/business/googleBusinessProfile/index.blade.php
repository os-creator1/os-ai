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
        $settingsUrl = route('customer.workspaces.businesses.gbp.settings', [$workspaceUid, $businessUid]);
        $googleManageUrl = 'https://business.google.com/';
        $retentionDays = (int) ($mirrorRetentionDays ?? 0);
        $firstMaps = null;
        foreach ($bindings as $candidateItem) {
            if (! empty($candidateItem['profile']['mapsUri'])) { $firstMaps = $candidateItem['profile']['mapsUri']; break; }
        }
    @endphp

    <div class="gbp-page gbp-main">
        {{-- Page header (prototype: title + connection line left, Refresh / Maps right) --}}
        <div class="gbp-head gbp-wrap">
            <div class="gbp-head-text">
                <h1 class="gbp-h1">Google Business Profile</h1>
                @if($isActive)
                    <div class="gbp-meta">
                        <span class="gbp-pill"><span class="gbp-pill-dot" aria-hidden="true"></span>Connected &middot; read-only</span>
                        @if($connection->google_account_email)
                            <span>{{ $connection->google_account_email }}</span>
                        @endif
                        @if($connection->connected_at)
                            <span>&middot; connected {{ $connection->connected_at->diffForHumans() }}</span>
                        @endif
                    </div>
                @else
                    <p class="gbp-sub">{{ $business->name }}</p>
                @endif
            </div>
            @if($isActive)
                @if (! empty($canManageGoogle) && count($bindings) > 0)
                    <form method="POST" action="{{ $refreshUrl }}">
                        @csrf
                        <button type="submit" class="gbp-btn is-outline"><x-ds-icon name="refresh-cw" :size="16" /> Refresh {{ count($bindings) === 1 ? 'from Google' : 'all ' . count($bindings) . ' locations from Google' }}</button>
                    </form>
                @endif
                @if($firstMaps)
                    <a class="gbp-btn is-primary" href="{{ $firstMaps }}" target="_blank" rel="noopener noreferrer"><x-ds-icon name="map-pin" :size="16" /> View on Google Maps</a>
                @endif
                <a class="gbp-btn is-outline" href="{{ $settingsUrl }}"><x-ds-icon name="settings" :size="16" /> Connection settings</a>
            @endif
        </div>

        <x-flash-alert />

        {{-- Contract §25.11 — the safe error state. A normalized classification only; never a raw provider message. --}}
        @if($connection && $connection->failure_classification)
            <x-alert variant="warning" class="gbp-wrap mb-0">
                The last Google request did not succeed ({{ str_replace('_', ' ', $connection->failure_classification) }}).
                @if (! empty($canManageGoogle) && $connection->state->value === 'active')
                    Use Refresh to try again.
                @endif
            </x-alert>
        @endif

        @if(! $connection || $connection->state->value === 'disconnected' || $connection->state->value === 'pending')
            {{-- Contract §25.2 — not connected. Layout: GbpConnect. --}}
            <section class="gbp-connect gbp-wrap-sm">
                <span class="gbp-connect-icon"><x-ds-icon name="map-pin" :size="32" /></span>
                <div class="gbp-connect-copy">
                    <h2>See how you look on Google</h2>
                    <p>Connect your profile and we&rsquo;ll compare it with your MotionGrove details.</p>
                </div>
                <div class="gbp-benefits">
                    <div class="gbp-benefit">
                        <span class="gbp-tile"><x-ds-icon name="store" :size="18" /></span>
                        <b>Your listing</b>
                        <span class="d">Your name, category, phone and website as Google shows them.</span>
                    </div>
                    <div class="gbp-benefit">
                        <span class="gbp-tile"><x-ds-icon name="arrow-left-right" :size="18" /></span>
                        <b>Spot differences</b>
                        <span class="d">See where Google and MotionGrove don&rsquo;t match.</span>
                    </div>
                    <div class="gbp-benefit">
                        <span class="gbp-tile"><x-ds-icon name="trending-up" :size="18" /></span>
                        <b>Get found more</b>
                        <span class="d">Fix missing info that keeps customers away.</span>
                    </div>
                </div>

                @if (! empty($canManageGoogle))
                    {{-- Item 9: initiation mutates state, so it is a POST. --}}
                    <form method="POST" action="{{ $connectUrl }}" data-gbp-connect-form>
                        @csrf
                        <button type="submit" class="gbp-btn is-primary gbp-connect-cta" data-gbp-connect-button><x-ds-icon name="plug" :size="18" /> Connect Google</button>
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
                    {{-- Contract §25 — permission denial is communicated, not hidden behind a broken control. --}}
                    <p class="gbp-sub mb-0">You do not have permission to manage this connection.</p>
                @endif

                <div class="gbp-connect-foot">
                    <p class="gbp-connect-note"><x-ds-icon name="lock" :size="14" /> Read-only. Nothing on Google changes unless you do it.</p>
                    {{-- Contract §25.9 — REQUIRED consent disclosure, available before every Connect. It is a factual
                         statement of a Google constraint: it must not be softened or removed, only kept out of the way. --}}
                    <details class="gbp-more" data-role="gbp-permission-disclosure">
                        <summary>Why does Google ask for permission? <x-ds-icon name="chevron-down" :size="14" /></summary>
                        <p>
                            Google's permission screen will say <strong>&ldquo;Manage your Business Profile on Google&rdquo;</strong>.
                            That is the only permission Google offers for Business Profile &mdash; there is no read-only option.
                            This platform only <strong>reads</strong> your profile; it will not change anything on Google.
                        </p>
                    </details>
                </div>
            </section>
        @elseif($connection->state->value === 'revoked')
            {{-- Contract §25.7 — revoked / reconnect. The binding is RETAINED and still shown. --}}
            <section class="gbp-connect gbp-wrap-sm">
                <span class="gbp-connect-icon"><x-ds-icon name="alert-triangle" :size="32" /></span>
                <div class="gbp-connect-copy">
                    <h2>Reconnect Google</h2>
                    <p>Google access has expired or was removed. Your linked location is kept.</p>
                </div>
                @if (! empty($canManageGoogle))
                    <form method="POST" action="{{ $connectUrl }}">
                        @csrf
                        <button type="submit" class="gbp-btn is-primary gbp-connect-cta">Reconnect Google</button>
                    </form>
                @else
                    <p class="gbp-sub mb-0">You do not have permission to manage this connection.</p>
                @endif
            </section>
        @endif

        @if($isActive)
            @if(count($bindings) === 0)
                {{-- Contract §25.3 — connected but not bound. --}}
                <section class="gbp-connect gbp-wrap-sm">
                    <span class="gbp-connect-icon"><x-ds-icon name="link" :size="32" /></span>
                    <div class="gbp-connect-copy">
                        <h2>Choose your Google location</h2>
                        <p>Google is connected. Pick the Google listing that matches this business &mdash; nothing is linked until you choose.</p>
                    </div>
                    @if (! empty($canManageGoogle))
                        <a class="gbp-btn is-primary gbp-connect-cta" href="{{ $locationsUrl }}">Choose location</a>
                    @else
                        <p class="gbp-sub mb-0">You do not have permission to manage this connection.</p>
                    @endif
                </section>
            @else
                {{-- Improvement actions: only facts already on this page. --}}
                @php
                    $actions = [];
                    foreach ($bindings as $item) {
                        $b = $item['binding'];
                        $p = $item['profile'];
                        $where = count($bindings) > 1 ? ($item['location']?->name ?? 'A linked location') . ': ' : '';
                        $cmpUrl = $item['comparisonUrl'];
                        if (! $p['fresh']) {
                            $actions[] = ['icon' => 'refresh-cw', 'tone' => 'warn', 'title' => $where . ($retentionDays === 0 ? 'Read this listing from Google' : 'Refresh Google data'), 'text' => 'Without a current read of this listing we cannot check it for gaps or mismatches.', 'url' => null, 'cta' => $retentionDays === 0 ? 'View live' : 'Refresh', 'refresh' => true];
                            continue;
                        }
                        foreach ($p['differences'] as $row) {
                            $actions[] = ['icon' => 'arrow-left-right', 'tone' => 'warn', 'title' => $where . $row->field . ' doesn’t match', 'text' => 'Stored here: ' . ($row->platformValue ?? 'Not set') . ' · On Google: ' . ($row->googleValue ?? 'Not set'), 'url' => $cmpUrl, 'cta' => 'Review'];
                        }
                        if ($p['website'] === null && ! collect($p['differences'])->contains(fn ($r) => $r->field === 'Website')) {
                            $actions[] = ['icon' => 'globe', 'tone' => 'warn', 'title' => $where . 'Google shows no website', 'text' => 'Customers cannot reach your site from the listing.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                        }
                        if ($p['phone'] === null && ! collect($p['differences'])->contains(fn ($r) => $r->field === 'Phone')) {
                            $actions[] = ['icon' => 'phone', 'tone' => 'warn', 'title' => $where . 'Google shows no phone number', 'text' => 'Customers cannot call you from the listing.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                        }
                        if ($p['category'] === null) {
                            $actions[] = ['icon' => 'tag', 'tone' => 'warn', 'title' => $where . 'No primary category on Google', 'text' => 'A category helps Google show you for the right searches.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                        }
                        if ($b->verification_state && $b->verification_state->value !== 'verified') {
                            $actions[] = ['icon' => 'shield-alert', 'tone' => 'warn', 'title' => $where . 'Listing is ' . strtolower($b->verification_state->label()), 'text' => 'Google decides what an unverified listing can show.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                        }
                        if ($b->has_pending_edits) {
                            $actions[] = ['icon' => 'clock', 'tone' => 'info', 'title' => $where . 'Edits are pending review', 'text' => 'Google is reviewing a change to this listing.', 'url' => null, 'cta' => null];
                        }
                        if ($b->duplicate_of_resource_name) {
                            $actions[] = ['icon' => 'copy', 'tone' => 'warn', 'title' => $where . 'Possible duplicate listing', 'text' => 'Google reports a duplicate; resolve it in Google.', 'url' => $googleManageUrl, 'cta' => 'Open Google'];
                        }
                        if ($item['addressContradiction']) {
                            $actions[] = ['icon' => 'map-pin', 'tone' => 'info', 'title' => $where . 'Address privacy conflicts with storefront', 'text' => 'The address is not sent to Google while it is private.', 'url' => null, 'cta' => null];
                        }
                    }
                @endphp

                <div class="gbp-cols gbp-wrap">
                    {{-- ============ LEFT COLUMN: profile, performance, reviews ============ --}}
                    <div class="gbp-col-main">
                        {{-- Contract §25.5 — EVERY binding this Business owns is listed; none is collapsed to one row. --}}
                        @foreach($bindings as $item)
                            @php
                                $binding = $item['binding'];
                                $profile = $item['profile'];
                                $verified = $binding->verification_state && $binding->verification_state->value === 'verified';
                                $initial = mb_strtoupper(mb_substr(trim((string) ($profile['title'] ?? '')) ?: 'G', 0, 1));
                                $subtitle = $item['location']?->name;
                            @endphp
                            <section class="gbp-cd is-clip" data-role="gbp-business">
                                <div class="gbp-cover" aria-hidden="true"><span class="gbp-cover-badge">On Google &middot; cover photo not available</span></div>
                                <div class="gbp-prof">
                                    <div class="gbp-prof-top">
                                        <span class="gbp-logo {{ $profile['fresh'] ? '' : 'is-empty' }}" aria-hidden="true"><span>{{ $profile['fresh'] ? $initial : 'G' }}</span></span>
                                        <div class="gbp-prof-name">
                                            <b>{{ $profile['title'] ?? 'Google Business Profile' }}</b>
                                            <div class="gbp-prof-sub">
                                                @if($binding->verification_state)
                                                    <span class="gbp-pill {{ $verified ? '' : 'is-warn' }}">@if($verified)<x-ds-icon name="check" :size="12" />@endif {{ $binding->verification_state->label() }}</span>
                                                @endif
                                                @if($profile['fresh'])
                                                    @if($profile['category'])<span>{{ $profile['category'] }}</span>@endif
                                                    @if($item['mirrorIsFresh'] && $binding->mirror_fetched_at)<span class="is-faint">&middot; Updated {{ $binding->mirror_fetched_at->diffForHumans() }}</span>@endif
                                                @endif
                                                <span class="is-faint">MotionGrove location: {{ $subtitle ?? '—' }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    @if($profile['fresh'])
                                        <p class="gbp-prof-desc is-faint" style="color: var(--g-faint)">Description, rating and hours are not read from Google yet.</p>
                                        <div class="gbp-kv2">
                                            <div><x-ds-icon name="clock" :size="16" /><span class="is-faint">Hours not read from Google yet</span></div>
                                            <div><x-ds-icon name="phone" :size="16" /><span class="{{ $profile['phone'] ? '' : 'is-faint' }}">{{ $profile['phone'] ?? 'No phone listed' }}</span></div>
                                            <div><x-ds-icon name="globe" :size="16" /><span class="{{ $profile['website'] ? '' : 'is-faint' }}">{{ $profile['website'] ?? 'No website listed' }}</span></div>
                                            <div><x-ds-icon name="map-pin" :size="16" /><span class="{{ $profile['location'] ? '' : 'is-faint' }}">{{ $profile['location'] ?? 'No location shown' }}</span></div>
                                        </div>
                                        @if($profile['category'] || $profile['additionalCategories'])
                                            <div class="gbp-cats">
                                                <span class="gbp-eyebrow">Categories &amp; services</span>
                                                <div class="gbp-tags">
                                                    @if($profile['category'])<span class="gbp-tg is-primary">{{ $profile['category'] }}</span>@endif
                                                    @foreach($profile['additionalCategories'] as $extra)<span class="gbp-tg">{{ $extra }}</span>@endforeach
                                                </div>
                                            </div>
                                        @endif

                                        <details class="gbp-details">
                                            <summary><x-ds-icon name="chevron-down" :size="14" /> <span>Show all details</span></summary>
                                            <dl class="gbp-dl">
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
                                        {{-- Contract §25.10 — refresh status, per binding. With zero retention an empty mirror is
                                             expected, not an error: Google performance below loads separately. --}}
                                        <div class="gbp-stale" data-role="gbp-stale">
                                            <span class="gbp-tile is-warn" style="width:44px;height:44px;border-radius:12px"><x-ds-icon name="refresh-cw" :size="20" /></span>
                                            <div class="t">
                                                <b>Refresh required</b>
                                                <span>
                                                    @if($retentionDays === 0)
                                                        This deployment does not keep your Google listing between visits. Refresh reads it live from Google and shows it for that one visit only,
                                                        so this overview stays empty until you refresh.
                                                    @else
                                                        Google data is not available or has passed its {{ $retentionDays }}-day retention window. Refresh to read this listing from Google again;
                                                        nothing is shown from memory.
                                                    @endif
                                                    @if($binding->bound_title_snapshot) Linked listing: {{ $binding->bound_title_snapshot }}. @endif
                                                </span>
                                            </div>
                                            @if (! empty($canManageGoogle))
                                                <form method="POST" action="{{ $refreshUrl }}">
                                                    @csrf
                                                    <button type="submit" class="gbp-btn is-primary"><x-ds-icon name="refresh-cw" :size="16" /> {{ $retentionDays === 0 ? 'View live from Google' : 'Refresh from Google' }}</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif

                                    @if($binding->has_pending_edits || $binding->duplicate_of_resource_name || ($binding->open_status && $binding->open_status !== 'OPEN') || $item['addressContradiction'])
                                        <div class="gbp-alerts">
                                            @if($binding->has_pending_edits)
                                                <div class="gbp-alert is-info">This Google listing has edits pending review.</div>
                                            @endif
                                            @if($binding->duplicate_of_resource_name)
                                                <div class="gbp-alert">Google reports this listing as a duplicate of another location. Resolve it in Google; this platform never merges listings.</div>
                                            @endif
                                            @if($binding->open_status && $binding->open_status !== 'OPEN')
                                                <div class="gbp-alert">Google shows this location as {{ $binding->open_status === 'CLOSED_PERMANENTLY' ? 'permanently closed' : 'temporarily closed' }}.</div>
                                            @endif
                                            {{-- Contract §23.6 — the storefront/consent contradiction is SURFACED, never silently resolved. --}}
                                            @if($item['addressContradiction'])
                                                <div class="gbp-alert">This location is marked as a storefront, but its address is set to stay private. The address is not sent to or read from Google while that is the case.</div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </section>
                        @endforeach

                        {{-- Performance (own partial: also refreshed in place by the page script) --}}
                        @include('customer.business.googleBusinessProfile._performance')

                        {{-- Customer reviews: the integration does not read ratings or reviews, so the card keeps its
                             composition with an honest unavailable state. --}}
                        <section class="gbp-cd is-clip" data-role="gbp-reviews">
                            <div class="gbp-ch" style="padding-bottom:14px">
                                <b>Reviews</b>
                                <a href="https://business.google.com/reviews" target="_blank" rel="noopener noreferrer">Manage reviews on Google</a>
                            </div>
                            <div class="gbp-rv-sum" aria-hidden="true">
                                <div class="gbp-rv-big"><b>N/A</b><span class="gbp-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</span><span class="s">No rating read</span></div>
                                <div class="gbp-bars">
                                    @foreach([5, 4, 3, 2, 1] as $star)
                                        <div class="gbp-bar"><span>{{ $star }}</span><span></span><span>&mdash;</span></div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="gbp-rv-empty">
                                <span class="gbp-tile is-neutral"><x-ds-icon name="star" :size="16" /></span>
                                <div>
                                    <b>Reviews are not shown here yet</b>
                                    <p>MotionGrove does not read your Google rating, review breakdown or recent reviews today, so none are displayed rather than guessed. Read and answer reviews in Google.</p>
                                </div>
                            </div>
                        </section>
                    </div>

                    {{-- ============ RIGHT COLUMN: improve, photos ============ --}}
                    <div class="gbp-col-side">
                        <section class="gbp-cd is-clip" data-role="gbp-improve">
                            <div class="gbp-ch" style="padding-bottom:4px">
                                <b>Improve your listing</b>
                                <span class="gbp-ch-note">{{ count($actions) === 0 ? 'Nothing to do' : count($actions) . ' to do' }}</span>
                            </div>
                            <p class="gbp-improve-sub">Based on what your listing shows today.</p>
                            @forelse($actions as $action)
                                <div class="gbp-op">
                                    <span class="gbp-tile {{ $action['tone'] === 'warn' ? 'is-warn' : 'is-info' }}"><x-ds-icon :name="$action['icon']" :size="16" /></span>
                                    <div>
                                        <b>{{ $action['title'] }}</b>
                                        <p>{{ $action['text'] }}</p>
                                    </div>
                                    @if(! empty($action['refresh']))
                                        @if(! empty($canManageGoogle))
                                            <form method="POST" action="{{ $refreshUrl }}">@csrf<button type="submit" class="gbp-op-link">{{ $action['cta'] }}</button></form>
                                        @endif
                                    @elseif($action['url'])
                                        <a class="gbp-op-link" href="{{ $action['url'] }}" @if(str_starts_with($action['url'], 'https://')) target="_blank" rel="noopener noreferrer" @endif>{{ $action['cta'] }}</a>
                                    @endif
                                </div>
                            @empty
                                <div class="gbp-ok-row">
                                    <span class="gbp-tile" style="background:var(--g-ok-soft);color:var(--g-ok)"><x-ds-icon name="check" :size="16" /></span>
                                    <span>No gaps found in the information we can read. Hours, photos and reviews are not checked yet.</span>
                                </div>
                            @endforelse

                            {{-- Comparison summary per linked location (the comparator's own rows, split for display). --}}
                            @foreach($bindings as $item)
                                @php
                                    $profile = $item['profile'];
                                    $diffCount = count($profile['differences']);
                                @endphp
                                @if($profile['fresh'] && $item['comparisonAvailable'])
                                    <div class="gbp-cmp-foot">
                                        <span>
                                            @if($diffCount > 0)
                                                <b>{{ $diffCount }} {{ $diffCount === 1 ? 'field differs' : 'fields differ' }} from Google</b>
                                            @else
                                                <b>Everything matches Google</b>
                                            @endif
                                            &middot; {{ $profile['matchCount'] }} {{ $profile['matchCount'] === 1 ? 'field matches' : 'fields match' }}. Nothing here changes your Google listing.
                                        </span>
                                        <a class="gbp-textlink" href="{{ $item['comparisonUrl'] }}">View full comparison</a>
                                    </div>
                                @elseif($item['comparisonAvailable'])
                                    <div class="gbp-cmp-foot">
                                        <span>{{ count($bindings) > 1 ? ($item['location']?->name ?? 'Linked location') . ': ' : '' }}Comparison needs a fresh Google read.</span>
                                        <a class="gbp-textlink" href="{{ $item['comparisonUrl'] }}">View comparison</a>
                                    </div>
                                @endif
                            @endforeach
                        </section>

                        <section class="gbp-cd" data-role="gbp-photos">
                            <div class="gbp-ch">
                                <b>Photos</b>
                                <span class="gbp-ch-note">Not available</span>
                            </div>
                            <div class="gbp-ph">
                                <div class="gbp-ph-top" aria-hidden="true">
                                    <div class="gbp-slot"><span class="lb">Cover</span></div>
                                    <div class="gbp-slot"><span class="lb">Logo</span></div>
                                </div>
                                <div class="gbp-ph-sq" aria-hidden="true">
                                    <div class="gbp-slot"></div><div class="gbp-slot"></div><div class="gbp-slot"></div><div class="gbp-slot"></div>
                                </div>
                                <span class="gbp-ph-note">
                                    <strong>Photos are not shown here yet.</strong> MotionGrove does not read your Google cover, logo or gallery today, so no images are displayed.
                                    <a class="gbp-textlink" href="https://business.google.com/" target="_blank" rel="noopener noreferrer">Manage photos on Google</a>
                                </span>
                            </div>
                        </section>
                    </div>
                </div>

                @if (! empty($canManageGoogle))
                    <div class="gbp-wrap"><a class="gbp-textlink" href="{{ $locationsUrl }}">Link another location</a></div>
                @endif
            @endif
        @endif
    </div>
@endsection
