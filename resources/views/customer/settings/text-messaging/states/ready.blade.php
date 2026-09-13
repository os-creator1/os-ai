@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('content')
    <div class="row mb-2">
        <div class="col-12">
            <h4 class="mb-0">Text messaging</h4>
            <p class="text-caption mb-0">How this Business sends and receives text messages.</p>
        </div>
    </div>

    <x-card :padded="true" class="mb-2">
        <div class="mb-2">
            <p class="text-section-heading mb-1">Status</p>
            <x-badge variant="success">Ready</x-badge>
        </div>

        <dl class="row mb-0">
            <dt class="col-sm-4">Phone number</dt>
            <dd class="col-sm-8" data-role="phone-number">{{ $phoneNumber }}</dd>

            <dt class="col-sm-4">Text messages</dt>
            <dd class="col-sm-8">{{ $textingAvailable ? 'Available' : 'Not available yet' }}</dd>

            <dt class="col-sm-4">Picture messages</dt>
            <dd class="col-sm-8">
                @if($mediaAvailable)
                    Available
                @else
                    <span class="text-caption">Not available yet.</span>
                @endif
            </dd>
        </dl>
    </x-card>

    <div class="row">
        <div class="col-md-6 mb-2">
            <x-card title="Delivery & usage" :padded="true">
                <p class="text-caption text-muted mb-2">How many texts have gone out, and how many reached carriers successfully.</p>
                <x-button variant="outline" size="sm"
                          :href="route('customer.workspaces.businesses.text-messaging.delivery-usage', [$workspaceUid, $businessUid])">
                    View delivery & usage
                </x-button>
            </x-card>
        </div>
        <div class="col-md-6 mb-2">
            <x-card title="Billing" :padded="true">
                <p class="text-caption text-muted mb-2">See your balance and add funds for texting.</p>
                <x-button variant="outline" size="sm"
                          :href="route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid])">
                    View usage & billing
                </x-button>
            </x-card>
        </div>
    </div>
@endsection
