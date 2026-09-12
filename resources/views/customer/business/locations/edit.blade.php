@extends('layouts/contentLayoutMaster')

@section('title', 'Edit location')

@section('content')
    <section class="mb-2" aria-labelledby="edit-location-heading">
        <h2 class="h4 text-section-heading mb-50" id="edit-location-heading">Edit {{ $location->name }}</h2>
        <p class="text-body mb-0">Changing these details doesn't change how many locations your plan uses.</p>
    </section>

    <x-card class="mb-2">
        <form method="POST" action="{{ route('customer.workspaces.businesses.locations.update', [$workspace->uid, $business->uid, $location->uid]) }}">
            @csrf
            @include('customer.business.locations._form', [
                'location' => $location,
                'modes' => array_map(fn ($mode) => $mode->value, \App\Enums\Business\BusinessServiceMode::cases()),
                'nameRequired' => false,
            ])
            <div class="d-flex gap-1">
                <x-button type="submit" variant="primary">Save changes</x-button>
                <x-button variant="ghost" :href="route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid])">Cancel</x-button>
            </div>
        </form>
    </x-card>
@endsection
