@extends('layouts/contentLayoutMaster')

@section('title', 'Workspace overview')

@section('content')
    <section id="workspace-overview">
        <div class="row">
            <div class="col-12">
                <a href="{{ route('customer.workspaces.index') }}">Back to Workspaces</a>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">{{ $workspace['name'] }}</h4>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Status</dt>
                            <dd class="col-sm-9">
                                @if ($workspace['is_active'])
                                    <span class="badge badge-light-success">Active</span>
                                @else
                                    <span class="badge badge-light-secondary">Inactive</span>
                                @endif
                            </dd>

                            <dt class="col-sm-3">Your role</dt>
                            <dd class="col-sm-9">{{ $workspace['role'] }}</dd>
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
                        @if (empty($businesses))
                            <p class="mb-0">No Businesses are accessible in this Workspace.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($businesses as $business)
                                            <tr>
                                                <td>{{ $business['name'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            @isset($directory)
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4 class="card-title">Members</h4>
                        </div>
                        <div class="card-body">
                            @if (empty($directory))
                                <p class="mb-0">This Workspace has no active members.</p>
                            @else
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Role</th>
                                                <th>Business access</th>
                                                <th>Assigned Businesses</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($directory as $member)
                                                <tr>
                                                    <td>{{ $member['name'] }}</td>
                                                    <td>{{ $member['role'] }}</td>
                                                    <td>{{ $member['scope'] }}</td>
                                                    <td>{{ $member['assigned_business_count'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endisset
        </div>
    </section>
@endsection
