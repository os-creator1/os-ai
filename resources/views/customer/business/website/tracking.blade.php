@extends('layouts/contentLayoutMaster')

@section('title', 'Tracking')

@section('page-style')
    @include('customer.business.website._manage-styles')
@endsection

@section('content')
    @include('customer.business.website._manage-header', ['active' => 'tracking'])

    @php($ids = [$workspaceUid, $businessUid])

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" data-testid="tracking-errors">
            @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
        </div>
    @endif

    <div class="mg-card p-4 mb-3" data-testid="lead-form-status">
        <h2 class="h5">Lead form</h2>
        @if ($leadForm['state'] === 'ready')
            <p class="mb-0">Your website's contact page uses your <strong>{{ $leadForm['embed']['title'] }}</strong> form. A successful submission is counted as one <em>lead</em> conversion — not a booking.</p>
        @else
            <p class="mb-0" data-testid="lead-form-setup-needed">{{ $leadForm['message'] }}</p>
        @endif
    </div>

    <div class="mg-card p-4 mb-3" data-testid="global-tracking">
        <h2 class="h5">Tracking for the whole website</h2>
        <p class="text-muted">Applies to every published page, including your blog. Previews never run it.</p>
        <form method="POST" action="{{ route('customer.workspaces.businesses.website.tracking.save', $ids) }}">
            @csrf
            <input type="hidden" name="scope" value="*">
            @include('customer.business.website._tracking-fields', ['setting' => $global, 'idSuffix' => 'global'])
            <div class="mt-3 d-flex gap-2">
                <x-button type="submit" variant="primary">Save tracking</x-button>
            </div>
        </form>
        @if ($global)
            <form method="POST" action="{{ route('customer.workspaces.businesses.website.tracking.destroy', $ids) }}" class="mt-2">
                @csrf @method('DELETE')
                <input type="hidden" name="scope" value="*">
                <x-button type="submit" variant="ghost" size="sm">Remove all site-wide tracking</x-button>
            </form>
        @endif
    </div>

    <div class="mg-card p-4" data-testid="page-tracking">
        <h2 class="h5">Page-specific tracking</h2>
        <p class="text-muted">Optional. Adds to the site-wide settings for one page; an ID that is already set site-wide is loaded only once.</p>

        @foreach ($pageSettings as $scopeKey => $row)
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 border-top">
                <div><strong>{{ $scopes[$scopeKey] ?? $scopeKey }}</strong> @unless ($row->is_enabled)<span class="badge bg-secondary">Disabled</span>@endunless</div>
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.tracking.destroy', $ids) }}">
                    @csrf @method('DELETE')
                    <input type="hidden" name="scope" value="{{ $scopeKey }}">
                    <x-button type="submit" variant="ghost" size="sm">Remove</x-button>
                </form>
            </div>
            <details class="mb-3">
                <summary class="small fw-semibold">Edit settings for {{ $scopes[$scopeKey] ?? $scopeKey }}</summary>
                <form method="POST" action="{{ route('customer.workspaces.businesses.website.tracking.save', $ids) }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="scope" value="{{ $scopeKey }}">
                    @include('customer.business.website._tracking-fields', ['setting' => $row, 'idSuffix' => 'existing-' . $loop->iteration])
                    <div class="mt-3"><x-button type="submit" variant="secondary">Save changes</x-button></div>
                </form>
            </details>
        @endforeach

        <form method="POST" action="{{ route('customer.workspaces.businesses.website.tracking.save', $ids) }}" class="mt-3">
            @csrf
            <label class="form-label" for="scope_page">Page</label>
            <select id="scope_page" name="scope" class="form-select mb-3">
                @foreach ($scopes as $scopeKey => $label)
                    <option value="{{ $scopeKey }}" @selected(old('scope') === $scopeKey)>{{ $label }}</option>
                @endforeach
            </select>
            @include('customer.business.website._tracking-fields', ['setting' => null, 'idSuffix' => 'page'])
            <div class="mt-3"><x-button type="submit" variant="secondary">Save page tracking</x-button></div>
        </form>
    </div>
@endsection
