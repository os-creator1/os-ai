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
                        @if (session('flash_success'))
                            <div class="alert alert-success">{{ session('flash_success') }}</div>
                        @endif

                        @if (session('flash_error'))
                            <div class="alert alert-danger">{{ session('flash_error') }}</div>
                        @endif

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('customer.workspaces.store') }}" class="mb-2">
                            @csrf

                            <div class="mb-1">
                                <label class="form-label" for="workspace-name">New Workspace name</label>
                                <input type="text" class="form-control" id="workspace-name" name="name" value="{{ old('name') }}" required>
                            </div>

                            <button type="submit" class="btn btn-primary">Create Workspace</button>
                        </form>

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
                                                <td><a href="{{ route('customer.workspaces.show', $workspace['uid']) }}">{{ $workspace['name'] }}</a></td>
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
