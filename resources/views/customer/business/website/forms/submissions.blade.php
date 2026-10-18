@extends('layouts/contentLayoutMaster')

@section('title', 'Inquiries')

@section('content')
    <div class="row mb-2">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h4 class="mb-0">Inquiries — {{ $form->name }}</h4>
            <x-button variant="secondary" href="{{ route('customer.workspaces.businesses.website.forms.index', [$workspaceUid, $businessUid]) }}">Back to Forms</x-button>
        </div>
    </div>

    <x-flash-alert class="mb-3" />

    @if ($locations->count() > 1)
        <form method="GET" class="mb-3 d-flex align-items-center">
            <label for="location" class="me-2 text-label">Location</label>
            <select name="location" id="location" class="form-select w-auto me-2" onchange="this.form.submit()">
                <option value="">All my locations</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->uid }}" @selected($locationFilter === $location->uid)>{{ $location->name }}</option>
                @endforeach
            </select>
        </form>
    @endif

    <x-card :padded="false">
        <div class="list-group list-group-flush">
            @forelse ($submissions as $submission)
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong>{{ $submission->data['name'] ?? 'Unknown visitor' }}</strong>
                            @if ($submission->is_spam)
                                <x-badge variant="danger">Flagged as spam</x-badge>
                            @endif
                            <span class="text-caption d-block">{{ $submission->created_at->format('M j, Y g:ia') }} @if ($submission->page_slug) &middot; from /{{ $submission->page_slug }} @endif @if ($submission->location) &middot; {{ $submission->location->name }} @endif</span>
                        </div>
                        <span>
                            @if ($submission->contact_resolution === 'ambiguous')
                                <x-badge variant="warning">Several contacts share this phone — not linked</x-badge>
                            @endif
                            @if ($submission->crm_opportunity_id)
                                <x-button variant="secondary" size="sm" href="{{ route('customer.workspaces.businesses.crm.opportunities.show', [$workspaceUid, $businessUid, $submission->crmOpportunity->uid]) }}">View in CRM</x-button>
                            @endif
                        </span>
                    </div>
                    <dl class="row mb-0 mt-2">
                        @foreach ($submission->data as $key => $value)
                            @if ($key !== 'name' && ! empty($value))
                                <dt class="col-sm-2 text-caption">{{ ucwords(str_replace('_', ' ', $key)) }}</dt>
                                <dd class="col-sm-10">{{ $value }}</dd>
                            @endif
                        @endforeach
                    </dl>
                </div>
            @empty
                <div class="p-4">
                    <x-empty-state icon="inbox" title="No inquiries yet" description="Submissions from your published Quote Request form will appear here." />
                </div>
            @endforelse
        </div>
    </x-card>

    <div class="mt-3">{{ $submissions->links() }}</div>
@endsection
