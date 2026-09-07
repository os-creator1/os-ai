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

    @unless($mirrorIsFresh)
        <x-alert variant="warning" class="mb-2">
            Google data is not available or has passed its 30-day retention window. Refresh to compare.
        </x-alert>
    @endunless

    <x-card :padded="true">
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
                    @foreach($rows as $row)
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
@endsection
