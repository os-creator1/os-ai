{{--
    Customer Experience Slice 4 §12 — several Businesses, none chosen: the
    Slice 1B choice. Each option is the same CSRF-protected, server-
    re-authorized switch the header switcher posts to.
--}}
@php use App\Library\Dashboard\DashboardSnapshot; $chooser = $dashboard->band(DashboardSnapshot::BAND_CHOOSER); @endphp

<section class="mb-2" aria-labelledby="dashboard-chooser-heading" data-band="chooser">
    <x-card>
        <h2 class="h4 text-section-heading mb-50" id="dashboard-chooser-heading">Choose a {{ $chooser['noun'] }}</h2>
        <p class="text-caption text-muted mb-1">Pick one to see what needs attention, what happened in the last 30 days and what to do next.</p>
        <ul class="list-unstyled mb-0">
            @foreach($chooser['businesses'] as $business)
                <li class="d-flex flex-wrap justify-content-between align-items-center gap-1 py-1 @unless($loop->last) border-bottom @endunless" data-role="chooser-option">
                    <span>
                        <span class="fw-bolder">{{ $business['name'] }}</span>
                        @if($chooser['showWorkspace'])
                            <span class="d-block text-caption text-muted">{{ $business['workspaceName'] }}</span>
                        @endif
                    </span>
                    @if($chooser['switchUrl'])
                        <form method="POST" action="{{ $chooser['switchUrl'] }}">
                            @csrf
                            <input type="hidden" name="workspace" value="{{ $business['workspace'] }}">
                            <input type="hidden" name="business" value="{{ $business['business'] }}">
                            <x-button type="submit" variant="outline" size="sm">Open<span class="visually-hidden"> {{ $business['name'] }}</span></x-button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-card>
</section>

@if($dashboard->has(DashboardSnapshot::BAND_TEAM_ACCOUNT))
    @include('customer.dashboard.team-account', ['parent' => $dashboard->band(DashboardSnapshot::BAND_TEAM_ACCOUNT)])
@endif
