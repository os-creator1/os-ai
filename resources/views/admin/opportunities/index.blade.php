@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunities')

@section('content')
    <section id="admin-opportunities-index">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Opportunities</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('admin.opportunities.index') }}" class="row g-2 mb-3">
                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">All statuses</option>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>
                                            {{ ucfirst(str_replace('_', ' ', $status->value)) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="freshness" class="form-select">
                                    <option value="">All freshness</option>
                                    @foreach ($freshnesses as $freshness)
                                        <option value="{{ $freshness->value }}" @selected($filters['freshness'] === $freshness->value)>
                                            {{ ucfirst($freshness->value) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="worker_key" class="form-select">
                                    <option value="">All workers</option>
                                    @foreach ($workerKeys as $workerKey)
                                        <option value="{{ $workerKey->value }}" @selected($filters['worker_key'] === $workerKey->value)>
                                            {{ ucfirst(str_replace('_', ' ', $workerKey->value)) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="number" name="business_id" min="1" class="form-control" placeholder="Business ID"
                                       value="{{ $filters['business_id'] }}">
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-primary">
                                <tr>
                                    <th>ID</th>
                                    <th>Business ID</th>
                                    <th>Title</th>
                                    <th>Worker</th>
                                    <th>Status</th>
                                    <th>Freshness</th>
                                    <th>Priority</th>
                                    <th>First detected</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($opportunities as $opportunity)
                                    <tr>
                                        <td>{{ $opportunity->id }}</td>
                                        <td>{{ $opportunity->business_id }}</td>
                                        <td>{{ $opportunity->title }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $opportunity->worker_key->value)) }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $opportunity->status->value)) }}</td>
                                        <td>{{ ucfirst($opportunity->freshness->value) }}</td>
                                        <td>{{ $opportunity->priority_score }}</td>
                                        <td>{{ optional($opportunity->first_detected_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                        <td><a href="{{ route('admin.opportunities.show', $opportunity->id) }}">View</a></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9">No opportunities found.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{ $opportunities->appends(array_filter($filters))->links() }}
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
