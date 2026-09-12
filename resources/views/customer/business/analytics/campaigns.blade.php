@extends('layouts/contentLayoutMaster')

@section('title', 'Campaign performance')

@php
    $pct = static fn (?float $value): string => $value === null ? '—' : number_format($value, 1) . '%';
    $n = static fn (int $value): string => number_format($value);
    // createdAtLocal is already a Business-local "Y-m-d H:i"; this only
    // reformats it for reading, with UTC as a neutral, DST-free calendar.
    $created = static fn (string $local): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m-d H:i', $local, 'UTC')->format('M j, Y · g:i A');
    $campaignsUrl = route('customer.workspaces.businesses.analytics.campaigns', [$workspaceUid, $businessUid]);
    $overviewUrl = route('customer.workspaces.businesses.analytics.overview', array_merge([$workspaceUid, $businessUid], $range->queryParameters()));
@endphp

@section('content')
    <section id="business-analytics-campaigns">
        <div class="row">
            <div class="col-12">
                <a href="{{ $overviewUrl }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
                    <x-ds-icon name="arrow-left" size="16" />
                    Back to Results
                </a>
            </div>

            <div class="col-12">
                @if ($errors->any())
                    <x-alert variant="danger" icon="alert-circle" class="mb-2">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif
            </div>

            <div class="col-12">
                <x-card :title="$business->name . ' — Campaign performance'">
                    <p class="text-caption mb-2">Campaigns created in the selected period, most recent first.</p>
                    @include('customer.business.analytics._range', ['range' => $range, 'formAction' => $campaignsUrl, 'businessName' => $business->name])
                </x-card>
            </div>

            <div class="col-12">
                <x-card :padded="false" data-role="campaign-performance">
                    @if (count($rows) === 0)
                        <x-empty-state icon="send" title="No campaigns in this range"
                                        description="Campaigns created in the selected period will be listed here, with how many of their messages were sent and how many failed." />
                    @else
                        <x-table :headers="['Campaign', 'Created', 'Status', 'Messages', 'Sent', 'Failed', 'Contacts targeted']">
                            @foreach ($rows as $row)
                                <tr data-role="campaign-row" data-uid="{{ $row->uid }}">
                                    <td>{{ $row->name !== '' ? $row->name : 'Untitled campaign' }}</td>
                                    <td class="text-numeric">{{ $created($row->createdAtLocal) }}</td>
                                    <td>
                                        @if ($row->status !== null)
                                            <x-badge variant="neutral">{{ ucfirst($row->status) }}</x-badge>
                                        @else
                                            <span class="text-caption">—</span>
                                        @endif
                                    </td>
                                    <td class="text-numeric">{{ $n($row->attempted) }}</td>
                                    <td class="text-numeric">{{ $n($row->accepted) }} <span class="text-caption">({{ $pct($row->acceptedRate()) }})</span></td>
                                    <td class="text-numeric">{{ $n($row->confirmedFailed) }} <span class="text-caption">({{ $pct($row->confirmedFailedRate()) }})</span></td>
                                    <td class="text-numeric">{{ $n($row->contactsTargeted) }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                        <div class="p-2">
                            <x-pagination :paginator="$paginator" />
                        </div>
                    @endif
                </x-card>
                <p class="text-caption">
                    "Messages" counts every message record for the campaign. "Sent" means the messaging provider accepted the message; it doesn't confirm the message reached the phone.
                    "Failed" covers messages the provider reported as undelivered, expired, rejected, failed or skipped.
                    "Contacts targeted" counts distinct contacts with a tracking record for the campaign.
                </p>
            </div>
        </div>
    </section>
@endsection
