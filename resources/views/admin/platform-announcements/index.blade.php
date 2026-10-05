@extends('layouts/contentLayoutMaster')

@section('title', 'Announcements')

@section('content')
    <section id="admin-platform-announcements-index">
        @include('admin.partials.flash')

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-start">
                <div>
                    <h4 class="card-title mb-25">Announcements</h4>
                    <p class="mb-0 text-muted">Write a message once, choose who sees it, and publish now or schedule it. Delivery is handled by Platform Automations.</p>
                </div>
                @can('create announcement')
                    <a class="btn btn-primary" href="{{ route('admin.platform-announcements.create') }}">New announcement</a>
                @endcan
            </div>
            <div class="card-body pb-0">
                <ul class="nav nav-pills">
                    <li class="nav-item"><a class="nav-link {{ $filter === '' ? 'active' : '' }}" href="{{ route('admin.platform-announcements.index') }}">All</a></li>
                    @foreach ($statuses as $s)
                        <li class="nav-item"><a class="nav-link {{ $filter === $s->value ? 'active' : '' }}" href="{{ route('admin.platform-announcements.index', ['status' => $s->value]) }}">{{ $s->label() }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Title</th><th>Status</th><th>Audience</th><th>When</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    @forelse ($rows as $a)
                        @php($st = $a->effectiveStatus())
                        <tr>
                            <td><strong>{{ $a->title }}</strong><div class="small text-muted">{{ \Illuminate\Support\Str::limit($a->body, 80) }}</div></td>
                            <td>
                                <span class="badge badge-light-{{ ['draft' => 'secondary', 'scheduled' => 'info', 'published' => 'success', 'expired' => 'warning', 'cancelled' => 'danger'][$st->value] }}">{{ $st->label() }}</span>
                            </td>
                            <td>{{ $a->audienceMode() === 'tiers' ? implode(', ', array_map('ucfirst', $a->audienceTiers())) . ' plans' : 'Everyone' }}</td>
                            <td class="small">
                                @if ($a->published_at) Published {{ $a->published_at->toDayDateTimeString() }}
                                @elseif ($a->scheduled_at) Scheduled for {{ $a->scheduled_at->toDayDateTimeString() }}
                                @else — @endif
                                @if ($a->expires_at)<div class="text-muted">Expires {{ $a->expires_at->toDayDateTimeString() }}</div>@endif
                            </td>
                            <td class="text-end">
                                @if (in_array($a->status->value, ['draft', 'scheduled'], true))
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.platform-announcements.edit', $a->uid) }}">Edit</a>
                                    <form class="d-inline" method="POST" action="{{ route('admin.platform-announcements.transition', [$a->uid, 'publish']) }}">@csrf
                                        <button class="btn btn-sm btn-success" type="submit">Publish now</button></form>
                                @endif
                                @if (in_array($a->status->value, ['draft', 'scheduled', 'published'], true))
                                    <form class="d-inline" method="POST" action="{{ route('admin.platform-announcements.transition', [$a->uid, 'cancel']) }}">@csrf
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No announcements{{ $filter !== '' ? ' with this status' : ' yet' }}.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection
