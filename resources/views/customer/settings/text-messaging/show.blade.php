@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Text messaging</h4>
            <p class="text-caption mb-0">How this Business sends and receives text messages.</p>
        </div>
    </div>

    <x-card :padded="true">
        <div class="mb-2">
            <p class="text-section-heading mb-1">Status</p>
            @switch($status)
                @case('ready')
                    <x-badge variant="success">Ready</x-badge>
                    @break
                @case('issue')
                    <x-badge variant="danger">Issue</x-badge>
                    @break
                @default
                    <x-badge variant="warning">Setup needed</x-badge>
            @endswitch
        </div>

        <dl class="row mb-0">
            <dt class="col-sm-4">Phone number</dt>
            <dd class="col-sm-8">
                @if($phoneNumber)
                    {{ $phoneNumber }}
                @else
                    <span class="text-caption">No number assigned yet.</span>
                @endif
            </dd>

            <dt class="col-sm-4">Text messages</dt>
            <dd class="col-sm-8">{{ $textingAvailable ? 'Available' : 'Not available yet' }}</dd>

            <dt class="col-sm-4">Picture messages</dt>
            <dd class="col-sm-8">
                @if($mediaAvailable)
                    Available
                @else
                    <span class="text-caption">Not available yet — this turns on automatically once texting is ready.</span>
                @endif
            </dd>
        </dl>

        @switch($status)
            @case('setup_needed')
                <p class="text-caption mb-0 mt-2">We're setting up a number for this Business. This usually only takes a few minutes — nothing for you to do.</p>
                @break
            @case('issue')
                <p class="text-caption mb-0 mt-2">Something needs attention with this Business's number. Contact support if this doesn't resolve on its own.</p>
                @break
        @endswitch
    </x-card>

    <x-card title="Usage &amp; billing" :padded="true" class="mt-2">
        <p class="text-caption mb-2">See how many texts this Business has sent and how that's billed.</p>
        <x-button variant="outline" size="sm"
                  :href="route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])">
            View usage &amp; billing
        </x-button>
    </x-card>
@endsection
