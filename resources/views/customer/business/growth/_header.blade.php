{{--
    Growth Center page header + tab navigation. Shared by every Growth view.
    Expects: $workspaceUid, $businessUid, $tab, $score (latest snapshot|null),
    $openCount, $engineEnabled.
--}}
@php
    $growthRoute = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.growth.' . $name, array_merge([$workspaceUid, $businessUid], $extra));
    $tabs = [
        'overview' => ['Overview', $growthRoute('index')],
        'opportunities' => ['Opportunities', $growthRoute('opportunities.index')],
        'score' => ['Score', $growthRoute('score')],
        'insights' => ['Insights', $growthRoute('insights')],
        'advisor' => ['Advisor', $growthRoute('advisor')],
    ];
@endphp

<div class="gc" data-surface="growth-center">
    <div class="gc-header">
        <div>
            <h1 class="gc-title" data-role="growth-title">Growth Center</h1>
            <p class="gc-subtitle">See what is helping or holding back growth — and what to do next.</p>
        </div>
        <div class="gc-header-meta">
            @if($score !== null)
                <span data-role="last-checked">Checked {{ $score->computed_at->diffForHumans() }}</span>
            @endif
            @if($engineEnabled)
                <form method="POST" action="{{ $growthRoute('refresh') }}" data-role="refresh-form">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm" icon="refresh-cw" data-role="refresh">Check again</x-button>
                </form>
            @endif
        </div>
    </div>

    <x-flash-alert class="mb-2" />

    @if($errors->has('opportunity'))
        <x-alert variant="danger" icon="alert-circle" role="alert" class="mb-2" data-role="growth-error">{{ $errors->first('opportunity') }}</x-alert>
    @endif

    @unless($engineEnabled)
        <div class="gc-banner is-info" data-role="engine-off">
            <x-ds-icon name="info" size="18" />
            <div>
                <strong>Growth checks are not running yet.</strong>
                <div>Your recommendations will appear here once the platform turns them on. Nothing is wrong with your business.</div>
            </div>
        </div>
    @endunless

    <nav class="gc-tabs" aria-label="Growth Center sections" data-role="growth-tabs">
        @foreach($tabs as $key => [$label, $url])
            <a href="{{ $url }}" class="gc-tab @if($tab === $key) is-active @endif" data-tab="{{ $key }}" @if($tab === $key) aria-current="page" @endif>
                {{ $label }}
                @if($key === 'opportunities' && $openCount > 0)
                    <span class="gc-tab-count" data-role="tab-open-count">{{ $openCount }}</span>
                @endif
            </a>
        @endforeach
    </nav>
</div>
