{{--
    Home V2 — the Business Command Center.

    ONE layout over the model HomeCommandCenter built from the bands the
    presenter already read. This template decides placement only: it computes
    nothing, queries nothing, and never invents a fact. The bands keep their
    own partials (billing strip, performance), so the figures here are the very
    ones Results and the legacy Home show.

    Order is the mobile order (greeting → move → attention → ask → performance
    → handled work); on desktop the move and attention share a row.

    State changes emphasis only — never a second page: `setup` puts the guided
    journey where the move normally sits; `broken` lifts attention above it.
--}}
@php
    use App\Library\Dashboard\DashboardSnapshot;
    use App\Library\Dashboard\Home\HomeCommandCenter;

    /** @var array<string, mixed> $home */
    $home = $dashboard->band(DashboardSnapshot::BAND_COMMAND);
    $state = $home['state'];
    // STATE 3 lifts attention above the move only when it has something of its own to say; when the
    // problem IS the primary move, the move card already carries it and stays where it is.
    $liftAttention = $state === HomeCommandCenter::STATE_BROKEN && $home['attention']['visible'] !== [];
    // The move card exists only when there IS a move (or it could not be worked out); the attention card only when it has
    // something of its own to say (an item, or "we could not check"). Otherwise the page rebalances around Ask.
    $showMove = $home['primary'] !== null || $home['primaryFailed'];
    $showAttention = ! $liftAttention && ($home['attention']['visible'] !== [] || $home['attention']['unknown']);
    $showQuiet = $home['status']['key'] === 'clear' && $home['quietSteps'] !== [];
    $showAutomations = $dashboard->has(DashboardSnapshot::BAND_AUTOMATIONS) || $dashboard->failed(DashboardSnapshot::BAND_AUTOMATIONS) || ($home['actions']['automation'] ?? null) !== null;
@endphp

