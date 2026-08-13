@extends('layouts/contentLayoutMaster')

@section('title', $workspace->name)

@section('content')
    <section id="admin-workspace-show">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ $workspace->name }}</h4>
                    </div>
                    <div class="card-body">
                        <dl class="row">
                            <dt class="col-sm-3">Uid</dt>
                            <dd class="col-sm-9">{{ $workspace->uid }}</dd>

                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                @if ($workspace->is_active)
                                    <span class="badge badge-light-success">Active</span>
                                @else
                                    <span class="badge badge-light-secondary">Inactive</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3">Owner</dt>
                            <dd class="col-sm-9">{{ $workspace->owner?->displayName() ?? 'Unknown' }}</dd>

                            <dt class="col-sm-3">Owner email</dt>
                            <dd class="col-sm-9">{{ $workspace->owner?->email ?? '—' }}</dd>

                            <dt class="col-sm-3">Created</dt>
                            <dd class="col-sm-9">{{ $workspace->created_at?->toDateTimeString() ?? '—' }}</dd>

                            <dt class="col-sm-3">Updated</dt>
                            <dd class="col-sm-9">{{ $workspace->updated_at?->toDateTimeString() ?? '—' }}</dd>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Businesses</h4>
                    </div>
                    <div class="card-body">
                        @if ($workspace->businesses->isEmpty())
                            <p class="mb-0">No Businesses in this Workspace.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Uid</th>
                                            <th>Business owner</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($workspace->businesses as $business)
                                            <tr>
                                                <td>{{ $business->name }}</td>
                                                <td>{{ $business->uid }}</td>
                                                <td>{{ $business->customer?->user?->displayName() ?? 'Unknown' }}</td>
                                                <td>{{ $business->status ? ucfirst($business->status->value) : '—' }}</td>
                                                <td>
                                                    @can('view business')
                                                        <a href="{{ route('admin.businesses.show', $business) }}">View</a>
                                                    @endcan
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Memberships</h4>
                    </div>
                    <div class="card-body">
                        @if ($workspace->memberships->isEmpty())
                            <p class="mb-0">This Workspace has no members.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Business access</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($workspace->memberships as $membership)
                                            <tr>
                                                <td>{{ $membership->user?->displayName() ?? 'Unknown' }}</td>
                                                <td>{{ $membership->user?->email ?? '—' }}</td>
                                                <td>{{ ucfirst($membership->role->value) }}</td>
                                                <td>
                                                    @if ($membership->business_access_scope->value === 'all')
                                                        All Businesses
                                                    @elseif ($membership->assignedBusinesses->isEmpty())
                                                        No Businesses assigned
                                                    @else
                                                        {{ $membership->assignedBusinesses->pluck('name')->implode(', ') }}
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($membership->is_active)
                                                        <span class="badge badge-light-success">Active</span>
                                                    @else
                                                        <span class="badge badge-light-secondary">Inactive</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
