@extends('layouts/contentLayoutMaster')

@section('title', $campaign->campaign_name)

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">{{ $campaign->campaign_name }}</h4>
            <x-button variant="outline" size="sm" :href="route('customer.outreach.campaigns')" icon="arrow-left">
                {{ __('locale.menu.Campaigns') }}
            </x-button>
        </div>
    </div>

    <x-card>
        <dl class="row mb-0">
            <dt class="col-sm-3">Channel</dt>
            <dd class="col-sm-9">{{ strtoupper($campaign->sms_type === 'unicode' ? 'plain' : $campaign->sms_type) }}</dd>

            <dt class="col-sm-3">{{ __('locale.labels.status') }}</dt>
            <dd class="col-sm-9"><x-badge variant="accent">{{ ucfirst($campaign->status) }}</x-badge></dd>

            <dt class="col-sm-3">{{ __('locale.labels.message') }}</dt>
            <dd class="col-sm-9">{{ $campaign->message }}</dd>

            <dt class="col-sm-3">{{ __('locale.labels.date') }}</dt>
            <dd class="col-sm-9">{{ $campaign->created_at?->format('Y-m-d H:i') }}</dd>
        </dl>

        <div class="d-flex gap-2 mt-3">
            @if($campaign->status === \App\Models\Campaigns::STATUS_SCHEDULED || $campaign->status === \App\Models\Campaigns::STATUS_PROCESSING)
                <form method="post" action="{{ route('customer.outreach.campaigns.pause', $campaign->uid) }}">
                    @csrf
                    <x-button type="submit" variant="outline" size="sm">{{ __('locale.buttons.pause') }}</x-button>
                </form>
            @endif

            @if($campaign->status === \App\Models\Campaigns::STATUS_PAUSED)
                <form method="post" action="{{ route('customer.outreach.campaigns.restart', $campaign->uid) }}">
                    @csrf
                    <x-button type="submit" variant="outline" size="sm">{{ __('locale.buttons.restart') }}</x-button>
                </form>
            @endif

            @if(in_array($campaign->status, [\App\Models\Campaigns::STATUS_FAILED, \App\Models\Campaigns::STATUS_ERROR, \App\Models\Campaigns::STATUS_DONE, \App\Models\Campaigns::STATUS_DELIVERED]))
                <form method="post" action="{{ route('customer.outreach.campaigns.resend', $campaign->uid) }}">
                    @csrf
                    <x-button type="submit" variant="outline" size="sm">{{ __('locale.buttons.resend') }}</x-button>
                </form>
            @endif

            <form method="post" action="{{ route('customer.outreach.campaigns.destroy', $campaign->uid) }}" onsubmit="return confirm('Delete this campaign?');">
                @csrf
                <x-button type="submit" variant="danger" size="sm">{{ __('locale.buttons.delete') }}</x-button>
            </form>
        </div>
    </x-card>
@endsection
