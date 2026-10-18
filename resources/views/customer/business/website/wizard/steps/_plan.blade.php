{{--
    Website V1 final — "Your website plan": which pages Generate will build
    from the owner's answers and WHY each other candidate is not built, from
    the same deterministic planner Generate runs. Nothing here is invented.
--}}
@if ($plan)
    <div class="card mb-3" data-testid="website-plan">
        <div class="card-body">
            <h6 class="mb-1">Your website plan</h6>
            <p class="text-caption mb-3" data-testid="plan-count">{{ $plan['budget']['planned'] }} pages will be built (a website is built with at most {{ $plan['budget']['max'] }}).</p>

            <div class="row">
                <div class="col-md-6">
                    <div class="text-label mb-1">Pages we'll build</div>
                    <ul class="ps-3 mb-3" data-testid="plan-included">
                        @foreach ($plan['included'] as $page)
                            <li>{{ $page['title'] }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="col-md-6">
                    @if (count($plan['excluded']) > 0)
                        <div class="text-label mb-1">Not built right now</div>
                        <ul class="ps-3 mb-3" data-testid="plan-excluded">
                            @foreach ($plan['excluded'] as $page)
                                <li>{{ $page['title'] }} <span class="text-caption">&mdash; {{ $page['reason'] }}</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @if ($plan['areas']['saved'] > 0)
                <div class="border-top pt-3" data-testid="area-plan">
                    <div class="fw-bold" data-testid="area-summary">Service areas saved {{ $plan['areas']['saved'] }} / local pages planned {{ $plan['areas']['planned'] }}</div>
                    <div class="text-caption mb-2">Every area you entered stays listed as a service area. Your first areas get their own page, in the order you entered them — put your most important area first.</div>
                    @if (count($plan['areas']['planned_list']) > 0)
                        <div class="small text-label">Areas that get their own page</div>
                        <ul class="ps-3 small mb-1" data-testid="area-planned-list">
                            @foreach ($plan['areas']['planned_list'] as $plannedArea)
                                <li>{{ $plannedArea }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if (count($plan['areas']['excluded']) > 0)
                        <details class="small mt-1">
                            <summary>Why aren't the other areas pages? ({{ count($plan['areas']['excluded']) }})</summary>
                            <ul class="ps-3 mb-0 mt-1">
                                @foreach ($plan['areas']['excluded'] as $excluded)
                                    <li>{{ $excluded['area'] }} <span class="text-caption">&mdash; {{ $excluded['reason'] }}</span></li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif
