@extends('layouts/contentLayoutMaster')

@section('title', 'Add a location')

@section('content')
    <section class="mb-2" aria-labelledby="add-location-heading">
        <h2 class="h4 text-section-heading mb-50" id="add-location-heading">Add a location to {{ $business->name }}</h2>
        <p class="text-body mb-0">
            A new storefront, branch or service area for this business.
            @if (! $capacity->unlimited && $capacity->effectiveCapacity !== null)
                Your plan has room for {{ (int) $capacity->remaining() }} more {{ (int) $capacity->remaining() === 1 ? 'location' : 'locations' }}.
            @endif
        </p>
    </section>

    <x-card class="mb-2">
        <form method="POST" action="{{ route('customer.workspaces.businesses.locations.store', [$workspace->uid, $business->uid]) }}">
            @csrf
            @include('customer.business.locations._form', [
                'location' => null,
                'modes' => \App\Http\Requests\Business\StoreBusinessLocationRequest::PHYSICAL_MODES,
                'nameRequired' => true,
            ])
            <div class="d-flex gap-1">
                <x-button type="submit" variant="primary" icon="plus">Add location</x-button>
                <x-button variant="ghost" :href="route('customer.workspaces.businesses.locations.index', [$workspace->uid, $business->uid])">Cancel</x-button>
            </div>
        </form>
    </x-card>
@endsection