<div data-role="command-center" data-home-state="{{ $state }}" class="home-command">
    @include('customer.dashboard.home._styles')

    @if($dashboard->has(DashboardSnapshot::BAND_BILLING_EXCEPTION))
        @include('customer.dashboard.bands.billing-exception', ['exception' => $dashboard->band(DashboardSnapshot::BAND_BILLING_EXCEPTION)])
    @endif

    {{-- A · greeting and briefing --}}
    @include('customer.dashboard.home._briefing', ['home' => $home])

    @if($state === HomeCommandCenter::STATE_SETUP)
        {{-- STATE 1 — a guided journey, driven by real readiness checks. --}}
        @include('customer.dashboard.home._setup', ['setup' => $home['setup']])
    @endif

    @if($home['readinessUnknown'] ?? false)
        {{-- Setup progress could not be read: say so plainly, claim nothing either way. --}}
        @include('customer.dashboard.band-failed', ['band' => 'setup', 'title' => 'Setup progress'])
    @endif

    @if($liftAttention)
        {{-- STATE 3 — an operational problem is the first thing on the page. --}}
        <div class="mb-2">
            @include('customer.dashboard.home._attention', ['attention' => $home['attention'], 'prominent' => true])
        </div>
    @endif

    {{-- B/C · the move and what else needs the customer. Absent when there is nothing to act on: a quiet Business gets no
         empty urgent-action container, and the page rebalances around the Ask card (screenshot: quiet Home). --}}
    @if($showMove || $showAttention)
        <div class="row g-2 mb-2 align-items-stretch">
            @if($showMove)
                <div class="@if($showAttention && $state !== HomeCommandCenter::STATE_SETUP) col-12 col-xl-8 @else col-12 @endif">
                    @include('customer.dashboard.home._move', ['home' => $home])
                </div>
            @endif

            @if($showAttention)
                <div class="@if($showMove && $state !== HomeCommandCenter::STATE_SETUP) col-12 col-xl-4 @else col-12 @endif">
                    @include('customer.dashboard.home._attention', ['attention' => $home['attention'], 'prominent' => false])
                </div>
            @endif
        </div>
    @endif

    {{-- F · Ask MotionGrove --}}
    @include('customer.dashboard.home._ask', ['home' => $home])

    {{-- STATE 4 — with nothing urgent, what could grow the business is the emphasis. --}}
    @if($home['queue'] !== [])
        @include('customer.dashboard.home._queue', ['home' => $home, 'emphasis' => $state === HomeCommandCenter::STATE_GROWING])
    @endif

    @if($dashboard->has(DashboardSnapshot::BAND_GROWTH))
        @include('customer.dashboard.home._growth-insights', ['growth' => $dashboard->band(DashboardSnapshot::BAND_GROWTH)])
    @endif

    {{-- D · Business performance (the existing band) --}}
    @if($dashboard->failed(DashboardSnapshot::BAND_HEADLINES))
        @include('customer.dashboard.band-failed', ['band' => 'headlines', 'title' => 'Business performance'])
    @elseif($dashboard->has(DashboardSnapshot::BAND_HEADLINES))
        @include('customer.dashboard.bands.headlines', ['headlines' => $dashboard->band(DashboardSnapshot::BAND_HEADLINES), 'stylesLoaded' => true])
    @endif

    {{-- E · bottom row: quiet-time next steps (only when quiet and offered) or Automations, beside what MotionGrove handles. --}}
    {{-- Quiet layout: Automations has no column, but an unreadable Automations is never hidden (and recorded activity is shown). --}}
    @if($showQuiet && ($dashboard->failed(DashboardSnapshot::BAND_AUTOMATIONS) || $dashboard->has(DashboardSnapshot::BAND_AUTOMATIONS)))
        <div class="mb-2">@include('customer.dashboard.home._automations', ['home' => $home, 'dashboard' => $dashboard])</div>
    @endif
    <div class="row g-2 mb-2 align-items-stretch home-bottom-row">
        @if($showQuiet)
            <div class="col-12 col-lg-6">@include('customer.dashboard.home._quiet', ['steps' => $home['quietSteps']])</div>
            <div class="col-12 col-lg-6">@include('customer.dashboard.home._handling', ['home' => $home, 'dashboard' => $dashboard])</div>
        @else
            <div class="col-12 @if($showAutomations) col-lg-6 @endif">@include('customer.dashboard.home._handling', ['home' => $home, 'dashboard' => $dashboard])</div>
            @if($showAutomations)
                <div class="col-12 col-lg-6">@include('customer.dashboard.home._automations', ['home' => $home, 'dashboard' => $dashboard])</div>
            @endif
        @endif
    </div>

    {{-- Everything the legacy Home showed stays reachable, one fold down. --}}
    <details class="mb-2 home-more" data-role="home-more">
        <summary class="text-label py-50">More detail</summary>
        <div class="pt-1">
            @foreach([
                [DashboardSnapshot::BAND_VISIBILITY, 'visibility', 'Visibility', 'bands.visibility', 'visibility'],
                [DashboardSnapshot::BAND_CONVERSATIONS, 'conversations', 'Conversations', 'bands.conversations', 'conversations'],
            ] as [$bandKey, $bandName, $bandTitle, $partial, $var])
                @if($dashboard->failed($bandKey))
                    @include('customer.dashboard.band-failed', ['band' => $bandName, 'title' => $bandTitle])
                @elseif($dashboard->has($bandKey))
                    @include('customer.dashboard.' . $partial, [$var => $dashboard->band($bandKey)])
                @endif
            @endforeach

            @if($dashboard->has(DashboardSnapshot::BAND_ACTIONS) && $dashboard->band(DashboardSnapshot::BAND_ACTIONS)['items'] !== [])
                @include('customer.dashboard.bands.actions', ['actions' => $dashboard->band(DashboardSnapshot::BAND_ACTIONS)])
            @endif
        </div>
    </details>
    @include('customer.dashboard.home._drawer')
</div>
