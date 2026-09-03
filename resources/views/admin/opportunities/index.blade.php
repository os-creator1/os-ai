@extends('layouts/contentLayoutMaster')

@section('title', 'Opportunities')

@section('content')
    <section id="admin-opportunities-index">
        <div class="row">
            <div class="col-12">
                <x-card title="Opportunities">
                        @php
                            $statusOptions = ['' => 'All statuses'];
                            foreach ($statuses as $status) {
                                $statusOptions[$status->value] = ucfirst(str_replace('_', ' ', $status->value));
                            }
                            $freshnessOptions = ['' => 'All freshness'];
                            foreach ($freshnesses as $freshness) {
                                $freshnessOptions[$freshness->value] = ucfirst($freshness->value);
                            }
                            $workerKeyOptions = ['' => 'All workers'];
                            foreach ($workerKeys as $workerKey) {
                                $workerKeyOptions[$workerKey->value] = ucfirst(str_replace('_', ' ', $workerKey->value));
                            }
                        @endphp
                        <form method="GET" action="{{ route('admin.opportunities.index') }}" class="row g-2 mb-3">
                            <div class="col-md-3">
                                <x-select name="status" :options="$statusOptions" :selected="$filters['status']" />
                            </div>
                            <div class="col-md-3">
                                <x-select name="freshness" :options="$freshnessOptions" :selected="$filters['freshness']" />
                            </div>
                            <div class="col-md-3">
                                <x-select name="worker_key" :options="$workerKeyOptions" :selected="$filters['worker_key']" />
                            </div>
                            <div class="col-md-2">
                                <x-input type="number" name="business_id" min="1" placeholder="Business ID"
                                       value="{{ $filters['business_id'] }}" />
                            </div>
                            <div class="col-md-1">
                                <x-button type="submit" class="w-100">Filter</x-button>
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
                </x-card>
            </div>
        </div>
    </section>
@endsection
