@extends('layouts/contentLayoutMaster')

@section('title', 'Workspaces')

@section('content')
    <section id="workspace-index">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Workspaces</h4>
                    </div>
                    <div class="card-body">
                        @if ($workspaces->isEmpty())
                            <p class="mb-0">You don't have access to any Workspaces yet.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($workspaces as $workspace)
                                            <tr>
                                                <td>{{ $workspace['name'] }}</td>
                                                <td>{{ $workspace['role'] }}</td>
                                                <td>
                                                    @if ($workspace['is_active'])
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
