@extends('layouts/contentLayoutMaster')

@section('title', 'Workspaces')

@section('content')
    <section id="admin-workspaces-index">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Workspaces</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="{{ route('admin.workspaces.index') }}" class="row g-2 mb-3">
                            <div class="col-md-6">
                                <input type="text" name="search" class="form-control" placeholder="Search name or uid"
                                       value="{{ $filters['search'] }}">
                            </div>
                            <div class="col-md-4">
                                <select name="is_active" class="form-select">
                                    <option value="" @selected($filters['is_active'] === null)>All</option>
                                    <option value="1" @selected($filters['is_active'] === true)>Active</option>
                                    <option value="0" @selected($filters['is_active'] === false)>Inactive</option>
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
                                    <th>Status</th>
                                    <th>Businesses</th>
                                    <th>Active members</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse ($workspaces as $workspace)
                                    <tr>
                                        <td>{{ $workspace->name }}</td>
                                        <td>{{ $workspace->owner?->displayName() ?? 'Unknown' }}</td>
                                        <td>
                                            @if ($workspace->is_active)
                                                <span class="badge badge-light-success">Active</span>
                                            @else
                                                <span class="badge badge-light-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td>{{ $workspace->businesses_count }}</td>
                                        <td>{{ $workspace->active_memberships_count }}</td>
                                        <td>
                                            <a href="{{ route('admin.workspaces.show', $workspace) }}">View</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6">No workspaces found.</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{ $workspaces->appends(array_filter($filters, fn ($value) => $value !== null))->links() }}
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
