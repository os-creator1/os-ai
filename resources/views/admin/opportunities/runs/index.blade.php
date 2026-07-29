@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunity runs — ' . $business->name)

@section('content')
    <section id="admin-opportunity-runs-index">
        <div class="row">
            <div class="col-12 mb-1">
                <a href="{{ route('admin.opportunities.index') }}" class="btn btn-outline-secondary btn-sm">
                    &larr; Back to opportunities
                </a>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Opportunity runs</h4>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>Business ID</strong>
                                <div>{{ $business->id }}</div>
                            </div>
                            <div class="col-md-4">
                                <strong>Business name</strong>
                                <div>{{ $business->name }}</div>
                            </div>
                            <div class="col-md-4">
                                <strong>Owning customer</strong>
                                <div>{{ $business->customer?->user?->displayName() ?? 'Unknown' }}</div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-primary">
                                <tr>
                                    <th>ID</th>
                                    <th>Worker</th>
                                    <th>Status</th>
                                    <th>Started</th>
                                    <th>Heartbeat</th>
                                    <th>Finished</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($runs as $run)
                                    <tr>
                                        <td>{{ $run->id }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $run->worker_key->value)) }}</td>
                                        <td>{{ ucfirst($run->status->value) }}</td>
                                        <td>{{ optional($run->started_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                        <td>{{ optional($run->heartbeat_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                        <td>{{ optional($run->completed_at)->format('M j, Y g:ia') ?? '—' }}</td>
                                        <td><a href="{{ route('admin.opportunities.runs.show', $run->id) }}">View</a></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7">No runs found for this business.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{ $runs->appends(['business_id' => $business->id, 'per_page' => $perPage])->links() }}
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
