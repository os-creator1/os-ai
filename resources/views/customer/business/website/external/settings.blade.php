@extends('customer.business.website.external.layout')

@section('external-content')
    <x-card :padded="true" class="mb-2" data-role="address">
        <p class="text-label mb-1">Your website address</p>
        <form method="POST" action="{{ route('customer.workspaces.businesses.website.external.settings.update', [$workspaceUid, $businessUid]) }}" class="row g-1 align-items-end">
            @csrf
            <div class="col-md-8">
                <label class="form-label" for="settings-website-url">Address</label>
                <input class="form-control" id="settings-website-url" name="website_url" value="{{ old('website_url', $business->website_url) }}" placeholder="example.com" maxlength="2048" @disabled(! $canEditAddress)>
                @unless($canEditAddress)<p class="text-caption text-muted mb-0">Only the account owner can change the address.</p>@endunless
            </div>
            @if($canEditAddress)
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100">Save address</button></div>
            @endif
        </form>
        <p class="text-caption text-muted mt-1 mb-0">MotionGrove checks this public website: it reads the pages anyone can see, never signs in, never submits a form and never changes anything on it. Changing the address starts a new check.</p>
    </x-card>

    <x-card :padded="true" class="mb-2" data-role="checks">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-1">
            <p class="text-label mb-0">Recent checks</p>
            <form method="POST" action="{{ $crawlUrl }}">@csrf<button type="submit" class="btn btn-sm btn-outline-secondary" data-role="check-again">Check again now</button></form>
        </div>
        @forelse($history as $check)
            <p class="mb-50" data-role="check-row">
                {{ $check->created_at?->format('M j, Y H:i') }} &middot;
                <strong>{{ ucfirst($check->status) }}</strong>
                @if($check->status === 'completed') &middot; {{ $check->pages_fetched }} pages, {{ $check->critical_count }} critical, {{ $check->warning_count + $check->info_count }} other issues @endif
                <span class="text-muted">({{ str_replace('_', ' ', $check->trigger) }})</span>
            </p>
        @empty
            <p class="text-muted mb-0">No checks yet.</p>
        @endforelse
        <p class="text-caption text-muted mt-1 mb-0">Your website is checked about once a week, or when you change the address.</p>
    </x-card>

    <x-card :padded="true" class="mb-2" data-role="switch">
        <p class="text-label mb-1">Change how you use Website</p>
        <p class="text-muted">Want MotionGrove to build and host your website instead? Your existing website is not touched.</p>
        <div class="d-flex flex-wrap gap-1">
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">@csrf<input type="hidden" name="mode" value="hosted"><button class="btn btn-sm btn-outline-primary" type="submit">Build a website with MotionGrove</button></form>
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.mode.choose', [$workspaceUid, $businessUid]) }}">@csrf<input type="hidden" name="mode" value="none"><button class="btn btn-sm btn-flat-secondary" type="submit">Stop checking my website</button></form>
        </div>
    </x-card>
@endsection
