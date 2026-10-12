{{--
    Home V2 STATE 1 — the guided setup journey.

    Progress is "N of M" counted from persisted facts (HomeSetupJourney); it
    disappears from Home the moment the essential steps are true. Each step
    carries ONE action and says what it achieves in plain words.
--}}
<section class="mb-2" aria-labelledby="home-setup-heading" data-band="setup" data-role="home-setup">
    <x-card>
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-1 mb-1">
            <h2 class="h4 text-section-heading mb-0" id="home-setup-heading">Let's get your business ready</h2>
            <p class="text-caption text-muted mb-0" data-role="setup-progress">{{ $setup['done'] }} of {{ $setup['total'] }} steps done</p>
        </div>

        <ol class="list-unstyled mb-0" data-role="setup-steps">
            @foreach($setup['steps'] as $step)
                <li class="home-step" data-role="setup-step" data-step="{{ $step['key'] }}"
                    data-done="{{ $step['done'] ? 'true' : 'false' }}" data-next="{{ $setup['nextKey'] === $step['key'] ? 'true' : 'false' }}">
                    <span class="home-step-mark" aria-hidden="true">{{ $step['done'] ? '✓' : $loop->iteration }}</span>
                    <div class="flex-grow-1">
                        <p class="fw-bold mb-25">
                            {{ $step['title'] }}
                            @if($step['done'])<span class="visually-hidden"> — done</span>@endif
                        </p>
                        <p class="text-caption text-muted mb-50">{{ $step['outcome'] }}</p>
                        @if(! $step['done'] && ! empty($step['note']))
                            <p class="text-caption mb-50" data-role="setup-step-note">{{ $step['note'] }}</p>
                        @endif
                        @if(! $step['done'] && $step['action'] !== null)
                            @if($step['action']['drawer'] !== null && ($home['actions'][$step['action']['drawer']]['drawerUrl'] ?? null) !== null)
                                <a href="{{ $step['action']['url'] }}" class="btn btn-sm @if($setup['nextKey'] === $step['key']) btn-primary @else btn-outline-secondary @endif"
                                   data-role="setup-step-action"
                                   data-home-drawer="{{ $home['actions'][$step['action']['drawer']]['drawerUrl'] }}" data-drawer-title="{{ $step['action']['label'] }}">{{ $step['action']['label'] }}</a>
                            @else
                                <a href="{{ $step['action']['url'] }}" class="btn btn-sm @if($setup['nextKey'] === $step['key']) btn-primary @else btn-outline-secondary @endif"
                                   data-role="setup-step-action">{{ $step['action']['label'] }}</a>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </x-card>
</section>
