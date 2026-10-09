@extends(request()->query('fragment') === '1' ? 'customer.business.calendar._fragment' : 'customer.business.calendar._frame')

@section('title', 'Staff availability')
@section('calendar-active', 'availability')

@section('calendar-section')
    @php
        // Implementation Contract 15 §5.2, §5.3, §6 — presentation only.
        //
        // The staff pickers below offer exactly what §6's authority table
        // permits this actor: an owner may choose any currently-eligible
        // person, while an Admin or Staff member may only ever act on
        // themselves. The write path enforces the same rule again — this
        // view narrows the controls so nobody is offered an action that
        // would be refused, it does NOT decide anything.
        $scope = [$workspace->uid, $business->uid, $location->uid];
        $staffName = static fn ($user) => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->email ?? ('User #' . $user->id));
        $selectable = $isOwner ? $eligibleStaff : $eligibleStaff->filter(static fn ($user) => (int) $user->id === $actorId)->values();
        $nameById = $eligibleStaff->keyBy('id');
        $days = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
    @endphp

    <x-card title="Weekly hours" class="mb-2" data-section="availability-rules">
        <p class="text-caption">
            When each person is bookable at <strong>{{ $location->name ?: 'this location' }}</strong>.
            Times are local to this business. Switch a day off for closed, and add another set of hours for a split day.
            @unless ($isOwner)
                You can set your own hours here; the account owner sets everyone else's.
            @endunless
        </p>

        @if ($rules->isEmpty())
            <x-empty-state icon="clock" title="No availability set for this location yet."
                            description="Nobody can be booked here until somebody has hours." />
        @endif

        @if ($person === null)
            <p class="text-caption mb-0">There is nobody you can set hours for at this location.</p>
        @else
            <div class="availability-toolbar" data-role="availability-toolbar">
                @if ($selectable->count() > 1)
                    <form method="GET" action="{{ route('customer.workspaces.businesses.calendar.availability.index', $scope) }}" class="availability-person">
                        <label for="availability_person" class="form-label mb-0">Person</label>
                        <select id="availability_person" name="person" class="form-select form-select-sm" onchange="this.form.submit()">
                            @foreach ($selectable as $candidate)
                                <option value="{{ $candidate->id }}" @selected((int) $candidate->id === (int) $person->id)>{{ $staffName($candidate) }}</option>
                            @endforeach
                        </select>
                        <noscript><x-button type="submit" variant="secondary" size="sm">Show</x-button></noscript>
                    </form>
                @else
                    <span class="text-label" data-role="availability-person-name">{{ $staffName($person) }}</span>
                @endif

                @if ($hasBusinessHours)
                    <a class="btn btn-sm btn-outline-secondary" data-role="use-business-hours"
                       href="{{ route('customer.workspaces.businesses.calendar.availability.index', $scope) }}?person={{ $person->id }}&amp;prefill=business">
                        Use business hours
                    </a>
                @endif
            </div>

            @if ($prefilled)
                <x-alert variant="neutral" class="mb-1" role="status" data-role="availability-prefill-note">
                    These are this location's business hours. Adjust them if you need to, then save — nothing changes until you do.
                </x-alert>
            @endif

            @if ($errors->any())
                <x-alert variant="danger" class="mb-1" role="alert">
                    @foreach ($errors->all() as $message)
                        <div>{{ $message }}</div>
                    @endforeach
                </x-alert>
            @endif

            <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.availability.week.update', $scope) }}" data-availability-editor>
                @csrf
                <input type="hidden" name="staff_user_id" value="{{ $person->id }}">

                <div class="availability-week" data-role="availability-week">
                    @foreach ($editorDays as $day)
                        @php
                            $windows = $week[$day] ?? [];
                            $isOpen = $windows !== [];
                        @endphp
                        <div class="availability-day {{ $isOpen ? '' : 'is-closed' }}" data-day="{{ $day }}" data-role="availability-day">
                            <div class="availability-day-name form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="open-{{ $day }}" name="open[{{ $day }}]" value="1"
                                       data-day-toggle @checked($isOpen)>
                                <label class="form-check-label" for="open-{{ $day }}">{{ $days[$day] }}</label>
                            </div>

                            <div class="availability-day-body">
                                <span class="availability-closed" data-role="availability-closed">Closed</span>
                                <div class="availability-intervals" data-intervals data-next-index="{{ count($windows) }}">
                                    @foreach ($windows as $i => $window)
                                        <div class="availability-interval" data-interval>
                                            <input type="time" class="form-control form-control-sm" name="days[{{ $day }}][{{ $i }}][start]" value="{{ $window['start'] }}"
                                                   aria-label="{{ $days[$day] }} opening time" required>
                                            <span class="availability-to" aria-hidden="true">to</span>
                                            <input type="time" class="form-control form-control-sm" name="days[{{ $day }}][{{ $i }}][end]" value="{{ $window['end'] }}"
                                                   aria-label="{{ $days[$day] }} closing time" required>
                                            <button type="button" class="btn btn-sm btn-icon btn-flat-secondary availability-remove" data-remove-interval
                                                    aria-label="Remove these hours from {{ $days[$day] }}"><x-ds-icon name="x" size="14" aria-hidden="true" /></button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="availability-day-actions">
                                <button type="button" class="btn btn-sm btn-flat-primary" data-add-interval aria-label="Add another set of hours on {{ $days[$day] }}">
                                    <x-ds-icon name="plus" size="14" aria-hidden="true" /> Add hours
                                </button>
                            </div>

                            <template data-interval-template>
                                <div class="availability-interval" data-interval>
                                    <input type="time" class="form-control form-control-sm" name="days[{{ $day }}][__INDEX__][start]" aria-label="{{ $days[$day] }} opening time" required>
                                    <span class="availability-to" aria-hidden="true">to</span>
                                    <input type="time" class="form-control form-control-sm" name="days[{{ $day }}][__INDEX__][end]" aria-label="{{ $days[$day] }} closing time" required>
                                    <button type="button" class="btn btn-sm btn-icon btn-flat-secondary availability-remove" data-remove-interval
                                            aria-label="Remove these hours from {{ $days[$day] }}"><x-ds-icon name="x" size="14" aria-hidden="true" /></button>
                                </div>
                            </template>
                        </div>
                    @endforeach
                </div>

                <div class="d-flex justify-content-end mt-2">
                    <x-button type="submit" variant="primary" icon="save" data-role="save-weekly-hours">Save hours</x-button>
                </div>
            </form>
        @endif
    </x-card>

    <x-card title="Time off" class="mb-2" data-section="availability-time-off">
        {{-- §5.3/§6 condition 3: the User-global scope must be stated plainly to the actor. --}}
        <x-alert variant="neutral" class="mb-2">
            <strong>Time off applies everywhere, not just this location.</strong>
            A person on time off is unavailable at every location they work at, for the whole period.
        </x-alert>

        @if ($timeOff->isEmpty())
            <p class="text-caption">No time off recorded.</p>
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Reason</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($timeOff as $entry)
                            @php
                                $mayEdit = $isOwner || (int) $entry->staff_user_id === $actorId;
                                $person = $nameById->get($entry->staff_user_id);
                            @endphp
                            <tr>
                                <td class="text-label">{{ $person ? $staffName($person) : 'User #' . $entry->staff_user_id }}</td>
                                <td>{{ $entry->start_at }}</td>
                                <td>{{ $entry->end_at }}</td>
                                <td>{{ $entry->reason ?: '—' }}</td>
                                <td class="text-right">
                                    @if ($mayEdit)
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.availability.time-off.destroy', array_merge($scope, [$entry->id])) }}">
                                            @csrf
                                            <x-button type="submit" variant="ghost" size="sm" icon="x">Remove</x-button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <hr class="my-2">

        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.availability.time-off.store', $scope) }}">
            @csrf
            <div class="form-row align-items-end">
                <div class="form-group col-md-3">
                    <label for="time_off_staff_user_id">Person</label>
                    <select id="time_off_staff_user_id" name="staff_user_id" class="form-control" required>
                        @foreach ($selectable as $candidate)
                            <option value="{{ $candidate->id }}">{{ $staffName($candidate) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label for="start_at">From</label>
                    <input type="datetime-local" id="start_at" name="start_at" class="form-control" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="end_at">To</label>
                    <input type="datetime-local" id="end_at" name="end_at" class="form-control" required>
                </div>
                <div class="form-group col-md-2">
                    <label for="reason">Reason</label>
                    <input type="text" id="reason" name="reason" class="form-control" maxlength="255">
                </div>
                <div class="form-group col-md-1">
                    <x-button type="submit" variant="primary" icon="plus">Add</x-button>
                </div>
            </div>
        </form>
    </x-card>
@endsection
