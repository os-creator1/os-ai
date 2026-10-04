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

    <x-card title="Weekly availability" class="mb-2" data-section="availability-rules">
        <p class="text-caption">
            When each person is bookable at <strong>{{ $location->name ?: 'this location' }}</strong>.
            Times are local to this business. Add more than one window on a day for a split shift.
            @unless ($isOwner)
                You can set your own hours here; the account owner sets everyone else's.
            @endunless
        </p>

        @if ($rules->isEmpty())
            <x-empty-state icon="clock" title="No availability set for this location yet."
                            description="Nobody can be booked here until somebody has hours." />
        @else
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Day</th>
                            <th>From</th>
                            <th>To</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            @php
                                $mayEdit = $isOwner || (int) $rule->staff_user_id === $actorId;
                                $person = $nameById->get($rule->staff_user_id);
                            @endphp
                            <tr>
                                <td class="text-label">{{ $person ? $staffName($person) : 'User #' . $rule->staff_user_id }}</td>
                                <td>{{ $days[$rule->day_of_week] ?? $rule->day_of_week }}</td>
                                <td>{{ $rule->start_time }}</td>
                                <td>{{ $rule->end_time }}</td>
                                <td class="text-right">
                                    @if ($mayEdit)
                                        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.availability.rules.destroy', array_merge($scope, [$rule->id])) }}">
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

        <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.availability.rules.store', $scope) }}">
            @csrf
            <div class="form-row align-items-end">
                <div class="form-group col-md-4">
                    <label for="rule_staff_user_id">Person</label>
                    <select id="rule_staff_user_id" name="staff_user_id" class="form-control" required>
                        @foreach ($selectable as $candidate)
                            <option value="{{ $candidate->id }}">{{ $staffName($candidate) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label for="day_of_week">Day</label>
                    <select id="day_of_week" name="day_of_week" class="form-control" required>
                        @foreach ($days as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label for="start_time">From</label>
                    <input type="time" id="start_time" name="start_time" class="form-control" required>
                </div>
                <div class="form-group col-md-2">
                    <label for="end_time">To</label>
                    <input type="time" id="end_time" name="end_time" class="form-control" required>
                </div>
                <div class="form-group col-md-1">
                    <x-button type="submit" variant="primary" icon="plus">Add</x-button>
                </div>
            </div>
        </form>
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
