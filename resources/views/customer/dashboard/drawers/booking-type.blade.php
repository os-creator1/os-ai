{{--
    Home V2 drawer — Create a booking type.

    The fields are the module's create form (name, duration, description); the
    post goes to booking-types.store, whose own validation and Location rule
    apply. A booking type belongs to exactly ONE Location, so the Location is
    chosen here only when the actor can reach more than one.
--}}
<div data-role="drawer-booking-type">
    <p class="mb-1">A booking type is something customers can book — a consultation, a visit, a class. You can fine-tune hours and staff afterwards.</p>

    @if($locations === [])
        <p class="mb-0" data-role="drawer-empty">There is no location you can add a booking type to yet.</p>
    @else
        <form class="home-drawer-form" data-home-form="booking-type" action="{{ $locations[0]['storeUrl'] }}" method="POST">
            @csrf

            @if(count($locations) > 1)
                <div class="mb-1">
                    <label for="home-bt-location">Location</label>
                    <select id="home-bt-location" name="_location" class="form-select" data-action-from>
                        @foreach($locations as $l)
                            <option value="{{ $l['uid'] }}" data-url="{{ $l['storeUrl'] }}">{{ $l['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <p class="text-caption mb-1">This will belong to <strong>{{ $locations[0]['name'] }}</strong> only.</p>
            @endif

            <div class="mb-1">
                <label for="home-bt-name">Name</label>
                <input type="text" id="home-bt-name" name="name" class="form-control" maxlength="120" required>
            </div>

            <div class="mb-1">
                <label for="home-bt-duration">Duration (minutes)</label>
                <input type="number" id="home-bt-duration" name="duration_minutes" class="form-control" min="1" max="1440" value="30" required>
            </div>

            <div class="mb-1">
                <label for="home-bt-description">Description <span class="text-muted">(optional)</span></label>
                <textarea id="home-bt-description" name="description" class="form-control" rows="3"></textarea>
            </div>

            <x-button type="submit" data-role="booking-type-submit">Create booking type</x-button>
        </form>
    @endif
</div>
