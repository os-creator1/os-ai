{{--
    Website Builder redesign — a subtle progress indicator (task
    instruction), shared by every wizard step. `$backUrl` is omitted on
    the very first step (nothing to go back to).

    Independent-review correction round: the back arrow now POSTs to
    setup.back (WebsiteWizardController::goBack(), which persists the
    newly selected step via WebsiteSetupSessionManager::goToStep())
    instead of being a plain GET link straight to the previous step's
    URL — a GET link only ever rendered that step without ever updating
    `current_step_key`, so leaving immediately after going back (closing
    the tab, or using the main "Website" nav) resumed at the LATER step
    the owner had just left, not the one Back actually showed them.
--}}
<div class="mb-4">
    @isset($backStepKey)
        <form method="POST" action="{{ route('customer.workspaces.businesses.website.setup.back', [$workspaceUid, $businessUid, $currentStepKey]) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-link p-0 d-inline-flex align-items-center gap-1 transition-fast text-label mb-2">
                <x-ds-icon name="arrow-left" size="16" aria-hidden="true" />
                Back
            </button>
        </form>
    @endisset
    <div class="progress" style="height: 4px;">
        <div class="progress-bar" role="progressbar" style="width: {{ $progress['total'] > 0 ? min(100, round($progress['current'] / $progress['total'] * 100)) : 0 }}%"></div>
    </div>
    <p class="text-caption mt-1 mb-0">Step {{ $progress['current'] }} of {{ $progress['total'] }}</p>
</div>
