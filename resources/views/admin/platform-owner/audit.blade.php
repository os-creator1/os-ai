@extends('layouts/contentLayoutMaster')

@section('title', 'Platform Owner audit')

@section('content')
    <section id="admin-platform-owner-audit">
        <div class="row">
            <div class="col-12">
                <p class="text-muted">
                    Actions taken by platform administrators on Workspace plans, account lifecycle and Business status, newest first.
                    System and customer-driven changes are not listed.
                </p>
                @include('admin.platform-owner.partials.audit', ['rows' => $rows->items(), 'actors' => $actors, 'title' => 'Platform Owner actions', 'showWorkspace' => true])

                <div class="d-flex gap-2">
                    @if ($rows->previousPageUrl())
                        <a class="btn btn-outline-secondary btn-sm" href="{{ $rows->previousPageUrl() }}">Newer</a>
                    @endif
                    @if ($rows->hasMorePages())
                        <a class="btn btn-outline-secondary btn-sm" href="{{ $rows->nextPageUrl() }}">Older</a>
                    @endif
                </div>

                <div class="card mt-2" id="platform-actions-audit">
                    <div class="card-header">
                        <h4 class="card-title">Plans, people &amp; announcements</h4>
                    </div>
                    <div class="card-body pb-0">
                        <a class="btn btn-sm {{ request('type') ? 'btn-outline-secondary' : 'btn-primary' }}" href="{{ route('admin.platform-owner.audit') }}">All</a>
                        @foreach (['plan' => 'Plans', 'user' => 'Users', 'administrator' => 'Administrators', 'role' => 'Roles', 'announcement' => 'Announcements'] as $type => $label)
                            <a class="btn btn-sm {{ request('type') === $type ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('admin.platform-owner.audit', ['type' => $type]) }}">{{ $label }}</a>
                        @endforeach
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>When</th><th>By</th><th>What</th><th>Reason</th></tr></thead>
                            <tbody>
                            @forelse ($platformActions as $row)
                                <tr>
                                    <td>{{ $row->created_at->toDayDateTimeString() }}</td>
                                    <td>{{ $row->actor?->email }}</td>
                                    <td>{{ $row->summary }}</td>
                                    <td>{{ $row->reason }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-2">Nothing recorded yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
