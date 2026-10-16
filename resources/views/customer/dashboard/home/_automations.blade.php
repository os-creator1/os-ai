{{--
    Home V2 — Automations, beside "What MotionGrove is handling".

    The figures are the Automations band's own (completed / failed for the chosen period, B5's counts) and the band is absent
    when nothing ran — so "No automations ran" is said ONLY from that absence, never from a failed read (that says it could
    not load). "Set one up" appears only where the actor may create a workflow on a plan that includes Automations.
--}}
@php
    use App\Library\Dashboard\DashboardSnapshot;

    $failedBand = $dashboard->failed(DashboardSnapshot::BAND_AUTOMATIONS);
    $band = $dashboard->has(DashboardSnapshot::BAND_AUTOMATIONS) ? $dashboard->band(DashboardSnapshot::BAND_AUTOMATIONS) : null;
    $setUp = $home['actions']['automation'] ?? null;
    $period = $dashboard->has(DashboardSnapshot::BAND_HEADLINES) ? strtolower($dashboard->band(DashboardSnapshot::BAND_HEADLINES)['range']->label()) : 'in this period';
@endphp
<section class="h-100" aria-labelledby="home-automations-heading" data-band="automations" data-role="home-automations">
    <x-card class="h-100 home-panel">
        <h2 class="home-panel-title mb-1" id="home-automations-heading">Automations</h2>

        @if($failedBand)
            <p class="mb-0 text-muted" data-role="automations-unavailable">We couldn't load automation activity just now.</p>
        @elseif($band !== null)
            <ul class="list-unstyled mb-0 home-rows" data-role="automations-items">
                @foreach($band['items'] as $item)
                    <li class="home-row" data-role="automations-item" data-automations="{{ $item['key'] }}"@if($item['severity'] !== null) data-severity="{{ $item['severity']->value }}"@endif>
                        <span class="home-row-text"><strong>{{ $item['label'] }}: <span data-role="automations-figure">{{ $item['figure'] }}</span></strong><span class="text-caption text-muted">{{ $item['caption'] }}</span></span>
                        @if($item['severity'] !== null)
                            <x-badge :variant="$item['severity']->badgeVariant()" data-role="automations-severity">{{ $item['severity']->word() }}</x-badge>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($band['reviewUrl'])
                <p class="mt-1 mb-0 text-caption"><a href="{{ $band['reviewUrl'] }}" data-role="automations-review">Review</a></p>
            @endif
        @else
            <div class="home-row" data-role="automations-empty">
                <span class="home-row-text">
                    <strong>No automations ran {{ $period }}</strong>
                    @if($setUp !== null)
                        <span class="text-caption text-muted">A follow-up automation could answer new messages while you're busy.</span>
                    @endif
                </span>
                @if($setUp !== null)
                    <a href="{{ $setUp['fullUrl'] }}" class="home-row-link" data-role="automations-setup">{{ $setUp['label'] }}</a>
                @endif
            </div>
        @endif
    </x-card>
</section>
