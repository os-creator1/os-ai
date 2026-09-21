<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book {{ $type->name }}</title>
    <style>
        body{font-family:system-ui,sans-serif;max-width:42rem;margin:3rem auto;padding:0 1rem;color:#17212b}
        label{display:block;margin:1rem 0 .3rem}input,button{font:inherit;padding:.65rem;border:1px solid #9ba7b4;border-radius:.4rem}
        button{background:#125a9c;color:white;border:0;cursor:pointer;margin-top:1.2rem}.slots{display:flex;flex-wrap:wrap;gap:.6rem}
        .slots label{margin:0}.slots input{margin-right:.25rem}.error{color:#a51d24}
    </style>
</head>
<body>
<main>
    <h1>{{ $type->name }}</h1>
    @if($type->description)<p>{{ $type->description }}</p>@endif
    <p>{{ $type->duration_minutes }} minutes · Times shown in {{ $timezone }}</p>
    <form method="get" action="{{ route('public.booking.show', [$type->public_booking_uuid]) }}">
        <label for="date-picker">Date</label>
        <input id="date-picker" type="date" name="date" value="{{ $day->toDateString() }}" required>
        <button type="submit">Show times</button>
    </form>
    @if($errors->any())<p class="error">Please check your details and choose an available time.</p>@endif
    @if(count($slots))
        <form method="post" action="{{ route('public.booking.store', [$type->public_booking_uuid]) }}">
            @csrf
            <input type="hidden" name="date" value="{{ $day->toDateString() }}">
            <h2>Available times</h2>
            <div class="slots">
                @foreach($slots as $time)
                    <label><input type="radio" name="time" value="{{ $time }}" required> {{ $time }}</label>
                @endforeach
            </div>
            <label for="first-name">First name</label><input id="first-name" name="first_name" value="{{ old('first_name') }}" required>
            <label for="last-name">Last name</label><input id="last-name" name="last_name" value="{{ old('last_name') }}" required>
            <label for="phone">Phone</label><input id="phone" type="tel" name="phone" value="{{ old('phone') }}" required>
            <button type="submit">Confirm booking</button>
        </form>
    @else
        <p>No available times on this date. Try another date.</p>
    @endif
</main>
</body>
</html>
