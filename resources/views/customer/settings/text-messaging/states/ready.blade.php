@extends('layouts/contentLayoutMaster')

@section('title', 'Text messaging')

@section('content')
    @include('customer.settings._module-header', [
        'backUrl' => route('customer.workspaces.businesses.settings.show', [$workspaceUid, $businessUid]),
        'title' => 'Text messaging',
        'description' => 'How this Business sends and receives text messages.',
    ])

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
        @if (! empty($locationChoices))
            <div class="col-md-6 mb-2">
                <x-card title="Locations that use this number" :padded="true">
                    <p class="text-caption text-muted mb-2">
                        Choose which locations text from this number. Automations limited to particular
                        locations only send texts for locations chosen here. Leave all unchecked to keep it
                        as your business-wide number.
                    </p>
                    <form method="POST" action="{{ route('customer.workspaces.businesses.text-messaging.number.locations.update', [$workspaceUid, $businessUid]) }}" data-role="number-locations-form">
                        @csrf
                        @method('PUT')
                        @foreach ($locationChoices as $choice)
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="location_uids[]" id="number-location-{{ $choice['uid'] }}" value="{{ $choice['uid'] }}" @checked($choice['checked'])>
                                <label class="form-check-label" for="number-location-{{ $choice['uid'] }}">{{ $choice['name'] }}</label>
                            </div>
                        @endforeach
                        <x-button type="submit" variant="outline" size="sm" class="mt-1">Save locations</x-button>
                    </form>
                </x-card>
            </div>
        @endif
        <div class="col-md-6 mb-2">
            @include('customer.settings.text-messaging._port-out-card')
        </div>
    </div>
@endsection
