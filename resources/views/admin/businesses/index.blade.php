@extends('layouts/contentLayoutMaster')

@section('title', 'Businesses')

@section('content')
    <section id="admin-businesses-index">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Businesses</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('admin.businesses.index') }}" class="row g-2 mb-3">
                            <div class="col-md-4">
                                <input type="text" name="search" class="form-control" placeholder="Search name, email, domain"
                                       value="{{ $filters['search'] }}">
                            </div>
                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">All statuses</option>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>
                                            {{ ucfirst($status->value) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="industry" class="form-select">
                                    <option value="">All industries</option>
                                    @foreach ($industries as $industry)
                                        <option value="{{ $industry->value }}" @selected($filters['industry'] === $industry->value)>
                                            {{ ucfirst(str_replace('_', ' ', $industry->value)) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-primary">
                                <tr>
                                    <th>Name</th>
                                    <th>Owner</th>
                                    <th>Industry</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($businesses as $business)
                                    <tr>
                                        <td>{{ $business->name }}</td>
                                        <td>{{ $business->customer?->user?->displayName() ?? 'Unknown' }}</td>
                                        <td>{{ ucfirst(str_replace('_', ' ', $business->industry?->value ?? '')) }}</td>
                                        <td>{{ ucfirst($business->status->value) }}</td>
                                        <td>
                                            <a href="{{ route('admin.businesses.show', $business) }}">View</a>
                                            @can('edit business')
                                                | <a href="{{ route('admin.businesses.edit', $business) }}">Edit</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">No businesses found.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{ $businesses->appends(array_filter($filters))->links() }}
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
