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

    @foreach (['flash_success' => 'success', 'flash_info' => 'neutral', 'flash_error' => 'danger'] as $flashKey => $flashVariant)
        @if (session($flashKey))
            <x-alert :variant="$flashVariant" class="mb-2" role="status">{{ session($flashKey) }}</x-alert>
        @endif
    @endforeach

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

            @php
                $weekProblems = collect($errors->getMessages())->filter(fn ($messages, $key) => str_starts_with($key, 'days'))->flatten();
            @endphp
            @if ($weekProblems->isNotEmpty())
                <x-alert variant="danger" class="mb-1" role="alert">
                    @foreach ($weekProblems as $message)
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

    @php
        // Re-open the editor, with the typed values, after a refused submit.
        $timeOffFields = ['staff_user_id', 'start_at', 'end_at', 'reason'];
        $timeOffOpen = collect($timeOffFields)->contains(fn ($field) => $errors->has($field));
        $formatMoment = static fn ($moment) => $moment->format('M j, Y') . ' · ' . $moment->format('g:i A');
    @endphp

    <x-card title="Time off" class="mb-2" data-section="availability-time-off">
        {{-- §5.3/§6 condition 3: the User-global scope is stated plainly to the actor — as
             ordinary helper text, not a warning. --}}
        <p class="text-caption" data-role="time-off-scope">
            Time off applies across all locations where this person works, for the whole period.
        </p>

        @if ($timeOff->isNotEmpty())
            <div class="timeoff-list" data-role="time-off-list">
                @foreach ($timeOff as $entry)
                    @php
                        $mayEdit = $isOwner || (int) $entry->staff_user_id === $actorId;
                        $who = $nameById->get($entry->staff_user_id);
                    @endphp
                    <div class="timeoff-row" data-role="time-off-row" data-time-off="{{ $entry->id }}">
                        <div class="timeoff-person text-label">{{ $who ? $staffName($who) : 'User #' . $entry->staff_user_id }}</div>
                        <div class="timeoff-range">
                            <span>{{ $formatMoment($entry->start_at) }}</span>
                            <span class="availability-to" aria-hidden="true">→</span>
                            <span class="visually-hidden">to</span>
                            <span>{{ $formatMoment($entry->end_at) }}</span>
                        </div>
                        <div class="timeoff-reason text-caption">{{ $entry->reason ?: '' }}</div>
                        <div class="timeoff-actions">
                            @if ($mayEdit)
                                <form method="POST" action="{{ route('customer.workspaces.businesses.calendar.availability.time-off.destroy', array_merge($scope, [$entry->id])) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-flat-secondary"
                                            aria-label="Delete time off for {{ $who ? $staffName($who) : 'this person' }}, {{ $formatMoment($entry->start_at) }}">Delete</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="timeoff-footer {{ $timeOff->isEmpty() ? 'is-empty' : '' }}" data-timeoff-footer @if ($timeOffOpen) hidden @endif>
            @if ($timeOff->isEmpty())
                <span class="text-caption" data-role="time-off-empty">No time off scheduled.</span>
            @endif
            @if ($selectable->isNotEmpty())
                <button type="button" class="btn btn-sm btn-flat-primary" data-timeoff-open data-role="add-time-off"
                        aria-controls="timeoff-editor" aria-expanded="{{ $timeOffOpen ? 'true' : 'false' }}">
                    <x-ds-icon name="plus" size="14" aria-hidden="true" /> Add time off
                </button>
            @endif
        </div>

        @if ($selectable->isNotEmpty())
            <form method="POST" id="timeoff-editor" class="timeoff-editor" data-timeoff-editor data-role="time-off-editor"
                  action="{{ route('customer.workspaces.businesses.calendar.availability.time-off.store', $scope) }}"
                  @unless ($timeOffOpen) hidden @endunless>
                @csrf
                <div class="timeoff-fields">
                    <div class="timeoff-field">
                        <label for="time_off_staff_user_id" class="form-label">Person</label>
                        <select id="time_off_staff_user_id" name="staff_user_id" class="form-select form-select-sm @error('staff_user_id') is-invalid @enderror"
                                @error('staff_user_id') aria-describedby="time_off_staff_user_id_error" @enderror required>
                            @foreach ($selectable as $candidate)
                                <option value="{{ $candidate->id }}" @selected((int) old('staff_user_id', $person?->id) === (int) $candidate->id)>{{ $staffName($candidate) }}</option>
                            @endforeach
                        </select>
                        @error('staff_user_id')<div class="invalid-feedback d-block" id="time_off_staff_user_id_error">{{ $message }}</div>@enderror
                    </div>

                    <div class="timeoff-field">
                        <label for="start_at" class="form-label">From</label>
                        <input type="datetime-local" id="start_at" name="start_at" value="{{ old('start_at') }}"
                               class="form-control form-control-sm @error('start_at') is-invalid @enderror"
                               @error('start_at') aria-describedby="start_at_error" @enderror required>
                        @error('start_at')<div class="invalid-feedback d-block" id="start_at_error">{{ $message }}</div>@enderror
                    </div>

                    <div class="timeoff-field">
                        <label for="end_at" class="form-label">To</label>
                        <input type="datetime-local" id="end_at" name="end_at" value="{{ old('end_at') }}"
                               class="form-control form-control-sm @error('end_at') is-invalid @enderror"
                               @error('end_at') aria-describedby="end_at_error" @enderror required>
                        @error('end_at')<div class="invalid-feedback d-block" id="end_at_error">{{ $message }}</div>@enderror
                    </div>

                    <div class="timeoff-field">
                        <label for="reason" class="form-label">Reason <span class="text-caption">(optional)</span></label>
                        <input type="text" id="reason" name="reason" value="{{ old('reason') }}" maxlength="255"
                               class="form-control form-control-sm @error('reason') is-invalid @enderror"
                               @error('reason') aria-describedby="reason_error" @enderror>
                        @error('reason')<div class="invalid-feedback d-block" id="reason_error">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="timeoff-editor-actions">
                    <button type="button" class="btn btn-sm btn-flat-secondary" data-timeoff-cancel>Cancel</button>
                    <x-button type="submit" variant="primary" size="sm" data-role="save-time-off">Add time off</x-button>
                </div>
            </form>
        @endif
    </x-card>
@endsection
