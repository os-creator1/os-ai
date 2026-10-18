{{--
    Home V2 — the final Home.

    ONE template over HomeCommandCenter's model and its `v2` block (HomeV2Presenter). It decides placement only: it computes
    nothing, queries nothing and never invents a fact. A customer should read this page in about five seconds:

      main column   greeting → Needs you → Ask MotionGrove → Is my business growing? (3 cards, one chart)
      side column   Done for you → Suggested next
      new business  a short "Get set up" checklist beside Ask — no results yet, so none are shown

    Billing, failed sources and the all-clear are decided in the presenter; nothing here says "all caught up" on its own.
--}}
@php
    use App\Library\Dashboard\DashboardSnapshot;
    use App\Library\Dashboard\Home\HomeV2Presenter;

    /** @var array<string, mixed> $home */
    $home = $dashboard->band(DashboardSnapshot::BAND_COMMAND);
    $v2 = $home['v2'];
    $setupLayout = $v2['layout'] === HomeV2Presenter::LAYOUT_SETUP;
    $greetingName = $home['greeting']['name'];
    // The supporting line stays short: the visit summary when there is one worth saying, otherwise the plain line.
    $briefing = $home['briefing'];
    $subline = ($briefing['unavailable'] || ($briefing['firstVisit'] ?? false) || ($briefing['quiet'] ?? false))
        ? "Here's what's happening with your business."
        : $briefing['text'];
@endphp

<div data-role="command-center" data-home-state="{{ $home['state'] }}" data-home-layout="{{ $v2['layout'] }}" data-home-status="{{ $home['status']['key'] }}" class="home-command hv2">
    @include('customer.dashboard.home._styles')
    @include('customer.dashboard.home.v2._styles')

    @if($dashboard->has(DashboardSnapshot::BAND_BILLING_EXCEPTION))
        @include('customer.dashboard.bands.billing-exception', ['exception' => $dashboard->band(DashboardSnapshot::BAND_BILLING_EXCEPTION)])
    @endif

    @if($home['readinessUnknown'] ?? false)
        {{-- Setup progress could not be read: say so plainly, claim nothing either way. --}}
        @include('customer.dashboard.band-failed', ['band' => 'setup', 'title' => 'Setup progress'])
    @endif

    @if($setupLayout)
        @include('customer.dashboard.home.v2._setup', ['home' => $home, 'v2' => $v2, 'greetingName' => $greetingName])
    @else
        <div class="hv2-layout">
            <div class="hv2-main">
                <section class="hv2-greeting" aria-labelledby="home-greeting-title" data-role="home-greeting">
                    <h2 class="hv2-greeting__title" id="home-greeting-title" data-role="home-salutation">{{ $home['greeting']['salutation'] }}@if($greetingName), {{ $greetingName }}@endif</h2>
                    <p class="hv2-greeting__sub" data-role="home-subline">{{ $subline }}</p>
                </section>

                @include('customer.dashboard.home.v2._needs', ['home' => $home, 'needs' => $v2['needs']])

                @include('customer.dashboard.home._ask', ['home' => $home, 'v2Chips' => $v2['chips']])

                @include('customer.dashboard.home.v2._growth', ['dashboard' => $dashboard, 'growth' => $v2['growth']])

                {{-- Everything the earlier Home showed that is not on this page stays one fold down, never removed. --}}
                @php $moreBands = [
                    [DashboardSnapshot::BAND_VISIBILITY, 'visibility', 'Visibility', 'bands.visibility', 'visibility'],
                    [DashboardSnapshot::BAND_CONVERSATIONS, 'conversations', 'Conversations', 'bands.conversations', 'conversations'],
                    [DashboardSnapshot::BAND_AUTOMATIONS, 'automations', 'Automations', 'bands.automations', 'automations'],
                ]; @endphp
                <details class="hv2-more" data-role="home-more">
                    <summary>More detail</summary>
                    <div class="hv2-more__body">
                        @foreach($moreBands as [$bandKey, $bandName, $bandTitle, $partial, $var])
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
            </div>

            <aside class="hv2-side" aria-label="Done for you and suggestions">
                @include('customer.dashboard.home.v2._done', ['done' => $v2['done'], 'failed' => $dashboard->failed(DashboardSnapshot::BAND_RECENT_WORK)])
                @include('customer.dashboard.home.v2._suggested', ['suggested' => $v2['suggested']])
            </aside>
        </div>
    @endif

    @include('customer.dashboard.home._drawer')
    <script src="{{ asset('js/home/home-v2.js') }}?v={{ @filemtime(public_path('js/home/home-v2.js')) }}" defer></script>
</div>
