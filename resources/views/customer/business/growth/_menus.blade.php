{{--
    Snooze and dismiss menus for one Opportunity ($card, $workspaceUid,
    $businessUid). Plain <details>, no JavaScript: each choice is its own
    CSRF-protected POST that goes through OpportunityManager. The snooze
    durations and dismiss reasons are closed lists validated server-side.
--}}
@php
    $base = fn (string $name) => route('customer.workspaces.businesses.growth.' . $name, [$workspaceUid, $businessUid, $card['uid']]);
    $reasons = \App\Library\Opportunity\OpportunityManager::DISMISS_REASONS;
    $reasonLabels = ['not_relevant' => 'Not relevant', 'already_handled' => 'Already handled', 'intentional' => 'Intentional', 'other' => 'Other'];
@endphp

@if($card['state'] === 'open')
    <details class="gc-menu" data-role="snooze-menu">
        <summary class="btn btn-flat-secondary btn-sm d-inline-flex align-items-center gap-1"><x-ds-icon name="clock" size="14" /> Snooze</summary>
        <form method="POST" action="{{ $base('opportunities.snooze') }}" class="gc-menu-body">
            @csrf
            <button type="submit" name="duration" value="tomorrow" data-duration="tomorrow">Tomorrow</button>
            <button type="submit" name="duration" value="3_days" data-duration="3_days">In 3 days</button>
            <button type="submit" name="duration" value="1_week" data-duration="1_week">In 1 week</button>
            <label for="snooze-{{ $card['uid'] }}">Or pick a date</label>
            <input type="date" id="snooze-{{ $card['uid'] }}" name="until" class="form-control form-control-sm" min="{{ now()->addDay()->toDateString() }}" max="{{ now()->addYear()->subDay()->toDateString() }}">
            <button type="submit" name="duration" value="custom" data-duration="custom">Snooze until that date</button>
        </form>
    </details>

    <details class="gc-menu" data-role="dismiss-menu">
        <summary class="btn btn-flat-secondary btn-sm d-inline-flex align-items-center gap-1"><x-ds-icon name="x" size="14" /> Dismiss</summary>
        <form method="POST" action="{{ $base('opportunities.dismiss') }}" class="gc-menu-body">
            @csrf
            <label for="dismiss-{{ $card['uid'] }}">Why? (optional)</label>
            <select id="dismiss-{{ $card['uid'] }}" name="reason" class="form-select form-select-sm">
                <option value="">No reason</option>
                @foreach($reasons as $key => $unused)
                    <option value="{{ $key }}">{{ $reasonLabels[$key] ?? $key }}</option>
                @endforeach
            </select>
            <button type="submit" data-role="confirm-dismiss">Dismiss it</button>
            <span class="gc-note" style="margin:0">It may return after a while if the problem is still there.</span>
        </form>
    </details>
@endif
