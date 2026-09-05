@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Campaigns'))

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ __('locale.menu.Campaigns') }}</h4>
            <x-button variant="outline" size="sm" :href="route('customer.workspaces.businesses.outreach.index', [$workspaceUid, $businessUid])" icon="edit-3">
                {{ __('locale.menu.Outreach') }}
            </x-button>
        </div>
    </div>

    <x-card :padded="false">
        @if($campaigns->count() === 0)
            <x-empty-state icon="send" title="No campaigns yet"
                            description="Sent and scheduled campaigns will appear here." />
        @else
            <x-table :headers="[__('locale.labels.name'), 'Channel', __('locale.labels.status'), __('locale.labels.date')]">
                @foreach($campaigns as $campaign)
                    <tr>
                        <td>
                            <a href="{{ route('customer.workspaces.businesses.outreach.campaigns.show', [$workspaceUid, $businessUid, $campaign->uid]) }}">{{ $campaign->campaign_name }}</a>
                        </td>
                        <td>
                            <x-badge variant="neutral">{{ strtoupper($campaign->sms_type === 'unicode' ? 'plain' : $campaign->sms_type) }}</x-badge>
                        </td>
                        <td>
                            @php($statusVariant = match($campaign->status) {
                                \App\Models\Campaigns::STATUS_DELIVERED, \App\Models\Campaigns::STATUS_DONE => 'success',
                                \App\Models\Campaigns::STATUS_FAILED, \App\Models\Campaigns::STATUS_ERROR => 'danger',
                                \App\Models\Campaigns::STATUS_PAUSED => 'warning',
                                default => 'accent',
                            })
                            <x-badge :variant="$statusVariant">{{ ucfirst($campaign->status) }}</x-badge>
                        </td>
                        <td>{{ $campaign->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>

    @if($campaigns->count() > 0)
        <div class="mt-2">
            <x-pagination :paginator="$campaigns" />
        </div>
    @endif
@endsection
