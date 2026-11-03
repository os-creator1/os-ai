{{--
    Final review of a guided setup: the facts the website is built from, in plain numbers, and the real photos that
    are still missing (generating a draft never needs them; publishing does) with a direct link to add each one.
--}}
@php
    $c = $guidedReview['counts'];
    $stepUrl = fn (string $key) => route('customer.workspaces.businesses.website.setup.step', [$workspaceUid, $businessUid, $key]);
    $design = $guidedReview['template'] ? \App\Library\Website\Design\WebsiteDesigns::forTemplateKey($guidedReview['template']) : null;
    $pricingLabel = ['fixed_packages' => 'Fixed package pricing', 'starting_from' => 'Prices starting from an amount', 'custom_quote' => 'Custom quote'][$c['pricing'] ?? ''] ?? '—';
@endphp
<div class="card mb-3" data-testid="guided-review">
    <div class="card-body">
        <h6 class="mb-3">Your website at a glance</h6>
        <dl class="row mb-0">
            <dt class="col-sm-4">Business</dt>
            <dd class="col-sm-8">{{ $guidedReview['business']['name'] }}@if ($guidedReview['business']['base']) <span class="text-caption"> &middot; {{ $guidedReview['business']['base'] }}</span>@endif</dd>
            <dt class="col-sm-4">Contact</dt>
            <dd class="col-sm-8">{{ collect([$guidedReview['business']['phone'], $guidedReview['business']['email']])->filter()->implode(' · ') ?: '—' }}</dd>
            <dt class="col-sm-4">Booths</dt>
            <dd class="col-sm-8" data-count="booths">{{ $c['booths'] }}</dd>
            <dt class="col-sm-4">Event types</dt>
            <dd class="col-sm-8" data-count="events">{{ $c['events'] }}</dd>
            <dt class="col-sm-4">Pricing</dt>
            <dd class="col-sm-8">{{ $pricingLabel }}</dd>
            <dt class="col-sm-4">Packages</dt>
            <dd class="col-sm-8" data-count="packages">{{ $c['packages'] }}</dd>
            <dt class="col-sm-4">Backdrops</dt>
            <dd class="col-sm-8" data-count="backdrops">{{ $c['backdrops'] }}</dd>
            <dt class="col-sm-4">Service areas</dt>
            <dd class="col-sm-8">{{ $guidedReview['business']['areas'] }} <a class="ms-2" href="{{ $stepUrl('service_area_cities') }}" data-testid="edit-service-areas">Edit</a></dd>
            <dt class="col-sm-4">Uploaded photos</dt>
            <dd class="col-sm-8" data-count="photos">{{ $c['photos'] }}</dd>
            <dt class="col-sm-4">Look</dt>
            <dd class="col-sm-8">{{ $design?->label ?? '—' }}</dd>
            <dt class="col-sm-4">Booking button</dt>
            <dd class="col-sm-8">{{ $guidedReview['booking'] ?? '—' }}</dd>
        </dl>
    </div>
</div>

<div class="card mb-3" data-testid="photo-requirements">
    <div class="card-body">
        @if ($guidedReview['missing'] === [])
            <h6 class="mb-1">Your photos are ready to publish</h6>
            <p class="text-caption mb-0">Your homepage, every booth and every backdrop have a real photo.</p>
        @else
            <h6 class="mb-1">Photos still needed to publish</h6>
            <p class="text-caption">You can generate your website now. Before you publish it, every item below needs a real photo.</p>
            <ul class="mb-0 ps-3">
                @foreach ($guidedReview['missing'] as $item)
                    <li class="mb-1">
                        <strong>{{ $item['title'] }}</strong> &mdash; {{ $item['detail'] }}
                        <a href="{{ $stepUrl($item['step']) }}" class="ms-1">Add it</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
