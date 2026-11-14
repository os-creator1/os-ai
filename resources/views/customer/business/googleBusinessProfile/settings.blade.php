{{--
    Google connection settings. Structure and spacing are based on the owner-supplied
    GbpSettings.dc.html design. Only actual Business-scoped connection, binding and
    audit-ledger data are shown. No Google write operations originate here.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Google connection')

@section('page-style')
    @include('customer.business.googleBusinessProfile._overview-style')
    <style>
        /* Scoped to settings: do not change the overview, picker or comparison. */
        .gbp-settings-page { width: 100%; max-width: 880px; margin-inline: auto; padding-bottom: 36px; display: flex; flex-direction: column; gap: 18px; box-sizing: border-box; }
        .gbps-heading { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
        .gbps-heading .gbp-h1 { margin: 0; font-size: 26px; letter-spacing: -.01em; }
        .gbps-heading .gbp-sub { margin: 0; }
        .gbps-card { width: 100%; min-width: 0; box-sizing: border-box; background: var(--g-surface); border: 1px solid var(--g-border); border-radius: 16px; }
        .gbps-card.is-clipped { overflow: hidden; }
        .gbps-connection { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; padding: 20px 22px; }
        .gbps-state-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex: none; background: var(--g-ok-soft); color: var(--g-ok); }
        .gbps-state-icon.is-off { background: var(--g-tile); color: var(--g-muted); }
        .gbps-connection-copy { flex: 1 1 300px; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
        .gbps-connection-copy > strong { font-size: 17px; font-weight: 700; }
        .gbps-desc { font-size: 13.5px; line-height: 1.45; color: var(--g-text-2); }
        .gbps-readonly { display: inline-flex; align-items: center; gap: 6px; margin-top: 2px; font-size: 13px; color: var(--g-text-2); }
        .gbps-readonly .ds-icon { color: var(--g-ok); }
        .gbps-location-head { padding: 18px 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 14px; }
        .gbps-location-initial { width: 44px; height: 44px; flex: none; display: flex; align-items: center; justify-content: center; border-radius: 12px; background: var(--g-brand); color: #fff; font-size: 16px; font-weight: 700; }
        .gbps-location-name { min-width: 0; flex: 1 1 270px; display: flex; flex-direction: column; gap: 3px; }
        .gbps-location-title { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-weight: 700; font-size: 16px; line-height: 1.4; }
        .gbps-status-tag { display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700; line-height: 1.4; background: var(--g-ok-soft); color: var(--g-ok); }
        .gbps-status-tag.is-warn { background: var(--g-warn-soft); color: var(--g-warn); }
        .gbps-data-row { display: flex; align-items: center; gap: 12px; min-height: 46px; box-sizing: border-box; padding: 10px 20px; border-top: 1px solid var(--g-border-soft); font-size: 14px; }
        .gbps-data-row > span:first-child { width: 150px; flex: none; color: var(--g-muted); }
        .gbps-data-row > span:last-child { flex: 1 1 auto; min-width: 0; font-weight: 500; overflow-wrap: anywhere; }
        .gbps-row-note { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 8px; color: var(--g-warn); }
        .gbps-location-foot { background: var(--g-soft); border-top: 1px solid var(--g-border-soft); padding: 10px 20px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .gbps-details { min-width: 0; flex: 1 1 230px; }
        .gbps-details > summary, .gbps-disconnect > summary { display: inline-flex; align-items: center; gap: 6px; min-height: 44px; color: var(--g-faint); font-size: 13.5px; font-weight: 500; cursor: pointer; list-style: none; }
        .gbps-details > summary::-webkit-details-marker, .gbps-disconnect > summary::-webkit-details-marker { display: none; }
        .gbps-details[open] > summary .ds-icon, .gbps-disconnect[open] > summary .ds-icon { transform: rotate(180deg); }
        .gbps-technical { padding: 4px 0 8px; color: var(--g-text-2); font-size: 12.5px; line-height: 1.65; overflow-wrap: anywhere; font-family: ui-monospace, Menlo, Consolas, monospace; }
        .gbps-change { display: inline-flex; align-items: center; gap: 6px; min-height: 44px; color: var(--g-ink); font-weight: 600; font-size: 13.5px; cursor: pointer; list-style: none; }
        .gbps-change::-webkit-details-marker { display: none; }
        .gbps-change-flow[open] { flex: 1 1 100%; }
        .gbps-change-flow[open] > summary .ds-icon { transform: rotate(180deg); }
        .gbps-change-confirm { padding: 12px 0 6px; display: flex; flex-wrap: wrap; align-items: center; gap: 14px; border-top: 1px solid var(--g-border-soft); }
        .gbps-change-confirm p { flex: 1 1 300px; margin: 0; }
        .gbps-card-heading { padding: 16px 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .gbps-card-heading > strong { flex: 1 1 auto; font-size: 16px; font-weight: 700; }
        .gbps-good { display: inline-flex; align-items: center; gap: 6px; color: var(--g-muted); font-size: 12.5px; }
        .gbps-good .dot { width: 7px; height: 7px; background: var(--g-ok); border-radius: 50%; }
        .gbps-day { padding: 12px 20px 6px; background: var(--g-soft); border-top: 1px solid var(--g-border-soft); color: var(--g-muted); font-size: 11.5px; text-transform: uppercase; font-weight: 700; letter-spacing: .07em; }
        .gbps-event { display: flex; align-items: center; gap: 12px; min-height: 50px; padding: 9px 20px; border-top: 1px solid var(--g-border-soft); box-sizing: border-box; font-size: 14px; }
        .gbps-event-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 30px; height: 30px; border-radius: 50%; color: var(--g-ok); background: var(--g-ok-soft); }
        .gbps-event-icon.is-warn { color: var(--g-warn); background: var(--g-warn-soft); }
        .gbps-event-icon.is-neutral { color: var(--g-muted); background: var(--g-tile); }
        .gbps-event-main { display: flex; flex: 1 1 auto; min-width: 0; flex-direction: column; gap: 1px; }
        .gbps-event-main > strong { font-weight: 600; line-height: 1.4; color: var(--g-text); }
        .gbps-event-main > span { color: var(--g-muted); font-size: 13px; line-height: 1.35; overflow-wrap: anywhere; }
        .gbps-event-time { margin-left: auto; color: var(--g-faint); font-size: 12.5px; flex: none; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .gbps-more-events > summary { display: flex; justify-content: center; align-items: center; min-height: 46px; padding: 8px 12px; border-top: 1px solid var(--g-border-soft); color: var(--g-ink); font-size: 13.5px; font-weight: 600; cursor: pointer; list-style: none; }
        .gbps-more-events > summary::-webkit-details-marker { display: none; }
        .gbps-more-events[open] > summary { border-bottom: 1px solid var(--g-border-soft); }
        .gbps-disconnect > summary { color: var(--g-faint); }
        .gbps-disconnect-panel { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; margin-top: 4px; padding: 16px 20px; border: 1px solid var(--g-border); border-radius: 14px; background: var(--g-surface); }
        .gbps-disconnect-copy { display: flex; flex: 1 1 300px; flex-direction: column; gap: 2px; }
        .gbps-disconnect-copy > strong { font-size: 14.5px; }
        .gbps-disconnect-copy > span { color: var(--g-text-2); font-size: 13px; line-height: 1.45; }
        .gbps-disconnect-btn { border-color: var(--g-brand); color: var(--g-ink); }
        .gbps-disconnect-btn:hover { border-color: var(--g-brand); background: var(--g-brand-soft); color: var(--g-ink); }
        .gbp-settings-page .gbp-btn { min-height: 44px; }
        .gbp-settings-page .gbps-location-head .gbp-btn.is-primary { min-height: 42px; }
        @media (max-width: 575.98px) {
            .gbps-connection, .gbps-location-head { padding: 16px; }
            .gbps-data-row { padding: 10px 16px; align-items: flex-start; flex-direction: column; gap: 4px; }
            .gbps-data-row > span:first-child { width: auto; }
            .gbps-location-foot, .gbps-card-heading, .gbps-event { padding-inline: 16px; }
            .gbps-day { padding-inline: 16px; }
            .gbps-event-time { font-size: 11.5px; }
            .gbps-connection .gbp-btn, .gbps-location-head .gbp-btn { max-width: 100%; }
        }
    </style>
@endsection

@section('content')
    @php
        $isActive = $connection && $connection->state->value === 'active';
        $indexUrl = route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]);
        $locationsUrl = route('customer.workspaces.businesses.gbp.locations', [$workspaceUid, $businessUid]);
        $refreshUrl = route('customer.workspaces.businesses.gbp.refresh', [$workspaceUid, $businessUid]);
        $disconnectUrl = route('customer.workspaces.businesses.gbp.disconnect', [$workspaceUid, $businessUid]);
        $connectUrl = route('customer.workspaces.businesses.gbp.connect', [$workspaceUid, $businessUid]);
        $visibleOperations = $operations->take(7);
        $moreOperations = $operations->skip(7);
        $hasRecentProblems = $visibleOperations->contains(fn ($op) => in_array($op->status->value, ['failed', 'unknown', 'deferred'], true));
        $hasRecentPending = $visibleOperations->contains(fn ($op) => $op->status->value === 'pending');
    @endphp

    <div class="gbp-page gbp-settings-page" data-role="gbp-settings">
        <header class="gbps-heading">
            <a href="{{ $indexUrl }}" class="gbp-back"><x-ds-icon name="arrow-left" :size="14" /> Google Business Profile</a>
            <h1 class="gbp-h1">Google connection</h1>
            <p class="gbp-sub">Your linked listing, access and recent activity.</p>
        </header>
        <x-flash-alert />

        <section class="gbps-card gbps-connection" data-role="gbp-settings-connection">
            <span class="gbps-state-icon {{ $isActive ? '' : 'is-off' }}" aria-hidden="true">
                <x-ds-icon :name="$isActive ? 'check' : 'link-2-off'" :size="22" />
            </span>
            <div class="gbps-connection-copy">
                <strong>{{ $isActive ? 'Connected to Google' : 'Not connected to Google' }}</strong>
                <span class="gbps-desc">
                    @if($isActive)
                        Since {{ $connection->connected_at?->format('M j, g:i A') ?? 'date unknown' }}
                        &middot; Last refreshed {{ $connection->last_refreshed_at?->format('g:i A') ?? 'not yet' }}
                    @else
                        Connect your Google account to view and compare your Business Profile listing.
                    @endif
                </span>
                <span class="gbps-readonly"><x-ds-icon name="lock-keyhole" :size="14" /> Read-only access to your Business Profile</span>
            </div>
            @if($isActive && ! empty($canManageGoogle) && count($bindings) > 0)
                <form method="POST" action="{{ $refreshUrl }}">
                    @csrf
                    <button type="submit" class="gbp-btn is-outline"><x-ds-icon name="refresh-cw" :size="15" /> Refresh now</button>
                </form>
            @elseif(! $isActive && ! empty($canManageGoogle))
                <form method="POST" action="{{ $connectUrl }}">
                    @csrf
                    <button type="submit" class="gbp-btn is-primary">Connect Google</button>
                </form>
            @endif
        </section>

        @forelse($bindings as $item)
            @php
                $binding = $item['binding'];
                $googleTitle = $binding->bound_title_snapshot ?: ($item['location']?->name ?? 'Google listing');
                $verification = $binding->verification_state?->label() ?? 'Status unknown';
                $verified = $binding->verification_state === \App\Enums\GoogleBusinessProfile\GoogleLocationHealth::Verified;
            @endphp
            <section class="gbps-card is-clipped" data-role="gbp-settings-location">
                <div class="gbps-location-head">
                    <span class="gbps-location-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr($googleTitle, 0, 1)) }}</span>
                    <div class="gbps-location-name">
                        <div class="gbps-location-title">
                            {{ $googleTitle }}
                            <span class="gbps-status-tag {{ $verified ? '' : 'is-warn' }}">{{ $verification }}</span>
                        </div>
                        <span class="gbps-desc">Linked to {{ $item['location']?->name ?? 'a location no longer in the platform' }}{{ $item['location']?->is_primary ? ' · Primary location' : '' }}</span>
                    </div>
                    @if($item['comparisonAvailable'])
                        <a href="{{ $item['comparisonUrl'] }}" class="gbp-btn is-primary">View comparison</a>
                    @endif
                </div>
                <div class="gbps-data-row">
                    <span>Google data</span>
                    <span>
                        @if($item['mirrorIsFresh'] && $binding->mirror_fetched_at)
                            Refreshed {{ $binding->mirror_fetched_at->format('M j, g:i A') }}
                        @else
                            <span class="gbps-row-note"><span class="gbps-status-tag is-warn">Not saved yet</span> Refresh to load your listing details.</span>
                        @endif
                    </span>
                </div>
                <div class="gbps-data-row">
                    <span>Google account</span>
                    <span>{{ $connection?->google_account_email ?? 'Not shown by Google' }}</span>
                </div>
                <div class="gbps-location-foot">
                    <details class="gbps-details">
                        <summary>Technical details <x-ds-icon name="chevron-down" :size="14" /></summary>
                        <div class="gbps-technical">
                            <div>location: {{ $item['providerLocationResourceName'] }}</div>
                            <div>account: {{ $item['providerAccountResourceName'] }}</div>
                            <div>granted access: {{ $connection?->granted_scopes ?? 'Not recorded' }}</div>
                        </div>
                    </details>
                    @if(! empty($canManageGoogle))
                        {{-- A bound platform location cannot be replaced directly in the picker.
                             Expose the existing authorized unlink POST first; the new listing
                             can be chosen after that binding is removed. --}}
                        <details class="gbps-change-flow" data-role="gbp-change-listing">
                            <summary class="gbps-change">Change listing <x-ds-icon name="chevron-down" :size="14" /></summary>
                            <div class="gbps-change-confirm">
                                <p class="gbps-desc">To change this listing, unlink it first. Only this location's Google link is removed; your Google account stays connected. You can then choose a new listing.</p>
                                <form method="POST" action="{{ route('customer.workspaces.businesses.gbp.unbind', [$workspaceUid, $businessUid]) }}">
                                    @csrf
                                    <input type="hidden" name="binding_uid" value="{{ $binding->uid }}">
                                    <button type="submit" class="gbp-btn is-outline gbps-disconnect-btn">Unlink to choose another listing</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            </section>
        @empty
            @if($isActive)
                <section class="gbps-card gbps-connection">
                    <div class="gbps-connection-copy"><strong>No Google listing linked yet</strong><span class="gbps-desc">Choose which Google location belongs to this business.</span></div>
                    <a href="{{ $locationsUrl }}" class="gbp-btn is-primary">Choose listing</a>
                </section>
            @endif
        @endforelse

        <section class="gbps-card is-clipped" data-role="gbp-settings-activity">
            <div class="gbps-card-heading">
                <strong>Recent activity</strong>
                @if($operations->isNotEmpty())
                    <span class="gbps-good"><span class="dot" style="{{ $hasRecentProblems || $hasRecentPending ? 'background:var(--g-warn)' : '' }}"></span>{{ $hasRecentProblems ? 'Needs review' : ($hasRecentPending ? 'In progress' : 'No recent errors') }}</span>
                @endif
            </div>
            @if($operations->isEmpty())
                <p class="gbps-desc" style="padding: 4px 20px 20px;">No Google activity recorded yet.</p>
            @else
                @php($lastDay = null)
                @foreach($visibleOperations as $operation)
                    @php($day = $operation->created_at?->isToday() ? 'Today' : ($operation->created_at?->format('M j, Y') ?? 'Date unknown'))
                    @if($day !== $lastDay)
                        <div class="gbps-day">{{ $day }}</div>
                        @php($lastDay = $day)
                    @endif
                    @include('customer.business.googleBusinessProfile._settings_event', ['operation' => $operation])
                @endforeach
                @if($moreOperations->isNotEmpty())
                    <details class="gbps-more-events" data-role="gbp-settings-more-activity">
                        <summary>Show all {{ $operations->count() }} events</summary>
                        @php($lastOlderDay = null)
                        @foreach($moreOperations as $operation)
                            @php($day = $operation->created_at?->isToday() ? 'Today' : ($operation->created_at?->format('M j, Y') ?? 'Date unknown'))
                            @if($day !== $lastOlderDay)
                                <div class="gbps-day">{{ $day }}</div>
                                @php($lastOlderDay = $day)
                            @endif
                            @include('customer.business.googleBusinessProfile._settings_event', ['operation' => $operation])
                        @endforeach
                    </details>
                @endif
            @endif
        </section>

        @if($connection && $connection->state->value !== 'disconnected')
            <details class="gbps-disconnect" data-role="gbp-settings-disconnect">
                <summary>Disconnect Google <x-ds-icon name="chevron-down" :size="14" /></summary>
                <div class="gbps-disconnect-panel">
                    <div class="gbps-disconnect-copy">
                        <strong>Stop syncing with Google</strong>
                        <span>Your Google listing stays unchanged. Disconnecting deletes stored authorization and unlinks all locations; reconnecting requires Google's permission screen again.</span>
                    </div>
                    @if(! empty($canManageGoogle))
                        <form method="POST" action="{{ $disconnectUrl }}">
                            @csrf
                            <button type="submit" class="gbp-btn is-outline gbps-disconnect-btn">Disconnect</button>
                        </form>
                    @else
                        <span class="gbps-desc">You do not have permission to manage this connection.</span>
                    @endif
                </div>
            </details>
        @endif
    </div>
@endsection
