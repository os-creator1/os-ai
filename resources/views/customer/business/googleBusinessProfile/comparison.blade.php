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

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap">
            <h4 class="mb-0">Platform vs Google</h4>
            <a href="{{ route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]) }}" class="text-caption">Back</a>
        </div>
    </div>

    @if($ephemeral ?? false)
        <x-alert variant="info" class="mb-2">
            This is a live view of what Google returned just now. Google data is not
            retained between requests on this deployment, so reopening this page will
            ask you to refresh again.
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

    {{-- One table per binding. This view renders a COLLECTION in every
         case — the binding-addressed comparison route passes exactly one,
         and a zero-retention refresh passes every binding it refreshed —
         so no code path can silently show only the first or the last. --}}
    @foreach($comparisons as $comparison)
        <x-card :padded="true" class="mb-2">
            <p class="text-section-heading mb-1">
                {{ $comparison['location']?->name ?? $comparison['binding']->provider_location_resource_name }}
            </p>

            @unless($comparison['mirrorIsFresh'])
                <x-alert variant="warning" class="mb-2">
                    Google data is not available or has passed its 30-day retention window. Refresh to compare.
                </x-alert>
            @endunless

            <p class="text-caption mb-2">
                This is a read-only comparison. Nothing here changes your Google listing, and nothing here changes the details stored on this platform.
            </p>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Field</th>
                            <th scope="col">Platform value</th>
                            <th scope="col">Google value</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comparison['rows'] as $row)
                            <tr>
                                <td>{{ $row->field }}</td>
                                <td>{{ $row->platformValue ?? '—' }}</td>
                                <td>{{ $row->googleValue ?? '—' }}</td>
                                <td>
                                    @php($status = $row->status->value)
                                    <x-badge :variant="$status === 'match' ? 'success' : ($status === 'mismatch' ? 'warning' : 'secondary')">
                                        {{ $row->status->label() }}
                                    </x-badge>
                                    @if($row->reason)
                                        <span class="text-caption d-block">{{ $row->reason }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endforeach
@endsection
