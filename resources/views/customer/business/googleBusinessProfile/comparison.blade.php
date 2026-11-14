{{--
    GBP Slice A contract §25.6 / §22 — the four-column comparison table.

    Exactly four columns (Field, Platform value, Google value, Status) and
    exactly five statuses. There is NO score, percentage, grade, severity
    ordering, chart, recommendation engine or AI interpretation, and no
    "Proposed change" column — Slice A writes nothing to Google.

    The rows are computed AT READ TIME by
    GoogleBusinessProfileComparator and are never persisted (contract
    §13.3). When the mirror is absent or expired, every Google cell reads
    "Not comparable" with a reason; a stale mirror is never shown as
    current.
--}}
@extends('layouts/contentLayoutMaster')

@section('title', 'Platform vs Google')

@section('page-style')
    @include('customer.business.googleBusinessProfile._overview-style')
@endsection

@section('content')
    @php
        $backUrl = route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]);
        $refreshUrl = route('customer.workspaces.businesses.gbp.refresh', [$workspaceUid, $businessUid]);
        $retentionDays = (int) ($mirrorRetentionDays ?? 0);
        $single = count($comparisons) === 1 ? $comparisons[0] : null;
    @endphp

    <div class="gbp-page gbp-main">
        <div class="gbp-title-block gbp-wrap-md">
            <a href="{{ $backUrl }}" class="gbp-back"><x-ds-icon name="arrow-left" :size="14" /> Google Business Profile</a>
            <h1 class="gbp-h1">Platform vs Google</h1>
            <p class="gbp-sub">
                @if($single){{ $single['location']?->name ?? 'Linked location' }} &middot; @endif
                Read-only. Nothing here changes Google or MotionGrove.
            </p>
        </div>

        <x-flash-alert class="gbp-wrap-md" />

        @if($ephemeral ?? false)
            <div class="gbp-notice is-info gbp-wrap-md">
                <p>
                    This is a live view of what Google returned just now. Google data is not
                    retained between requests on this deployment, so it is gone once you leave or reload
                    this page, and reopening it will ask you to refresh again.
                </p>
            </div>
        @endif

        {{-- MULTI-LOCATION CORRECTION — a refresh that could not reach Google for some bindings says so explicitly, and never lets
             the bindings it DID refresh imply that all of them succeeded. --}}
        @foreach($failures ?? [] as $failure)
            <x-alert variant="warning" class="gbp-wrap-md mb-0">
                {{ $failure['location'] ?? 'One linked location' }}: {{ $failure['message'] }}
            </x-alert>
        @endforeach

        {{-- One block per binding. This view renders a COLLECTION in every case — the binding-addressed comparison route passes
             exactly one, and a zero-retention refresh passes every binding it refreshed — so no code path can silently show only
             the first or the last. --}}
        @foreach($comparisons as $comparison)
            @php
                $rows = collect($comparison['rows']);
                $fresh = (bool) $comparison['mirrorIsFresh'];
                $count = fn (string $status) => $rows->filter(fn ($row) => $row->status->value === $status)->count();
                $matching = $count('match');
                $differing = $count('mismatch');
                $filled = $rows->filter(fn ($row) => $row->platformValue !== null)->count();
                $locName = $comparison['location']?->name ?? $comparison['binding']->provider_location_resource_name;
            @endphp

            @unless($fresh)
                {{-- A stale mirror is never shown as current: refresh, don't fabricate. --}}
                <section class="gbp-stale gbp-wrap-md" data-role="gbp-stale" style="padding: 20px 22px; border-radius: 16px">
                    <span class="gbp-tile is-warn" style="width:44px;height:44px;border-radius:12px"><x-ds-icon name="refresh-cw" :size="20" /></span>
                    <div class="t">
                        <b style="font-size:16px">Google data needs a refresh</b>
                        <span style="font-size:14px">
                            @if($retentionDays === 0)
                                Google data is not kept between visits on this deployment. Refresh to compare against Google live.
                            @else
                                Google data is not available or has passed its {{ $retentionDays }}-day retention window. Refresh to compare.
                            @endif
                        </span>
                    </div>
                    @if(! empty($canManageGoogle))
                        <form method="POST" action="{{ $refreshUrl }}">
                            @csrf
                            <button type="submit" class="gbp-btn is-primary" style="padding:0 20px"><x-ds-icon name="refresh-cw" :size="16" /> Refresh from Google</button>
                        </form>
                    @endif
                </section>
            @endunless

            <section class="gbp-cmp-card gbp-wrap-md">
                <div class="gbp-cmp-top">
                    <b>Business details{{ count($comparisons) > 1 ? ' · ' . $locName : '' }}</b>
                    @if($fresh)
                        <span class="gbp-cmp-count {{ $differing === 0 ? 'is-ok' : '' }}">{{ $matching }} matching &middot; {{ $differing }} differing &middot; {{ $rows->count() - $matching - $differing }} missing or unavailable</span>
                    @else
                        <span class="gbp-cmp-count">{{ $filled }} of {{ $rows->count() }} filled in MotionGrove</span>
                    @endif
                </div>

                <div role="table" aria-label="Platform compared with Google">
                    <div class="gbp-tr th {{ $fresh ? 'has-status' : '' }}" role="row">
                        <span role="columnheader">Field</span>
                        <span role="columnheader">MotionGrove</span>
                        <span role="columnheader">Google</span>
                        @if($fresh)<span role="columnheader">Status</span>@endif
                    </div>
                    @foreach($comparison['rows'] as $row)
                        @php $status = $row->status->value; @endphp
                        <div class="gbp-tr {{ $fresh ? 'has-status' : '' }} {{ $fresh && $status === 'mismatch' ? 'is-diff' : '' }}" role="row">
                            <span class="f" role="cell">{{ $row->field }}</span>
                            <span role="cell" class="v">
                                <span class="gbp-cl">MotionGrove</span>
                                @if($row->platformValue !== null)
                                    {{ $row->platformValue }}
                                @else
                                    <span class="miss">Not set</span>
                                @endif
                            </span>
                            <span role="cell" class="g">
                                <span class="gbp-cl">Google</span>
                                @if(! $fresh)
                                    <span class="gbp-sk" style="width: {{ 40 + (crc32($row->field) % 35) }}%" aria-label="Not available until refreshed"></span>
                                @elseif($row->googleValue !== null)
                                    <span class="v">{{ $row->googleValue }}</span>
                                @else
                                    <span class="miss">&mdash;</span>
                                @endif
                            </span>
                            @if($fresh)
                                <span role="cell">
                                    <span class="gbp-cl">Status</span>
                                    <x-badge :variant="$status === 'match' ? 'success' : ($status === 'mismatch' ? 'warning' : 'secondary')">
                                        {{ $row->status->label() }}
                                    </x-badge>
                                    @if($row->reason)
                                        <span class="why">{{ $row->reason }}</span>
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection
