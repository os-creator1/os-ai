@php
    $business = $review['business'];
    $location = $review['location'];
    $primary = $review['primaryService'];
    $others = $review['services']->reject(fn ($service) => $primary !== null && $service->id === $primary->id);
    $missing = $review['missing'];
    $completed = $onboarding->status === \App\Enums\Business\OnboardingStatus::Completed;
    $website = $review['website'];
    $websiteLabels = ['build' => 'Build a new website with MotionGrove', 'existing' => 'I already have a website', 'later' => 'Set up later'];
@endphp

<h5>{{ $completed ? 'Your setup is complete' : 'Review and finish' }}</h5>
<p class="text-muted">This is exactly what MotionGrove has saved. Nothing here is generated or estimated.</p>

<dl class="row mb-2" data-role="review">
    <dt class="col-sm-3">Business</dt>
    <dd class="col-sm-9">
        @if (isset($missing['business']))
            <span class="text-danger">{{ $missing['business'] }}</span>
            <a href="{{ route('customer.onboarding.show', ['step' => 'business']) }}">Add it</a>
        @else
            {{ $business->name }} <span class="text-muted">· {{ $business->industry->value === 'other' && filled($business->industry_other) ? $business->industry_other : $business->industry->label() }}</span>
            <a class="ms-1" href="{{ route('customer.onboarding.show', ['step' => 'business']) }}">Edit</a>
        @endif
    </dd>

    <dt class="col-sm-3">Where you work</dt>
    <dd class="col-sm-9">
        @if (isset($missing['location']))
            <span class="text-danger">{{ $missing['location'] }}</span>
            <a href="{{ route('customer.onboarding.show', ['step' => 'location']) }}">Fix it</a>
        @else
            @php($place = collect([$location->city, $location->region, $location->country_code])->filter()->implode(', '))
            {{ $place }}
            <span class="text-muted">· {{ ['service_area' => 'Travels to customers', 'storefront' => 'Customers visit', 'hybrid' => 'Travels and customers visit', 'online' => 'Online only'][$location->service_mode->value] ?? '' }}</span>
            @if (! empty($location->service_area_cities))
                <div class="text-muted">Also serving: {{ implode(', ', $location->service_area_cities) }}</div>
            @endif
            <a class="ms-1" href="{{ route('customer.onboarding.show', ['step' => 'location']) }}">Edit</a>
        @endif
    </dd>

    <dt class="col-sm-3">Services</dt>
    <dd class="col-sm-9">
        @if (isset($missing['services']))
            <span class="text-danger">{{ $missing['services'] }}</span>
            <a href="{{ route('customer.onboarding.show', ['step' => 'services']) }}">Fix it</a>
        @else
            <strong>{{ $primary->name }}</strong> <span class="text-muted">(primary)</span>@if ($others->isNotEmpty()), {{ $others->pluck('name')->implode(', ') }}@endif
            <a class="ms-1" href="{{ route('customer.onboarding.show', ['step' => 'services']) }}">Edit</a>
        @endif
    </dd>

    <dt class="col-sm-3">Website</dt>
    <dd class="col-sm-9">
        @if (isset($missing['website']))
            <span class="text-danger">{{ $missing['website'] }}</span>
            <a href="{{ route('customer.onboarding.show', ['step' => 'website']) }}">Answer it</a>
        @else
            {{ $websiteLabels[$website['choice']] ?? '' }}
            @if ($website['choice'] === 'existing' && filled($website['url']))
                <span class="text-muted">· {{ $website['url'] }}</span>
            @endif
            <div class="text-muted">{{ $website['status'] }}</div>
            <a href="{{ route('customer.onboarding.show', ['step' => 'website']) }}">Edit</a>
        @endif
    </dd>
</dl>

@if ($completed)
    <a class="btn btn-primary" href="{{ route('user.home') }}">Enter MotionGrove</a>
@elseif ($missing === [])
    <form method="POST" action="{{ route('customer.onboarding.complete') }}" data-role="complete-form">
        @csrf
        <x-button type="submit" variant="primary">Enter MotionGrove</x-button>
    </form>
    <script>
        // A double-click must not submit twice.
        document.querySelector('[data-role="complete-form"]').addEventListener('submit', function (e) {
            var button = e.target.querySelector('button[type="submit"]');
            if (button.disabled) { e.preventDefault(); return; }
            setTimeout(function () { button.disabled = true; }, 0);
        });
    </script>
@else
    <p class="text-danger mb-0">Finish the items marked above, then you can enter MotionGrove.</p>
@endif
