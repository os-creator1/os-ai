@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunities')

@section('content')
    <section id="opportunities-index">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Opportunities</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('customer.opportunities.index') }}" class="row g-1 mb-2">
                            <div class="col-md-4">
                                <label class="form-label" for="status">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="">All statuses</option>
                                    @foreach (\App\Enums\Opportunity\OpportunityStatus::cases() as $status)
                                        <option value="{{ $status->value }}" @selected($selectedStatus === $status->value)>
                                            {{ ucwords(str_replace('_', ' ', $status->value)) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label" for="freshness">Freshness</label>
                                <select class="form-control" id="freshness" name="freshness">
                                    <option value="current" @selected($selectedFreshness === 'current')>Current</option>
                                    <option value="stale" @selected($selectedFreshness === 'stale')>Stale</option>
                                    <option value="all" @selected($selectedFreshness === 'all')>All</option>
                                </select>
                            </div>

                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-1">Apply Filters</button>
                                <a href="{{ route('customer.opportunities.index') }}" class="btn btn-outline-secondary">Clear Filters</a>
                            </div>
                        </form>

                        @if ($opportunities->isEmpty())
                            <div class="alert alert-secondary mb-0">
                                No opportunities are available right now.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Status</th>
                                        <th>Freshness</th>
                                        <th>First detected</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($opportunities as $opportunity)
                                        <tr>
                                            <td>{{ $opportunity->title }}</td>
                                            <td>
                                                <span class="badge bg-light-primary">
                                                    {{ ucwords(str_replace('_', ' ', $opportunity->status->value)) }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge {{ $opportunity->freshness->value === 'current' ? 'bg-light-success' : 'bg-light-warning' }}">
                                                    {{ ucfirst($opportunity->freshness->value) }}
                                                </span>
                                            </td>
                                            <td>{{ optional($opportunity->first_detected_at)->format('M j, Y') }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('customer.opportunities.show', $opportunity->id) }}" class="btn btn-sm btn-outline-primary">
                                                    View
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-2">
                                {{ $opportunities->appends(['status' => $selectedStatus, 'freshness' => $selectedFreshness])->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
