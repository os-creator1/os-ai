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
    @endphp

    <div class="gbp-narrow mb-2" style="max-width: 1120px">
        <a href="{{ $backUrl }}" class="gbp-link d-inline-flex align-items-center gap-50 mb-50"><x-ds-icon name="arrow-left" :size="14" /> Google Business Profile</a>
        <h1 class="h3 mb-25">Platform vs Google</h1>
        <p class="text-caption mb-0">A read-only comparison. Nothing here changes your Google listing or the details stored on this platform.</p>
    </div>

    <x-flash-alert class="mb-2" />

    @if($ephemeral ?? false)
        <x-alert variant="info" class="mb-2">
            This is a live view of what Google returned just now. Google data is not
            retained between requests on this deployment, so it is gone once you leave or reload
            this page, and reopening it will ask you to refresh again.
        </x-alert>
    @endif

    {{-- MULTI-LOCATION CORRECTION — a refresh that could not reach Google
         for some bindings says so explicitly, and never lets the bindings
         it DID refresh imply that all of them succeeded. --}}
    @foreach($failures ?? [] as $failure)
        <x-alert variant="warning" class="mb-2">
            {{ $failure['location'] ?? 'One linked location' }}: {{ $failure['message'] }}
        </x-alert>
    @endforeach

    {{-- One block per binding. This view renders a COLLECTION in every
         case — the binding-addressed comparison route passes exactly one,
         and a zero-retention refresh passes every binding it refreshed —
         so no code path can silently show only the first or the last. --}}
    @foreach($comparisons as $comparison)
        @php
            $rows = collect($comparison['rows']);
            $count = fn (string $status) => $rows->filter(fn ($row) => $row->status->value === $status)->count();
            $matching = $count('match');
            $differing = $count('mismatch');
            $unavailable = $comparison['mirrorIsFresh'] ? 0 : $rows->count();
        @endphp
        <x-card :padded="true" class="mb-2" style="max-width: 1120px">
            <div class="gbp-section-head">
                <div>
                    <p class="gbp-eyebrow mb-25">Linked location</p>
                    <h2 class="h5">{{ $comparison['location']?->name ?? $comparison['binding']->provider_location_resource_name }}</h2>
                </div>
            </div>

            @unless($comparison['mirrorIsFresh'])
                {{-- A stale mirror is never shown as current: refresh, don't fabricate. --}}
                <div class="gbp-stale mb-3" data-role="gbp-stale">
                    <x-alert variant="warning" class="mb-0 flex-grow-1">
                        @if((int) ($mirrorRetentionDays ?? 0) === 0)
                            Google data is not kept between visits on this deployment. Refresh to compare against Google live.
                        @else
                            Google data is not available or has passed its {{ (int) $mirrorRetentionDays }}-day retention window. Refresh to compare.
                        @endif
                    </x-alert>
                    @if(! empty($canManageGoogle))
                        <form method="POST" action="{{ $refreshUrl }}">
                            @csrf
                            <button type="submit" class="btn btn-primary"><x-ds-icon name="refresh-cw" :size="14" /> Refresh from Google</button>
                        </form>
                    @endif
                </div>
            @else
                <div class="gbp-cmp-summary">
                    <x-badge variant="success">{{ $matching }} matching</x-badge>
                    <x-badge variant="warning">{{ $differing }} differing</x-badge>
                    <x-badge variant="secondary">{{ $rows->count() - $matching - $differing }} missing or unavailable</x-badge>
                </div>
            @endunless

            <div role="table" aria-label="Platform compared with Google">
                <div class="gbp-cmp-row is-head" role="row">
                    <div role="columnheader">Field</div>
                    <div role="columnheader">Platform value</div>
                    <div role="columnheader">Google value</div>
                    <div role="columnheader">Status</div>
                </div>
                @foreach($comparison['rows'] as $row)
                    @php($status = $row->status->value)
                    <div class="gbp-cmp-row {{ $status === 'mismatch' ? 'is-mismatch' : '' }}" role="row">
                        <div role="cell"><strong>{{ $row->field }}</strong></div>
                        <div role="cell"><span class="gbp-cmp-label">Platform value</span>{{ $row->platformValue ?? '—' }}</div>
                        <div role="cell"><span class="gbp-cmp-label">Google value</span>{{ $row->googleValue ?? '—' }}</div>
                        <div role="cell">
                            <span class="gbp-cmp-label">Status</span>
                            <x-badge :variant="$status === 'match' ? 'success' : ($status === 'mismatch' ? 'warning' : 'secondary')">
                                {{ $row->status->label() }}
                            </x-badge>
                            @if($row->reason)
                                <span class="text-caption d-block">{{ $row->reason }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endforeach
@endsection
