@extends('layouts/contentLayoutMaster')

@section('title', 'Announcements')

@php
    $badge = fn (string $s) => match ($s) {
        'published' => 'badge-light-success',
        'scheduled' => 'badge-light-primary',
        'cancelled' => 'badge-light-danger',
        default => 'badge-light-secondary',
    };
@endphp

@section('content')
    <section id="admin-platform-announcements">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <h3 class="mb-25">Announcements</h3>
                <p class="text-muted mb-0">Tell customers something — as a banner, an in-app notice, or an email — to exactly the audience you choose.</p>
            </div>
            <div class="d-flex gap-1">
                <a href="{{ route('admin.platform-automations.index') }}" class="btn btn-outline-secondary">Platform Automations</a>
                <a href="{{ route('admin.platform-announcements.create') }}" class="btn btn-primary" data-role="pann-new">New announcement</a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                @if ($announcements->isEmpty())
                    <div class="text-center py-3" data-role="pann-empty">No announcements yet.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover" data-role="pann-list">
                            <thead class="table-primary"><tr><th>Title</th><th>Audience</th><th>Channels</th><th>Status</th><th>When</th><th>Delivered</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($announcements as $a)
                                <tr data-announcement="{{ $a->uid }}">
                                    <td class="fw-bolder">{{ $a->title }}<div class="small text-muted">{{ \Illuminate\Support\Str::limit($a->body, 90) }}</div></td>
                                    <td>{{ \App\Library\PlatformAutomation\Announcements\PlatformAnnouncementAudience::KINDS[$a->audience['kind'] ?? 'everyone'] ?? '—' }}
                                        @if (($a->audience['kind'] ?? '') === 'tier')· {{ ucfirst($a->audience['tier'] ?? '') }}@endif</td>
                                    <td>{{ implode(', ', $a->channels) }}</td>
                                    <td><span class="badge {{ $badge($a->status) }}" data-role="pann-status">{{ ucfirst($a->status) }}</span></td>
                                    <td class="small">
                                        @if ($a->status === 'published' || $a->status === 'expired') published {{ $a->published_at?->format('M j, g:i A') }}
                                        @elseif ($a->publish_at) {{ $a->publish_at->format('M j, g:i A') }}
                                        @else — @endif
                                        @if ($a->expires_at)<div class="text-muted">expires {{ $a->expires_at->format('M j, g:i A') }}</div>@endif
                                    </td>
                                    <td>{{ $a->recipients_done }} / {{ $a->recipients_total }}</td>
                                    <td class="text-end text-nowrap">
                                        @if (in_array($a->status, ['draft', 'scheduled']))
                                            <a href="{{ route('admin.platform-announcements.edit', $a) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                        @endif
                                        @if (in_array($a->status, ['draft', 'scheduled', 'published']))
                                            <form method="POST" action="{{ route('admin.platform-announcements.cancel', $a) }}" class="d-inline" onsubmit="return confirm('Cancel this announcement?');">@csrf
                                                <button class="btn btn-sm btn-outline-danger" data-role="pann-cancel">Cancel</button></form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $announcements->links() }}
                @endif
            </div>
        </div>
    </section>
@endsection
