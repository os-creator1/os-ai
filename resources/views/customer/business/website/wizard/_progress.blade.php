{{--
    Website Builder redesign — a subtle progress indicator (task
    instruction), shared by every wizard step. `$backUrl` is omitted on
    the very first step (nothing to go back to).
--}}
<div class="mb-4">
    @isset($backUrl)
        <a href="{{ $backUrl }}" class="d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
            <x-ds-icon name="arrow-left" size="16" aria-hidden="true" />
            Back
        </a>
    @endisset
    <div class="progress" style="height: 4px;">
        <div class="progress-bar" role="progressbar" style="width: {{ $progress['total'] > 0 ? min(100, round($progress['current'] / $progress['total'] * 100)) : 0 }}%"></div>
    </div>
    <p class="text-caption mt-1 mb-0">Step {{ $progress['current'] }} of {{ $progress['total'] }}</p>
</div>
